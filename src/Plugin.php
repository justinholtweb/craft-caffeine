<?php

declare(strict_types=1);

namespace JustinHoltWeb\Caffeine;

use CraftCms\Cms\Cp\Data\NavItem;
use CraftCms\Cms\Cp\Icons;
use CraftCms\Cms\Cp\IconSets;
use CraftCms\Cms\GarbageCollection\Events\RunningGarbageCollection;
use CraftCms\Cms\Gql\Events\GqlSchemaComponentsResolving;
use CraftCms\Cms\Plugin\Plugin as BasePlugin;
use CraftCms\Cms\Route\Routes;
use CraftCms\Cms\Support\Facades\HtmlStack;
use CraftCms\Cms\Support\Facades\ProjectConfig;
use CraftCms\Cms\Support\Html;
use CraftCms\Cms\Twig\Events\TwigCreated;
use CraftCms\Cms\Ui\Controls\Choice;
use CraftCms\Cms\Ui\Controls\IconPicker;
use CraftCms\Cms\Ui\Controls\Lightswitch;
use CraftCms\Cms\Ui\Controls\Number;
use CraftCms\Cms\Ui\Controls\Text;
use CraftCms\Cms\Ui\Controls\Textarea;
use CraftCms\Cms\Ui\Nodes\Field;
use CraftCms\Cms\Ui\Nodes\Heading;
use CraftCms\Cms\Ui\Ui;
use CraftCms\Cms\Ui\UiContext;
use CraftCms\Cms\User\Data\Permission;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Event;
use JustinHoltWeb\Caffeine\Gql\SearchQuery;
use JustinHoltWeb\Caffeine\Models\Edition;
use JustinHoltWeb\Caffeine\Models\Settings;
use JustinHoltWeb\Caffeine\Services\ArtifactCache;
use JustinHoltWeb\Caffeine\Services\Artifacts;
use JustinHoltWeb\Caffeine\Services\AutoUpdate;
use JustinHoltWeb\Caffeine\Services\Builder;
use JustinHoltWeb\Caffeine\Services\Extractors;
use JustinHoltWeb\Caffeine\Services\Indexes;
use JustinHoltWeb\Caffeine\Services\Mapper;
use JustinHoltWeb\Caffeine\Services\Publisher;
use JustinHoltWeb\Caffeine\Services\Records;
use JustinHoltWeb\Caffeine\Services\Search;
use JustinHoltWeb\Caffeine\Services\Sources;
use JustinHoltWeb\Caffeine\Services\Tokenizer;
use JustinHoltWeb\Caffeine\Twig\Extension;
use Override;
use Psr\Log\LogLevel;
use Throwable;

use function CraftCms\Cms\currentUser;
use function CraftCms\Cms\t;

/**
 * Caffeine — instant faceted search for Craft.
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const string EDITION_LITE = 'lite';

    public const string EDITION_PRO = 'pro';

    public const string PERMISSION_MANAGE_INDEXES = 'caffeine:manageIndexes';

    public const string PERMISSION_BUILD = 'caffeine:build';

    /** The Laravel log channel everything in the plugin writes to (`storage/logs/caffeine-*.log`). */
    public const string LOG_CHANNEL = 'caffeine';

    public string $schemaVersion = '1.1.0';

    /**
     * Sites upgrade from the last Caffeine 5 release, so the Craft 5 migrations are all in place
     * and `database/migrations/Install.php` can describe the final schema.
     */
    public string $minVersionRequired = '5.3.0';

    public bool $hasCpSection = true;

    public bool $hasCpSettings = true;

    /** The index tools screen's preview and playground. Loaded on every CP page; idle elsewhere. */
    protected array $scripts = [
        __DIR__.'/../resources/js/cp/tools.js' => 'cp/tools.js',
    ];

    /** The front-end runtime: ES modules, no build step, imported relative to each other. */
    protected array $publishables = [
        __DIR__.'/../resources/js/runtime' => 'runtime',
    ];

    /**
     * Widget templates. A project's own `templates/_caffeine/<name>.twig` is found first, so
     * overriding a widget is a matter of creating that file.
     */
    protected array $siteTemplateRoots = [
        '_caffeine' => __DIR__.'/../resources/site',
    ];

    protected array $gqlQueries = [
        SearchQuery::class,
    ];

    protected array $commands = [
        Console\Commands\IndexStatusCommand::class,
        Console\Commands\IndexBuildCommand::class,
        Console\Commands\IndexBuildWorkerCommand::class,
        Console\Commands\IndexPreviewCommand::class,
        Console\Commands\IndexTouchCommand::class,
        Console\Commands\IndexPruneOrphansCommand::class,
        Console\Commands\ArtifactPublishCommand::class,
        Console\Commands\ArtifactStatusCommand::class,
        Console\Commands\ArtifactVersionsCommand::class,
        Console\Commands\ArtifactVerifyCommand::class,
        Console\Commands\ArtifactPruneCommand::class,
    ];

    private bool $runtimeRegistered = false;

    #[Override]
    public static function editions(): array
    {
        return [
            self::EDITION_LITE,
            self::EDITION_PRO,
        ];
    }

    #[Override]
    protected static function createSettings(): Settings
    {
        return new Settings;
    }

    public function register(): void
    {
        parent::register();

        // Every service memoises something for the request — loaded definitions, decoded
        // artifacts, the contexts a page rendered — so each must be one instance.
        foreach ([
            Indexes::class, Sources::class, Mapper::class, Records::class, Builder::class,
            Extractors::class, Artifacts::class, ArtifactCache::class, AutoUpdate::class,
            Publisher::class, Search::class, Tokenizer::class,
        ] as $service) {
            $this->app->singleton($service);
        }
    }

    public function boot(): void
    {
        $this->app->bind(Settings::class, fn () => $this->getSettings());

        $this->registerLogChannel();
        $this->registerProjectConfigHandlers();
        $this->registerTwig();
        $this->registerGql();

        // Registered for every request type, console included: a `craft:resave:entries` is
        // exactly the case the bulk-op coalescing exists for.
        $this->app->make(AutoUpdate::class)->register();

        // Clears derived data belonging to indexes that no longer exist — an index whose uid
        // changed (a restored config, a re-run seeding script) orphans its records otherwise.
        Event::listen(RunningGarbageCollection::class, fn () => $this->app->make(Indexes::class)->collectGarbage());

        // An InstantSearch client posts JSON from the browser, possibly from another origin, and
        // the endpoint only reads a published artifact.
        $actions = $this->app->make(Routes::class)->actionTriggerUriPrefix();
        PreventRequestForgery::except([
            $actions.'/caffeine/search',
            $actions.'/caffeine/search/*',
            'caffeine/search/*',
        ]);
    }

    /**
     * Whether the Pro feature set is available.
     *
     * Every edition check goes through here rather than calling `is()` directly, so the
     * Lite/Pro boundary is auditable in one place (with `Models\Edition`).
     */
    public function isPro(): bool
    {
        return $this->is(self::EDITION_PRO, '>=');
    }

    /**
     * Adds the front-end runtime to the page, once. Called by `{% caffeine %}`, so a site that
     * never uses the tag never ships it.
     */
    public function registerRuntime(): void
    {
        if ($this->runtimeRegistered) {
            return;
        }

        $this->runtimeRegistered = true;

        HtmlStack::jsFile($this->asset('runtime/caffeine.js').'?v='.rawurlencode($this->version), ['type' => 'module']);
    }

    #[Override]
    protected function getPermissions(): array
    {
        return [
            new Permission(
                key: self::PERMISSION_MANAGE_INDEXES,
                label: t('Manage indexes', category: 'caffeine'),
            ),
            new Permission(
                key: self::PERMISSION_BUILD,
                label: t('Rebuild and republish indexes', category: 'caffeine'),
            ),
        ];
    }

    #[Override]
    public function getCpNavItem(): NavItem|array|null
    {
        $item = parent::getCpNavItem();

        if (! $item instanceof NavItem) {
            return $item;
        }

        $this->customiseNavItem($item, $this->getSettings());

        $item->subnav([
            new NavItem()->label(t('Indexes', category: 'caffeine'))->href('caffeine/indexes'),
        ]);

        if (currentUser()?->isAdmin()) {
            $item->add(new NavItem()->label(t('Settings', category: 'caffeine'))->href('settings/plugins/caffeine'));
        }

        return $item;
    }

    /**
     * Applies the nav label and icon from settings.
     *
     * Craft's own Settings → Control Panel Navigation can rename and re-icon any item too, and
     * an override made there wins over this, because Craft applies it afterwards.
     */
    public function customiseNavItem(NavItem $item, Settings $settings): NavItem
    {
        $label = trim((string) $settings->navLabel);

        if ($label !== '') {
            $item->label($label);
        }

        $svg = trim((string) $settings->navIconSvg);
        $icon = trim((string) $settings->navIcon);

        try {
            if ($svg !== '') {
                // Pasted markup is drawn into every CP page, so it is always sanitised.
                $sanitised = Html::svg($svg, sanitize: true, namespace: true);

                if ($sanitised !== '') {
                    return $item->iconSvg($sanitised)->icon(null);
                }
            }

            if ($icon !== '') {
                // An icon from a registered custom set (`set:name`) has to be drawn server-side;
                // a plain Font Awesome name the CP draws itself.
                return IconSets::parse($icon) !== null
                    ? $item->iconSvg(Icons::svg($icon))->icon(null)
                    : $item->icon($icon)->iconSvg(null);
            }
        } catch (Throwable) {
            // A bad icon setting must never take the nav down; fall back to the mug.
        }

        return $item;
    }

    #[Override]
    public function settingsUi(UiContext $context = new UiContext): Ui
    {
        return Ui::make([
            Heading::make('caffeine-publishing', t('Publishing', category: 'caffeine')),
            Field::make(t('Filesystem disk', category: 'caffeine'), Text::make('filesystemHandle')->placeholder(t('Web root', category: 'caffeine')))
                ->instructions(t('The name of a disk in `config/filesystems.php`. Leave empty to publish into the web root, which is right for most sites; naming a disk puts artifacts on S3 or a CDN so PHP never serves them.', category: 'caffeine')),
            Field::make(t('Publish path', category: 'caffeine'), Text::make('publishPath'))
                ->instructions(t('Directory, within that disk or the web root, that artifacts are written under.', category: 'caffeine')),
            Field::make(t('Versions to keep', category: 'caffeine'), Number::make('keepVersions')->min(1)->max(100))
                ->instructions(t('Superseded artifacts are kept, because a visitor who loaded the page a moment before a rebuild is still fetching the previous one. Pruning it out from under them turns a rebuild into a 404.', category: 'caffeine')),
            Field::make(t('Write precompressed copies', category: 'caffeine'), Lightswitch::make('precompress'))
                ->instructions(t('Write .gz and .br alongside each artifact so a web server can serve them without recompressing on every request.', category: 'caffeine')),
            Field::make(t('Keep artifacts in OPcache', category: 'caffeine'), Lightswitch::make('cacheArtifacts'))
                ->instructions(t('Decode each published artifact once and keep it in OPcache, rather than decoding it on every request. Worth it for a large index on the htmx transport, and only if OPcache has room for the whole artifact — a 37,000-product catalogue needed about 180 MB. Caffeine checks, and logs a warning and decodes as before when it does not fit.', category: 'caffeine')),

            Heading::make('caffeine-updates', t('Updates', category: 'caffeine')),
            Field::make(t('Update automatically', category: 'caffeine'), Lightswitch::make('autoUpdate'))
                ->instructions(t('Rebuild and republish when content changes. Off means indexes only update when you say so.', category: 'caffeine')),
            Field::make(t('Use the queue', category: 'caffeine'), Lightswitch::make('useQueue'))
                ->instructions(t('Build in the background rather than during the request that saved the element. Turning this off is only sensible in tests — a synchronous build blocks the editor’s save.', category: 'caffeine')),

            Heading::make('caffeine-nav', t('Control panel', category: 'caffeine'))
                ->description(t('How Caffeine appears in the control panel’s sidebar. An override set in Settings → Control Panel Navigation takes precedence over these.', category: 'caffeine')),
            Field::make(t('Sidebar label', category: 'caffeine'), Text::make('navLabel')->placeholder($this->name))
                ->instructions(t('Leave empty for “{name}”.', ['name' => $this->name], category: 'caffeine')),
            Field::make(t('Sidebar icon', category: 'caffeine'), IconPicker::make('navIcon')->sets('*'))
                ->instructions(t('Leave empty for Caffeine’s mug.', category: 'caffeine')),
            Field::make(t('Custom SVG icon', category: 'caffeine'), Textarea::make('navIconSvg')->rows(4)->monospace())
                ->instructions(t('Optional. Paste an `<svg>` element to use instead of the icon above. It’s sanitised before it’s drawn; a single-colour icon that uses `currentColor` matches the rest of the sidebar.', category: 'caffeine')),

            Heading::make('caffeine-logging', t('Logging', category: 'caffeine')),
            Field::make(t('Log level', category: 'caffeine'), Choice::make('logLevel')->options(array_map(
                fn (string $level) => ['label' => ucfirst($level), 'value' => $level],
                [LogLevel::DEBUG, LogLevel::INFO, LogLevel::NOTICE, LogLevel::WARNING, LogLevel::ERROR, LogLevel::CRITICAL, LogLevel::ALERT, LogLevel::EMERGENCY],
            )))->instructions(t('Written to `storage/logs/caffeine-*.log`.', category: 'caffeine')),
        ]);
    }

    /**
     * Removes Caffeine's own project-config key on uninstall.
     *
     * Index definitions live under a top-level `caffeine` key that Craft knows nothing about —
     * it only clears `plugins.caffeine`. Without this the key outlives the plugin, and
     * reinstalling silently resurrects the old indexes.
     */
    #[Override]
    protected function afterUninstall(): void
    {
        ProjectConfig::remove(Indexes::CONFIG_ROOT, 'Remove Caffeine’s index definitions');
    }

    private function registerLogChannel(): void
    {
        $level = $this->getSettings()->logLevel ?: LogLevel::WARNING;

        config(['logging.channels.'.self::LOG_CHANNEL => [
            'driver' => 'daily',
            'path' => storage_path('logs/caffeine.log'),
            'level' => $level,
            'days' => 10,
            'replace_placeholders' => true,
        ]]);
    }

    private function registerProjectConfigHandlers(): void
    {
        $indexes = $this->app->make(Indexes::class);

        ProjectConfig::onAdd(Indexes::CONFIG_PATH.'.{uid}', $indexes->handleChangedIndex(...))
            ->onUpdate(Indexes::CONFIG_PATH.'.{uid}', $indexes->handleChangedIndex(...))
            ->onRemove(Indexes::CONFIG_PATH.'.{uid}', $indexes->handleDeletedIndex(...));
    }

    /**
     * Site templates get the tags. CP templates do too — the tools screen and anything rendering
     * a template from the console need them — and an extension costs nothing until it's used.
     */
    private function registerTwig(): void
    {
        Event::listen(TwigCreated::class, function (TwigCreated $event): void {
            if (! $event->twig->hasExtension(Extension::class)) {
                $event->twig->addExtension(new Extension);
            }
        });
    }

    /**
     * One schema scope per index, so a GraphQL token can be limited to the indexes it may search.
     */
    private function registerGql(): void
    {
        Event::listen(GqlSchemaComponentsResolving::class, function (GqlSchemaComponentsResolving $event): void {
            if (! Edition::allowsGraphql($this->isPro())) {
                return;
            }

            $components = [];

            foreach ($this->app->make(Indexes::class)->all() as $index) {
                $components[SearchQuery::COMPONENT.'.'.$index->uid.':read'] = [
                    'label' => t('Search “{name}”', ['name' => $index->name], category: 'caffeine'),
                ];
            }

            if ($components !== []) {
                $event->queries[t('Caffeine indexes', category: 'caffeine')] = $components;
            }
        });
    }
}
