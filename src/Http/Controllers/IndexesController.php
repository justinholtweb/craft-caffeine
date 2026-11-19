<?php

declare(strict_types=1);

namespace JustinHoltWeb\Caffeine\Http\Controllers;

use CraftCms\Cms\Cp\Data\ActionItem;
use CraftCms\Cms\Http\RespondsWithFlash;
use CraftCms\Cms\Http\Responses\CpScreenResponse;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Support\Html;
use CraftCms\Cms\Support\Url;
use CraftCms\Cms\Ui\Controls\Choice;
use CraftCms\Cms\Ui\Controls\Hidden;
use CraftCms\Cms\Ui\Controls\Lightswitch;
use CraftCms\Cms\Ui\Controls\Number;
use CraftCms\Cms\Ui\Controls\Table as TableControl;
use CraftCms\Cms\Ui\Controls\Text;
use CraftCms\Cms\Ui\Controls\Textarea;
use CraftCms\Cms\Ui\Nodes\Callout;
use CraftCms\Cms\Ui\Nodes\Field;
use CraftCms\Cms\Ui\Nodes\Heading;
use CraftCms\Cms\Ui\Nodes\Table;
use CraftCms\Cms\Ui\Nodes\TemplateContent;
use CraftCms\Cms\Ui\Ui;
use CraftCms\Cms\Ui\UiContext;
use CraftCms\Cms\Ui\UiResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use JustinHoltWeb\Caffeine\Jobs\UpdateJob;
use JustinHoltWeb\Caffeine\Models\AttributeDefinition;
use JustinHoltWeb\Caffeine\Models\Edition;
use JustinHoltWeb\Caffeine\Models\IndexDefinition;
use JustinHoltWeb\Caffeine\Models\SourceDefinition;
use JustinHoltWeb\Caffeine\Plugin;
use JustinHoltWeb\Caffeine\Search\Engine;
use JustinHoltWeb\Caffeine\Search\QueryState;
use JustinHoltWeb\Caffeine\Services\Artifacts;
use JustinHoltWeb\Caffeine\Services\AutoUpdate;
use JustinHoltWeb\Caffeine\Services\Indexes;
use JustinHoltWeb\Caffeine\Services\Mapper;
use JustinHoltWeb\Caffeine\Services\Publisher;
use JustinHoltWeb\Caffeine\Services\Records;
use JustinHoltWeb\Caffeine\Services\Sources;
use JustinHoltWeb\Caffeine\Services\Tokenizer;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

use function CraftCms\Cms\t;

/**
 * The control panel.
 *
 * An index definition is project config, so the edit screen is a form over YAML rather than over
 * a table — which is why saving goes through `Indexes::save()` and never touches the database,
 * and why `IndexDefinition::fromForm()` starts from the stored definition so settings this screen
 * doesn't show survive a save.
 *
 * Permissions are applied per route in `routes/cp.php`.
 */
class IndexesController
{
    use RespondsWithFlash;

    public function __construct(
        private readonly Indexes $indexes,
        private readonly Artifacts $artifacts,
        private readonly AutoUpdate $autoUpdate,
        private readonly Sources $sources,
    ) {}

    public function index(): CpScreenResponse
    {
        $canBuild = Gate::check(Plugin::PERMISSION_BUILD);
        $rows = [];

        foreach ($this->indexes->all() as $index) {
            $status = $this->artifacts->status($index);
            $allowed = $this->indexes->isAllowed($index);

            $name = Html::tag('strong', Html::encode($index->name)).' '.Html::tag('code', Html::encode($index->handle));

            if (! $allowed) {
                $name .= ' · '.Html::encode(t('Not available on this edition', category: 'caffeine'));
            } elseif (! $index->enabled) {
                $name .= ' · '.Html::encode(t('Disabled', category: 'caffeine'));
            }

            $records = (string) $status['records'];

            if (! empty($status['dirty'])) {
                // The number that matters most on this screen: what visitors are being served is
                // behind the CMS by exactly this much.
                $records .= ' · '.t('{n} stale', ['n' => $status['dirty']], category: 'caffeine');

                if ($this->autoUpdate->isPending($index->uid)) {
                    $records .= ' · '.t('update queued', category: 'caffeine');
                }
            }

            $published = ! empty($status['version'])
                ? 'v'.$status['version'].(! empty($status['publishedAt']) ? ' · '.self::formatDate($status['publishedAt']) : '')
                : t('Never', category: 'caffeine');

            $rows[] = [
                'id' => $index->handle,
                'name' => ['html' => Html::a($name, Url::cpUrl('caffeine/indexes/'.$index->handle))],
                'records' => $records,
                'published' => $published,
                'size' => ! empty($status['bytes']) ? number_format($status['bytes'] / 1024, 1).' KB' : '—',
                'transport' => ['html' => Html::tag('code', Html::encode($index->transport))],
                'tools' => ['label' => $canBuild ? t('Rebuild & tools', category: 'caffeine') : t('Tools', category: 'caffeine'), 'url' => Url::cpUrl('caffeine/indexes/'.$index->handle.'/tools')],
                '_deleteUrl' => Url::cpUrl('caffeine/indexes/'.$index->handle),
                '_deleteConfirmMessage' => t('Delete “{name}”? Its records and published artifacts go with it.', ['name' => $index->name], category: 'caffeine'),
            ];
        }

        $table = Table::make('caffeine-indexes')
            ->columns([
                ['key' => 'name', 'label' => t('Index', category: 'caffeine'), 'width' => '2fr'],
                ['key' => 'records', 'label' => t('Records', category: 'caffeine')],
                ['key' => 'published', 'label' => t('Published', category: 'caffeine')],
                ['key' => 'size', 'label' => t('Size', category: 'caffeine')],
                ['key' => 'transport', 'label' => t('Transport', category: 'caffeine')],
                ['key' => 'tools', 'label' => t('Tools', category: 'caffeine')],
            ])
            ->rows($rows)
            ->emptyMessage(t('No indexes yet.', category: 'caffeine'))
            ->createAction(t('New index', category: 'caffeine'), Url::cpUrl('caffeine/indexes/new'))
            ->createActionInPageHeader()
            ->deletable();

        return new CpScreenResponse()
            ->title(t('Indexes', category: 'caffeine'))
            ->ui(Ui::make([
                $table,
                Callout::make('caffeine-store', t('Publishing to {store}.', ['store' => $this->describeStore()], category: 'caffeine')),
            ]));
    }

    public function create(): CpScreenResponse
    {
        return $this->screen(new IndexDefinition);
    }

    public function edit(string $handle): CpScreenResponse
    {
        // The stored definition, not the one modules have added to: those additions are shown
        // read-only and must not round-trip into project config.
        $index = $this->indexes->getStoredByHandle($handle) ?? abort(404, "No index with the handle “{$handle}”.");

        return $this->screen($index);
    }

    public function save(Request $request): Response|CpScreenResponse
    {
        $uid = (string) $request->input('uid', '');
        $existing = $uid !== '' ? $this->indexes->getStoredByUid($uid) : null;

        // Laravel turns blank inputs into null; fromForm() treats missing and null alike.
        $index = IndexDefinition::fromForm($request->all(), $existing);

        if (! $this->indexes->save($index)) {
            return $this->screen($index, t('Couldn’t save the index.', category: 'caffeine'));
        }

        // A changed definition invalidates every record built under the old one, and the
        // project-config handler has already marked them dirty.
        return $this->asSuccess(
            t('Index saved.', category: 'caffeine'),
            redirect: Url::cpUrl('caffeine/indexes/'.$index->handle),
        );
    }

    public function destroy(string $handle): Response
    {
        $index = $this->indexes->getByHandle($handle) ?? abort(404, "No index with the handle “{$handle}”.");

        $this->indexes->delete($index);

        return $this->asSuccess(t('Index deleted.', category: 'caffeine'), redirect: Url::cpUrl('caffeine/indexes'));
    }

    /**
     * The tools screen: rebuild and publish, plus the Pro record preview and query playground.
     */
    public function tools(string $handle): CpScreenResponse
    {
        $index = $this->indexes->getByHandle($handle) ?? abort(404, "No index with the handle “{$handle}”.");

        return new CpScreenResponse()
            ->title(t('{name} tools', ['name' => $index->name], category: 'caffeine'))
            ->crumbs([
                new ActionItem()->label(t('Indexes', category: 'caffeine'))->href(Url::cpUrl('caffeine/indexes')),
                new ActionItem()->label($index->name)->href(Url::cpUrl('caffeine/indexes/'.$index->handle)),
            ])
            ->contentTemplate('caffeine/indexes/_tools', [
                'index' => $index,
                'status' => $this->artifacts->status($index),
                'canBuild' => Gate::check(Plugin::PERMISSION_BUILD) && $this->indexes->isAllowed($index),
                'isPro' => Plugin::getInstance()->isPro(),
                'previewUrl' => Url::cpUrl('caffeine/indexes/'.$index->handle.'/preview'),
                'queryUrl' => Url::cpUrl('caffeine/indexes/'.$index->handle.'/query'),
            ]);
    }

    /**
     * Rebuilds records and republishes, through the same job the element events queue.
     */
    public function build(Request $request, string $handle, Records $records): Response
    {
        $index = $this->indexes->getByHandle($handle) ?? abort(404);

        if ($request->boolean('full')) {
            // A full rebuild has to mark everything first: UpdateJob only walks dirty rows, and
            // "rebuild everything" is exactly the button you press when you no longer trust what
            // is in there.
            $records->markIndexDirty($index->uid);
        }

        $this->autoUpdate->clearPending($index->uid);

        UpdateJob::dispatch($index->uid);

        return $this->asSuccess(
            t('Rebuilding “{name}”.', ['name' => $index->name], category: 'caffeine'),
            redirect: Url::cpUrl('caffeine/indexes/'.$index->handle.'/tools'),
        );
    }

    public function publish(string $handle): Response
    {
        $index = $this->indexes->getByHandle($handle) ?? abort(404);
        $back = Url::cpUrl('caffeine/indexes/'.$index->handle.'/tools');

        try {
            $result = $this->artifacts->publish($index);
        } catch (Throwable $e) {
            return $this->asFailure(t('Couldn’t publish: {message}', ['message' => $e->getMessage()], category: 'caffeine'));
        }

        return $this->asSuccess($result['published']
            ? t('Published v{version}.', ['version' => $result['version']], category: 'caffeine')
            : t('Nothing changed — v{version} is still current.', ['version' => $result['version']], category: 'caffeine'),
            redirect: $back);
    }

    /**
     * The record this definition would build for one element. Pro.
     *
     * The fastest way to find out why a facet is empty: look at what the mapper actually got,
     * rather than rebuilding and reading the artifact.
     */
    public function preview(Request $request, string $handle, Mapper $mapper, Tokenizer $tokenizer): JsonResponse
    {
        $this->requirePro();

        $index = $this->indexes->getByHandle($handle) ?? abort(404);
        $siteId = (int) ($request->input('siteId') ?: Sites::getCurrentSite()->id);
        $element = Elements::getElementById((int) $request->input('elementId'), null, $siteId);

        if ($element === null) {
            return response()->json(['error' => t('No element with that ID.', category: 'caffeine')]);
        }

        try {
            $startedAt = microtime(true);
            $record = $mapper->map($index, $element);

            return response()->json([
                'element' => (string) $element,
                'covered' => $this->sources->covers($index, $element),
                'record' => $record->toArray(),
                'tokens' => $tokenizer->tokenizeRecord($index, $record),
                'dependencies' => $record->dependencies,
                'ms' => round((microtime(true) - $startedAt) * 1000, 2),
            ]);
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()]);
        }
    }

    /**
     * Runs a state against the published artifact. Pro.
     *
     * Deliberately the *published* artifact rather than a fresh compile: the question this answers
     * is "what are visitors getting".
     */
    public function query(Request $request, string $handle): JsonResponse
    {
        $this->requirePro();

        $index = $this->indexes->getByHandle($handle) ?? abort(404);
        $state = json_decode((string) $request->input('state', '{}'), true);

        if (! is_array($state)) {
            return response()->json(['error' => t('That is not valid JSON.', category: 'caffeine')]);
        }

        $artifact = $this->artifacts->published($index);

        if ($artifact === null) {
            return response()->json(['error' => t('Nothing is published for this index yet.', category: 'caffeine')]);
        }

        try {
            $startedAt = microtime(true);
            $result = (new Engine($artifact))->search(QueryState::fromArray($state), $index->hitsPerPage);

            return response()->json([
                'version' => $artifact->version,
                'ms' => round((microtime(true) - $startedAt) * 1000, 3),
                'result' => $result,
            ]);
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()]);
        }
    }

    // ---------------------------------------------------------------------------------------------

    private function screen(IndexDefinition $index, ?string $error = null): CpScreenResponse
    {
        $isNew = $index->uid === '';
        $isPro = Plugin::getInstance()->isPro();

        if ($index->sources === []) {
            $index->sources = [new SourceDefinition];
        }

        $values = self::values($index);
        $context = new UiContext(values: $values, errors: $index->errors()->getMessages());
        $title = $isNew ? t('New index', category: 'caffeine') : $index->name;

        $response = new CpScreenResponse()
            ->title($title)
            ->crumbs([
                new ActionItem()->label(t('Indexes', category: 'caffeine'))->href(Url::cpUrl('caffeine/indexes')),
                new ActionItem()->label($title),
            ])
            ->redirectUrl(Url::cpUrl('caffeine/indexes'))
            ->ui($this->editUi($index, $isPro, $error), $context, [
                'submit' => ['method' => 'post', 'url' => Url::cpUrl('caffeine/indexes')],
                'sidebarUi' => app(UiResolver::class)->resolve($this->sidebarUi($index, $isPro), $context),
            ]);

        if (! $isNew) {
            $response->addAltAction(t('Rebuild & tools', category: 'caffeine'), [
                'href' => Url::cpUrl('caffeine/indexes/'.$index->handle.'/tools'),
            ]);
        }

        return $response;
    }

    private function editUi(IndexDefinition $index, bool $isPro, ?string $error): Ui
    {
        $sourceOptions = [];
        $help = [];

        foreach ($this->sources->all() as $source) {
            $sourceOptions[] = ['label' => $source::displayName(), 'value' => $source::handle()];

            $containers = array_keys($source->containerOptions());
            $subTypes = array_keys($source->subTypeOptions());

            if ($containers !== [] || $subTypes !== []) {
                $help[] = '- **'.$source::displayName().'**: '
                    .($containers !== [] ? t('containers', category: 'caffeine').' `'.implode('`, `', $containers).'`' : '')
                    .($containers !== [] && $subTypes !== [] ? '; ' : '')
                    .($subTypes !== [] ? t('types', category: 'caffeine').' `'.implode('`, `', $subTypes).'`' : '');
            }
        }

        $role = ['type' => 'lightswitch', 'class' => 'thin centered'];

        $dynamic = array_values(array_filter(
            $this->indexes->getByUid($index->uid)->attributes ?? [],
            fn (AttributeDefinition $attribute) => $attribute->dynamic,
        ));

        return Ui::make(array_values(array_filter([
            $error !== null ? Callout::make('caffeine-error', $error)->variant('danger') : null,
            Field::make(null, Hidden::make('uid')),
            Field::make(t('Name', category: 'caffeine'), Text::make('name'))->required(),
            Field::make(t('Handle', category: 'caffeine'), Text::make('handle'))
                ->instructions(t('Used in templates and in the published URL. Changing it moves the published artifacts.', category: 'caffeine'))
                ->required(),
            Field::make(t('Description', category: 'caffeine'), Textarea::make('description')->rows(2)),

            Heading::make('caffeine-sources', t('Sources', category: 'caffeine'))
                ->description(t('Which elements become records. Containers and types are handles, comma-separated; leave them blank for all.', category: 'caffeine')),
            Field::make(null, TableControl::make('sources')
                ->columns([
                    'type' => ['heading' => t('Element type', category: 'caffeine'), 'type' => 'select', 'options' => $sourceOptions],
                    'containers' => ['heading' => t('Containers', category: 'caffeine'), 'type' => 'singleline', 'code' => true],
                    'subTypes' => ['heading' => t('Types', category: 'caffeine'), 'type' => 'singleline', 'code' => true],
                    'status' => ['heading' => t('Status', category: 'caffeine'), 'type' => 'select', 'options' => [
                        ['label' => t('Live', category: 'caffeine'), 'value' => 'live'],
                        ['label' => t('Enabled', category: 'caffeine'), 'value' => 'enabled'],
                        ['label' => t('Any', category: 'caffeine'), 'value' => 'any'],
                    ]],
                ])
                ->allowAdd()->allowDelete()->allowReorder()
                ->addRowLabel(t('Add a source', category: 'caffeine')))
                ->instructions($help !== [] ? implode("\n", $help) : null),

            Heading::make('caffeine-attributes', t('Attributes', category: 'caffeine'))
                ->description(implode(' ', array_filter([
                    t('One row per key in a record. Roles are not exclusive — a title is usually searchable, sortable and payload at once.', category: 'caffeine'),
                    t('A geo facet points its path at an address field and is filtered by distance rather than by value, so it has no buckets.', category: 'caffeine'),
                    $isPro ? null : t('Lite builds string and boolean facets. Hierarchical, numeric, date and geo facets need Pro.', category: 'caffeine'),
                ]))),
            Field::make(null, TableControl::make('attributes')
                ->columns([
                    'key' => ['heading' => t('Key', category: 'caffeine'), 'type' => 'singleline', 'code' => true],
                    'label' => ['heading' => t('Label', category: 'caffeine'), 'type' => 'singleline'],
                    'source' => ['heading' => t('From', category: 'caffeine'), 'type' => 'select', 'options' => [
                        ['label' => t('Attribute', category: 'caffeine'), 'value' => AttributeDefinition::SOURCE_ATTRIBUTE],
                        ['label' => t('Field', category: 'caffeine'), 'value' => AttributeDefinition::SOURCE_FIELD],
                    ]],
                    'path' => ['heading' => t('Path', category: 'caffeine'), 'type' => 'singleline', 'code' => true],
                    'searchable' => $role + ['heading' => t('Search', category: 'caffeine')],
                    'facet' => $role + ['heading' => t('Facet', category: 'caffeine')],
                    'sortable' => $role + ['heading' => t('Sort', category: 'caffeine')],
                    'payload' => $role + ['heading' => t('Payload', category: 'caffeine')],
                    'searchWeight' => ['heading' => t('Weight', category: 'caffeine'), 'type' => 'number', 'min' => 0, 'step' => 0.1],
                    'facetType' => ['heading' => t('Facet type', category: 'caffeine'), 'type' => 'select', 'options' => array_map(
                        fn (string $type) => ['label' => ucfirst($type), 'value' => $type],
                        Edition::facetTypes($isPro),
                    )],
                    'facetOperator' => ['heading' => t('Operator', category: 'caffeine'), 'type' => 'select', 'options' => [
                        ['label' => t('Or', category: 'caffeine'), 'value' => 'or'],
                        ['label' => t('And', category: 'caffeine'), 'value' => 'and'],
                    ]],
                ])
                ->allowAdd()->allowDelete()->allowReorder()
                ->addRowLabel(t('Add an attribute', category: 'caffeine')))
                ->instructions(t('Per-attribute settings this screen doesn’t show — value ordering, numeric buckets, transforms, hierarchy separator — are kept as they are when you save, so they can be managed in project config without this screen undoing them.', category: 'caffeine')),

            $dynamic === [] ? null : TemplateContent::make('caffeine-dynamic', self::dynamicTable($dynamic)),

            Heading::make('caffeine-sortings', t('Sortings', category: 'caffeine'))
                ->description(t('Each is precomputed into the artifact, so switching sort at query time is a pointer swap rather than a sort. Relevance always exists and doesn’t need a row.', category: 'caffeine')),
            Field::make(null, TableControl::make('sortings')
                ->columns([
                    'name' => ['heading' => t('Name', category: 'caffeine'), 'type' => 'singleline', 'code' => true],
                    'label' => ['heading' => t('Label', category: 'caffeine'), 'type' => 'singleline'],
                    'attribute' => ['heading' => t('Attribute key', category: 'caffeine'), 'type' => 'singleline', 'code' => true],
                    'direction' => ['heading' => t('Direction', category: 'caffeine'), 'type' => 'select', 'options' => [
                        ['label' => t('Ascending', category: 'caffeine'), 'value' => 'asc'],
                        ['label' => t('Descending', category: 'caffeine'), 'value' => 'desc'],
                    ]],
                ])
                ->allowAdd()->allowDelete()->allowReorder()
                ->addRowLabel(t('Add a sorting', category: 'caffeine'))),

            Heading::make('caffeine-words', t('Words', category: 'caffeine'))
                ->description($isPro ? null : t('Stopwords and synonyms need Caffeine Pro.', category: 'caffeine')),
            Field::make(t('Stopwords', category: 'caffeine'), Text::make('stopwords'))
                ->instructions(t('Comma-separated. Dropped from both documents and queries.', category: 'caffeine')),
            Field::make(t('Synonyms', category: 'caffeine'), Textarea::make('synonyms')->rows(4)->monospace())
                ->instructions(t('One group per line, comma-separated: `sofa, couch, settee`. Expanded at index time.', category: 'caffeine')),
        ])));
    }

    private function sidebarUi(IndexDefinition $index, bool $isPro): Ui
    {
        return Ui::make([
            Field::make(t('Transport', category: 'caffeine'), Choice::make('transport')->options(array_map(
                fn (string $transport) => ['label' => $transport, 'value' => $transport],
                Edition::transports($isPro),
            ))),
            Field::make(t('Hits per page', category: 'caffeine'), Number::make('hitsPerPage')->min(1)->max(1000)),
            Field::make(t('Max values per facet', category: 'caffeine'), Number::make('maxValuesPerFacet')->min(1)->max(1000)),
            Field::make(t('Publish debounce', category: 'caffeine'), Number::make('publishDebounce')->min(0)->max(3600)->suffix(t('seconds', category: 'caffeine')))
                ->instructions(t('How long to wait after a save before rebuilding, so a burst of edits is one rebuild.', category: 'caffeine')),
            Field::make(t('Shard payload', category: 'caffeine'), Lightswitch::make('shardPayload')),
            Field::make(t('Enabled', category: 'caffeine'), Lightswitch::make('enabled')),
            Field::make(t('Sites', category: 'caffeine'), Choice::make('siteIds')->multiple()->options(
                Sites::getAllSites()->map(fn ($site) => ['label' => $site->name, 'value' => (int) $site->id])->values()->all(),
            ))->instructions(t('Leave empty for every site.', category: 'caffeine')),
        ]);
    }

    /**
     * The form's values, in the shape `IndexDefinition::fromForm()` reads back.
     *
     * @return array<string, mixed>
     */
    private static function values(IndexDefinition $index): array
    {
        return [
            'uid' => $index->uid,
            'name' => $index->name,
            'handle' => $index->handle,
            'description' => $index->description,
            'transport' => $index->transport,
            'hitsPerPage' => $index->hitsPerPage,
            'maxValuesPerFacet' => $index->maxValuesPerFacet,
            'publishDebounce' => $index->publishDebounce,
            'shardPayload' => $index->shardPayload,
            'enabled' => $index->enabled,
            'siteIds' => array_map('intval', $index->siteIds),
            'stopwords' => implode(', ', $index->stopwords),
            'synonyms' => implode("\n", $index->synonyms),
            'sources' => array_map(fn (SourceDefinition $s) => [
                'type' => $s->type,
                'containers' => implode(', ', $s->containers),
                'subTypes' => implode(', ', $s->subTypes),
                'status' => $s->status,
            ], $index->sources),
            'attributes' => array_map(fn (AttributeDefinition $a) => [
                'key' => $a->key,
                'label' => $a->label,
                'source' => $a->source,
                'path' => $a->path,
                'searchable' => in_array(AttributeDefinition::ROLE_SEARCHABLE, $a->roles, true),
                'facet' => in_array(AttributeDefinition::ROLE_FACET, $a->roles, true),
                'sortable' => in_array(AttributeDefinition::ROLE_SORTABLE, $a->roles, true),
                'payload' => in_array(AttributeDefinition::ROLE_PAYLOAD, $a->roles, true),
                'searchWeight' => $a->searchWeight,
                'facetType' => $a->facetType,
                'facetOperator' => $a->facetOperator,
            ], array_values(array_filter($index->attributes, fn (AttributeDefinition $a) => ! $a->dynamic))),
            'sortings' => array_map(fn ($s) => [
                'name' => $s->name,
                'label' => $s->label,
                'attribute' => $s->attribute,
                'direction' => $s->direction,
            ], array_values(array_filter($index->sortings, fn ($s) => ! $s->dynamic))),
        ];
    }

    /**
     * @param  list<AttributeDefinition>  $attributes
     */
    private static function dynamicTable(array $attributes): string
    {
        $rows = '';

        foreach ($attributes as $a) {
            $rows .= '<tr><td><code>'.Html::encode($a->key).'</code></td><td>'.Html::encode((string) $a->label).'</td><td><code>'
                .Html::encode($a->path).'</code></td><td>'.Html::encode(implode(', ', $a->roles)).'</td><td>'
                .Html::encode($a->isFacet() ? $a->facetType : '').'</td></tr>';
        }

        return '<h3>'.Html::encode(t('Added by a plugin or module', category: 'caffeine')).'</h3>'
            .'<p>'.Html::encode(t('These attributes come from an event listener each time the index loads. They are built and queried like the ones above, but they are not saved with this index and can’t be edited here.', category: 'caffeine')).'</p>'
            .'<table class="data fullwidth"><thead><tr><th>'.Html::encode(t('Key', category: 'caffeine')).'</th><th>'
            .Html::encode(t('Label', category: 'caffeine')).'</th><th>'.Html::encode(t('Path', category: 'caffeine')).'</th><th>'
            .Html::encode(t('Roles', category: 'caffeine')).'</th><th>'.Html::encode(t('Facet type', category: 'caffeine')).'</th></tr></thead><tbody>'
            .$rows.'</tbody></table>';
    }

    /**
     * The record preview and the query playground are Pro — checked here as well as hidden in the
     * template, because a hidden button is a courtesy, not a boundary.
     */
    private function requirePro(): void
    {
        abort_unless(Plugin::getInstance()->isPro(), 403, t('The record preview and query playground need Caffeine Pro.', category: 'caffeine'));
    }

    private function describeStore(): string
    {
        try {
            return app(Publisher::class)->store()->describe();
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    private static function formatDate(mixed $value): string
    {
        try {
            $date = $value instanceof \DateTimeInterface ? $value : new \DateTimeImmutable((string) $value);

            return $date->format('Y-m-d H:i');
        } catch (Throwable) {
            return (string) $value;
        }
    }
}
