<?php

namespace justinholtweb\caffeine;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\UrlHelper;
use craft\log\MonologTarget;
use craft\services\Gc;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use justinholtweb\caffeine\models\Settings;
use justinholtweb\caffeine\services\Artifacts;
use justinholtweb\caffeine\services\AutoUpdate;
use justinholtweb\caffeine\services\Builder;
use justinholtweb\caffeine\services\Extractors;
use justinholtweb\caffeine\services\Indexes;
use justinholtweb\caffeine\services\Mapper;
use justinholtweb\caffeine\services\Publisher;
use justinholtweb\caffeine\services\Records;
use justinholtweb\caffeine\services\Search;
use justinholtweb\caffeine\services\Sources;
use justinholtweb\caffeine\services\Tokenizer;
use justinholtweb\caffeine\web\twig\Extension;
use yii\base\Event;

/**
 * Caffeine — instant faceted search for Craft.
 *
 * @property-read Indexes $indexes
 * @property-read Artifacts $artifacts
 * @property-read AutoUpdate $autoUpdate
 * @property-read Publisher $publisher
 * @property-read Sources $sources
 * @property-read Mapper $mapper
 * @property-read Records $records
 * @property-read Search $search
 * @property-read Builder $builder
 * @property-read Extractors $extractors
 * @property-read Tokenizer $tokenizer
 * @property-read Settings $settings
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const EDITION_LITE = 'lite';
    public const EDITION_PRO = 'pro';

    public const PERMISSION_MANAGE_INDEXES = 'caffeine:manageIndexes';
    public const PERMISSION_BUILD = 'caffeine:build';

    /** Log category used by everything in the plugin. */
    public const LOG_CATEGORY = 'caffeine';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;

    public static function editions(): array
    {
        return [
            self::EDITION_LITE,
            self::EDITION_PRO,
        ];
    }

    public static function config(): array
    {
        return [
            'components' => [
                'indexes' => Indexes::class,
                'sources' => Sources::class,
                'mapper' => Mapper::class,
                'records' => Records::class,
                'builder' => Builder::class,
                'extractors' => Extractors::class,
                'artifacts' => Artifacts::class,
                'autoUpdate' => AutoUpdate::class,
                'publisher' => Publisher::class,
                'search' => Search::class,
                'tokenizer' => Tokenizer::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerLogging();
        $this->registerCpUrlRules();
        $this->registerSiteUrlRules();
        $this->registerPermissions();
        $this->registerProjectConfigHandlers();
        $this->registerTwigExtension();

        // Registered on every request type, console included: a `resave/entries` is exactly the
        // case the bulk-op coalescing exists for, and it only ever runs from the command line.
        $this->autoUpdate->register();
        $this->registerGarbageCollection();
    }

    /**
     * Removes Caffeine's own project-config key on uninstall.
     *
     * The install migration drops the tables, but index definitions live under a top-level
     * `caffeine` key that Craft knows nothing about — it only clears `plugins.caffeine`.
     * Without this the key outlives the plugin: it turns up in every `project-config/diff` on
     * every environment from then on, and reinstalling silently resurrects the old indexes.
     */
    public function afterUninstall(): void
    {
        parent::afterUninstall();

        Craft::$app->getProjectConfig()->remove(
            Indexes::CONFIG_ROOT,
            'Remove Caffeine’s index definitions',
        );
    }

    /**
     * Whether the Pro feature set is available.
     *
     * Every edition check goes through here rather than calling `is()` directly, so the
     * Lite/Pro boundary is auditable in one place.
     */
    public function isPro(): bool
    {
        return $this->is(self::EDITION_PRO, '>=');
    }

    private function registerProjectConfigHandlers(): void
    {
        Craft::$app->getProjectConfig()
            ->onAdd(Indexes::CONFIG_PATH . '.{uid}', [$this->indexes, 'handleChangedIndex'])
            ->onUpdate(Indexes::CONFIG_PATH . '.{uid}', [$this->indexes, 'handleChangedIndex'])
            ->onRemove(Indexes::CONFIG_PATH . '.{uid}', [$this->indexes, 'handleDeletedIndex']);
    }

    /**
     * Registered unconditionally rather than only on site requests: the CP's query playground
     * and any console command that renders a template need the same tags, and a Twig extension
     * costs nothing until a template actually uses it.
     */
    /**
     * Clears derived data belonging to indexes that no longer exist.
     *
     * The project-config handler covers a deleted index. It cannot cover an index whose *uid*
     * changed — a restored config, a re-run seeding script — which orphans its records and leaves
     * its artifacts being served out of the web root with nothing pointing at them.
     */
    private function registerGarbageCollection(): void
    {
        Event::on(Gc::class, Gc::EVENT_RUN, function() {
            $known = array_keys($this->indexes->allByUid());

            $this->artifacts->deleteOrphans($known);
            $this->records->deleteOrphans($known);
        });
    }

    private function registerTwigExtension(): void
    {
        Craft::$app->getView()->registerTwigExtension(new Extension());
    }

    private function registerSiteUrlRules(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                // Both endpoints are also reachable as ordinary action requests; these are the
                // tidy URLs that show up in a network panel and in a CDN's logs.
                $event->rules['caffeine/fragment'] = 'caffeine/fragment/index';
                $event->rules['caffeine/search/<handle:{handle}>'] = 'caffeine/search/index';
            }
        );
    }

    private function registerLogging(): void
    {
        /** @var Settings $settings */
        $settings = $this->getSettings();

        Craft::getLogger()->dispatcher->targets[] = new MonologTarget([
            'name' => self::LOG_CATEGORY,
            'categories' => [self::LOG_CATEGORY],
            'level' => $settings->logLevel,
            'logContext' => false,
            'allowLineBreaks' => true,
            'maxFiles' => 10,
        ]);
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $user = Craft::$app->getUser();

        $subnav = [
            'indexes' => ['label' => Craft::t('caffeine', 'Indexes'), 'url' => 'caffeine/indexes'],
        ];

        if ($user->getIsAdmin()) {
            $subnav['settings'] = ['label' => Craft::t('caffeine', 'Settings'), 'url' => 'caffeine/settings'];
        }

        $item['subnav'] = $subnav;

        return $item;
    }

    protected function createSettingsModel(): Settings
    {
        return new Settings();
    }

    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('caffeine/settings'));
    }

    private function registerCpUrlRules(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['caffeine'] = 'caffeine/indexes/index';
                $event->rules['caffeine/indexes'] = 'caffeine/indexes/index';
                $event->rules['caffeine/indexes/new'] = 'caffeine/indexes/edit';
                $event->rules['caffeine/indexes/<handle:{handle}>'] = 'caffeine/indexes/edit';
                $event->rules['caffeine/settings'] = 'caffeine/settings/index';
            }
        );
    }

    private function registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('caffeine', 'Caffeine'),
                    'permissions' => [
                        self::PERMISSION_MANAGE_INDEXES => [
                            'label' => Craft::t('caffeine', 'Manage indexes'),
                        ],
                        self::PERMISSION_BUILD => [
                            'label' => Craft::t('caffeine', 'Rebuild and republish indexes'),
                        ],
                    ],
                ];
            }
        );
    }
}
