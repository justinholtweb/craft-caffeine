<?php

namespace justinholtweb\caffeine\models;

use Craft;
use justinholtweb\caffeine\Plugin;
use justinholtweb\caffeine\search\Artifact;
use justinholtweb\caffeine\search\FacetValue;
use justinholtweb\caffeine\search\QueryState;
use justinholtweb\caffeine\search\UrlState;
use craft\web\View;
use Twig\Markup;

/**
 * What `search` is inside `{% caffeine %}`.
 *
 * Everything a template needs, and everything the runtime needs, in one object. The widgets are
 * thin wrappers over the same methods a developer would call directly — `facet()`, `toggleUrl()`
 * — so ignoring the widgets and writing your own markup costs nothing and loses nothing.
 *
 * Every URL this builds is a real, complete URL that server-renders the state it describes.
 * That is not a nicety: it is what makes the whole feature work with JavaScript switched off,
 * and it is why the runtime can be a few kilobytes — it intercepts links that already work
 * rather than inventing behaviour.
 */
class SearchContext
{
    /** Captured by `{% caffeineresults %}`, so the fragment endpoint can return exactly that block. */
    private ?string $renderedResults = null;

    /**
     * @var array<string, string> Region id → rendered HTML.
     *
     * Every part of the page that depends on the state records itself here as it renders, and
     * the fragment endpoint returns the lot. Swapping only the hits would leave the facet counts
     * beside them describing the previous query — "Acme (12)" next to three results — which is
     * the specific bug that makes hand-rolled filtering feel broken.
     */
    private array $regions = [];

    /** @var array<string, mixed>|null Memoised facet views, since a template asks twice as often as not. */
    private ?array $facetCache = null;

    public function __construct(
        public readonly IndexDefinition $index,
        public readonly Artifact $artifact,
        public readonly QueryState $state,
        /** @var array<string, mixed> The engine's Algolia-shaped response. */
        public readonly array $result,
        /** Path the facet links point back at — the page's own, not the fragment endpoint's. */
        public readonly string $path,
        /** Distinguishes two indexes on one page. Empty for the common case of one. */
        public readonly string $prefix = '',
        /** Version of the artifact this was rendered against, so the runtime can notice it is stale. */
        public readonly int $version = 0,
        /** @var array<string, mixed> What the tag was given, including where it is rendering. */
        public readonly array $options = [],
    ) {
    }

    // -----------------------------------------------------------------------------------------
    // Results
    // -----------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function getHits(): array
    {
        return $this->result['hits'] ?? [];
    }

    public function getNbHits(): int
    {
        return (int)($this->result['nbHits'] ?? 0);
    }

    public function getPage(): int
    {
        return (int)($this->result['page'] ?? 0);
    }

    public function getNbPages(): int
    {
        return (int)($this->result['nbPages'] ?? 0);
    }

    public function getHitsPerPage(): int
    {
        return (int)($this->result['hitsPerPage'] ?? $this->index->hitsPerPage);
    }

    public function getQuery(): string
    {
        return (string)($this->result['query'] ?? '');
    }

    public function getProcessingTimeMS(): int
    {
        return (int)($this->result['processingTimeMS'] ?? 0);
    }

    public function getIsEmpty(): bool
    {
        return $this->getNbHits() === 0;
    }

    /** Position of the first hit on this page, one-based, for "showing 25–48 of 137". */
    public function getFrom(): int
    {
        return $this->getNbHits() === 0 ? 0 : ($this->getPage() * $this->getHitsPerPage()) + 1;
    }

    public function getTo(): int
    {
        return min($this->getNbHits(), ($this->getPage() + 1) * $this->getHitsPerPage());
    }

    // -----------------------------------------------------------------------------------------
    // Facets
    // -----------------------------------------------------------------------------------------

    /**
     * One facet, with its buckets already carrying the URL that toggles each of them.
     *
     * @return array{key: string, label: string, type: string, operator: string, isRefined: bool, buckets: list<array<string, mixed>>, stats: array<string, float>|null}|null
     */
    public function facet(string $key): ?array
    {
        return $this->getFacets()[$key] ?? null;
    }

    /**
     * Every facet the index defines, in definition order.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getFacets(): array
    {
        if ($this->facetCache !== null) {
            return $this->facetCache;
        }

        $facets = [];

        foreach ($this->index->facets() as $definition) {
            $key = $definition->key;
            $raw = $this->result['caffeineFacets'][$key] ?? null;

            if ($raw === null) {
                continue;
            }

            $buckets = [];

            foreach ($raw['buckets'] ?? [] as $bucket) {
                $buckets[] = $bucket + [
                    'key' => FacetValue::toKey($bucket['value']),
                    'label' => $this->label($definition, $bucket['value']),
                    'url' => $this->toggleUrl($key, $bucket['value']),
                ];
            }

            $facets[$key] = [
                'key' => $key,
                'label' => $definition->label ?: $key,
                'type' => $definition->facetType,
                'operator' => $raw['operator'] ?? 'or',
                'isRefined' => ($this->state->refinements[$key] ?? []) !== []
                    || isset($this->state->ranges[$key]),
                'buckets' => $buckets,
                'stats' => $this->result['facets_stats'][$key] ?? null,
            ];
        }

        return $this->facetCache = $facets;
    }

    /**
     * Every active refinement, flattened, each with the URL that removes it.
     *
     * Named `getRefinements` rather than `getCurrentRefinements` on purpose. Twig resolves
     * `search.currentRefinements` to a method of exactly that name *before* it tries the `get`
     * prefix, so a getter called `getCurrentRefinements()` would lose to the `currentRefinements()`
     * widget — and the widget's own template reads the value, so it re-entered itself until the
     * stack overflowed. A segfault, not an exception.
     *
     * @return list<array{facet: string, facetLabel: string, value: mixed, label: string, url: string, isRange: bool}>
     */
    public function getRefinements(): array
    {
        $current = [];

        foreach ($this->index->facets() as $definition) {
            $key = $definition->key;

            foreach ($this->state->refinements[$key] ?? [] as $value) {
                $current[] = [
                    'facet' => $key,
                    'facetLabel' => $definition->label ?: $key,
                    'value' => $value,
                    'label' => $this->label($definition, $value),
                    'url' => $this->toggleUrl($key, $value),
                    'isRange' => false,
                ];
            }

            $around = $this->state->around[$key] ?? null;

            if ($around !== null && ($around['radius'] ?? 0) > 0) {
                $current[] = [
                    'facet' => $key,
                    'facetLabel' => $definition->label ?: $key,
                    'value' => $around,
                    'label' => Craft::t('caffeine', 'Within {distance}', [
                        'distance' => $this->distanceLabel((float)$around['radius']),
                    ]),
                    'url' => $this->geoUrl($key),
                    'isRange' => true,
                ];
            }

            $range = $this->state->ranges[$key] ?? null;

            if ($range !== null) {
                $current[] = [
                    'facet' => $key,
                    'facetLabel' => $definition->label ?: $key,
                    'value' => $range,
                    'label' => $this->rangeLabel($range),
                    'url' => $this->rangeUrl($key, null, null),
                    'isRange' => true,
                ];
            }
        }

        return $current;
    }

    public function getHasRefinements(): bool
    {
        return $this->state->hasRefinements() || $this->state->query !== '';
    }

    // -----------------------------------------------------------------------------------------
    // URLs
    //
    // Each returns the URL of the state that would result from the change — never a URL that
    // needs JavaScript to mean anything. Paging always resets to the first page, because a
    // visitor who narrows from 200 results to 3 while on page 7 should not be shown nothing.
    // -----------------------------------------------------------------------------------------

    public function toggleUrl(string $facet, mixed $value): string
    {
        $state = $this->cloneState();
        $values = $state->refinements[$facet] ?? [];
        $found = false;

        foreach ($values as $index => $existing) {
            if ($existing === $value) {
                unset($values[$index]);
                $found = true;
                break;
            }
        }

        if (!$found) {
            $values[] = $value;
        }

        $state->refinements[$facet] = array_values($values);
        $state->page = 0;

        return $this->urlFor($state);
    }

    public function rangeUrl(string $facet, mixed $min = null, mixed $max = null): string
    {
        $state = $this->cloneState();
        $range = [];

        if ($min !== null && $min !== '') {
            $range['min'] = (float)$min;
        }

        if ($max !== null && $max !== '') {
            $range['max'] = (float)$max;
        }

        if ($range === []) {
            unset($state->ranges[$facet]);
        } else {
            $state->ranges[$facet] = $range;
        }

        $state->page = 0;

        return $this->urlFor($state);
    }

    /**
     * The URL that filters a geo facet to a radius around a point.
     *
     * Passing no radius clears the filter but keeps the point, which is what "show everything,
     * nearest first" needs — the point is still required to sort by distance.
     */
    public function geoUrl(string $facet, ?float $lat = null, ?float $lng = null, ?float $radius = null): string
    {
        $state = $this->cloneState();

        if ($lat === null || $lng === null) {
            unset($state->around[$facet]);
        } else {
            $state->around[$facet] = [
                'lat' => $lat,
                'lng' => $lng,
                'radius' => max(0.0, (float)($radius ?? 0)),
            ];
        }

        $state->page = 0;

        return $this->urlFor($state);
    }

    public function sortUrl(string $sorting): string
    {
        $state = $this->cloneState();
        $state->sortBy = $sorting;
        $state->page = 0;

        return $this->urlFor($state);
    }

    public function pageUrl(int $page): string
    {
        $state = $this->cloneState();
        $state->page = max(0, $page);

        return $this->urlFor($state);
    }

    public function queryUrl(string $query): string
    {
        $state = $this->cloneState();
        $state->query = $query;
        $state->page = 0;

        return $this->urlFor($state);
    }

    /** Clears one facet, or everything when given no argument. */
    public function clearUrl(?string $facet = null): string
    {
        $state = $this->cloneState();

        if ($facet === null) {
            $state->refinements = [];
            $state->ranges = [];
            $state->query = '';
        } else {
            unset($state->refinements[$facet], $state->ranges[$facet]);
        }

        $state->page = 0;

        return $this->urlFor($state);
    }

    /**
     * The current state as query-string parameters, for a form's hidden fields.
     *
     * A `GET` form replaces the entire query string when it submits, so a search box that did
     * not carry the active refinements forward would silently clear them the moment someone
     * typed. Passing `except` leaves out the parameters the form's own fields own.
     *
     * @param list<string> $except Unprefixed parameter names the form supplies itself.
     * @return array<string, string>
     */
    public function params(array $except = []): array
    {
        $params = UrlState::encode($this->state, $this->urlOptions());

        foreach ($except as $name) {
            unset($params[$this->prefix . $name]);

            // A range facet's form fields are named after the facet, so excluding "price" has
            // to drop the canonical parameter too or the two would fight.
            unset($params[$this->prefix . $name . UrlState::SUFFIX_MIN]);
            unset($params[$this->prefix . $name . UrlState::SUFFIX_MAX]);
        }

        return $params;
    }

    /**
     * The unrefined URL of this listing — what `rel="canonical"` should point at.
     *
     * A faceted listing generates a combinatorial explosion of URLs, and letting a search engine
     * index all of them is how a site ends up with 40,000 near-duplicate pages.
     */
    public function getCanonicalUrl(): string
    {
        return $this->path;
    }

    public function urlFor(QueryState $state): string
    {
        return UrlState::url($this->path, $state, $this->urlOptions());
    }

    /**
     * @return array{prefix: string, defaultSort: string}
     */
    public function urlOptions(): array
    {
        return [
            'prefix' => $this->prefix,
            'defaultSort' => $this->index->defaultSorting()->name,
        ];
    }

    // -----------------------------------------------------------------------------------------
    // Runtime handoff
    // -----------------------------------------------------------------------------------------

    /**
     * The configuration the browser runtime reads out of the page.
     *
     * @return array<string, mixed>
     */
    public function config(): array
    {
        $publisher = Plugin::getInstance()->publisher;

        return [
            'index' => $this->index->handle,
            'transport' => $this->index->transport,
            'prefix' => $this->prefix,
            'path' => $this->path,
            'version' => $this->version,
            'defaultSort' => $this->index->defaultSorting()->name,
            'hitsPerPage' => $this->getHitsPerPage(),
            // The stable pointer, never a versioned URL. Cached HTML months old still finds
            // today's index through it.
            'pointer' => $publisher->pointerUrl($this->index),
            'fragment' => $this->fragmentUrl(),
            'state' => $this->state->toArray(),
            // Signed, so the endpoint will render this template and no other. Without the
            // signature an endpoint that takes a template name from the request is an arbitrary
            // template-render primitive pointed at the whole site.
            'tokenParam' => \justinholtweb\caffeine\controllers\FragmentController::PARAM_TOKEN,
            'token' => $this->token(),
        ];
    }

    /**
     * A tamper-proof description of what the fragment endpoint should re-render.
     *
     * `hashData()` prefixes an HMAC keyed on the site's security key, so the endpoint can trust
     * the template path it is handed. Nothing secret is inside it — it is signed, not encrypted
     * — and it is only ever a template Caffeine was already rendering.
     */
    public function token(): string
    {
        return Craft::$app->getSecurity()->hashData(json_encode([
            'index' => $this->index->handle,
            'prefix' => $this->prefix,
            'path' => $this->path,
            'template' => $this->options['template'] ?? null,
            'element' => $this->options['element'] ?? null,
            'variables' => $this->options['with'] ?? [],
        ], JSON_UNESCAPED_SLASHES));
    }

    public function fragmentUrl(): string
    {
        // The registered site route rather than an action URL, so it reads as a normal path in a
        // network panel and can be cached or routed at the edge like any other GET.
        return \craft\helpers\UrlHelper::siteUrl('caffeine/fragment');
    }

    /** The DOM id shared by the results wrapper and everything that swaps it. */
    public function getResultsId(): string
    {
        return 'caffeine-results-' . ($this->prefix !== '' ? $this->prefix : '') . $this->index->handle;
    }

    public function captureResults(string $html): void
    {
        $this->renderedResults = $html;
        $this->captureRegion('results', $html);
    }

    public function captureRegion(string $name, string $html): void
    {
        $this->regions[$this->regionId($name)] = $html;
    }

    /** @return array<string, string> */
    public function getRegions(): array
    {
        return $this->regions;
    }

    /** Namespaced, so two indexes on one page cannot swap each other's markup. */
    public function regionId(string $name): string
    {
        return $this->prefix . $this->index->handle . ':' . $name;
    }

    public function getRenderedResults(): ?string
    {
        return $this->renderedResults;
    }

    // -----------------------------------------------------------------------------------------
    // Widgets
    //
    // Every one of these is a thin wrapper over the methods above. They exist so a listing can
    // be built in five lines, not so anyone is obliged to use them — the markup they emit is a
    // template like any other, and a site that wants its own overrides it by putting a template
    // of the same name under `_caffeine/` in its own templates directory.
    // -----------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $options
     */
    public function searchBox(array $options = []): Markup
    {
        return $this->widget('search-box', $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function refinementList(string $facet, array $options = []): Markup
    {
        return $this->widget('refinement-list', $options + ['facet' => $this->facet($facet)]);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function hierarchicalMenu(string $facet, array $options = []): Markup
    {
        $view = $this->facet($facet);

        return $this->widget('hierarchical-menu', $options + [
            'facet' => $view,
            'tree' => $view === null ? [] : $this->tree($facet, $view['buckets']),
        ]);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function rangeInput(string $facet, array $options = []): Markup
    {
        return $this->widget('range-input', $options + [
            'facet' => $this->facet($facet),
            'range' => $this->state->ranges[$facet] ?? [],
        ]);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function currentRefinements(array $options = []): Markup
    {
        return $this->widget('current-refinements', $options);
    }

    /**
     * @param list<string>|null $sortings Names to offer, in order. Null means every one defined.
     * @param array<string, mixed> $options
     */
    public function sortBy(?array $sortings = null, array $options = []): Markup
    {
        $available = [];

        foreach ($this->index->allSortings() as $sorting) {
            if ($sortings !== null && !in_array($sorting->name, $sortings, true)) {
                continue;
            }

            $available[$sorting->name] = $sorting;
        }

        // Honour the order the template asked for, not the order they happen to be defined in.
        if ($sortings !== null) {
            $ordered = [];

            foreach ($sortings as $name) {
                if (isset($available[$name])) {
                    $ordered[$name] = $available[$name];
                }
            }

            $available = $ordered;
        }

        return $this->widget('sort-by', $options + ['sortings' => $available]);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function stats(array $options = []): Markup
    {
        return $this->widget('stats', $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function pagination(array $options = []): Markup
    {
        $window = max(1, (int)($options['window'] ?? 5));
        $page = $this->getPage();
        $last = max(0, $this->getNbPages() - 1);

        $from = max(0, min($page - intdiv($window, 2), $last - $window + 1));
        $to = min($last, $from + $window - 1);

        $pages = [];

        for ($i = $from; $i <= $to; $i++) {
            $pages[] = ['number' => $i, 'label' => (string)($i + 1), 'url' => $this->pageUrl($i), 'isCurrent' => $i === $page];
        }

        return $this->widget('pagination', $options + [
            'pages' => $pages,
            'first' => $last > 0 ? $this->pageUrl(0) : null,
            'last' => $last > 0 ? $this->pageUrl($last) : null,
            'previous' => $page > 0 ? $this->pageUrl($page - 1) : null,
            'next' => $page < $last ? $this->pageUrl($page + 1) : null,
        ]);
    }

    /**
     * Renders a widget template, preferring the site's own copy when it has one.
     *
     * A site that wants different markup creates `_caffeine/refinement-list.twig` in its
     * templates directory and Caffeine uses it instead — no configuration, and no need to fork
     * the widget just to change a class name.
     *
     * @param array<string, mixed> $variables
     */
    public function widget(string $name, array $variables = []): Markup
    {
        $view = Craft::$app->getView();
        $override = '_caffeine/' . $name;

        $variables += ['search' => $this, 'options' => $variables];

        $html = $view->doesTemplateExist($override, View::TEMPLATE_MODE_SITE)
            ? $view->renderTemplate($override, $variables, View::TEMPLATE_MODE_SITE)
            : $view->renderTemplate('caffeine/_widgets/' . $name, $variables, View::TEMPLATE_MODE_CP);

        // A facet's key is part of the region name, so two refinement lists on one page are two
        // regions rather than one that overwrites the other.
        $region = $name . (isset($variables['facet']['key']) ? ':' . $variables['facet']['key'] : '');

        $this->captureRegion($region, $html);

        return new Markup(
            sprintf(
                '<div data-caffeine-region="%s">%s</div>',
                htmlspecialchars($this->regionId($region), ENT_QUOTES, 'UTF-8'),
                $html,
            ),
            'UTF-8',
        );
    }

    /**
     * Nests a hierarchical facet's flat buckets back into a tree.
     *
     * The artifact stores every ancestor path as an ordinary string value (QUERY_SPEC §4), which
     * is what lets both engines stay ignorant of hierarchy. A menu is the one place that needs
     * the shape back, so it is rebuilt here rather than carried in the artifact.
     *
     * @param list<array<string, mixed>> $buckets
     * @return list<array<string, mixed>>
     */
    private function tree(string $facet, array $buckets): array
    {
        $definition = $this->index->getFacet($facet);
        $separator = $definition !== null ? trim($definition->hierarchySeparator) : '>';

        $depth = [];

        foreach ($buckets as $bucket) {
            $parts = array_map('trim', explode($separator, (string)$bucket['value']));
            $depth[(string)$bucket['value']] = $bucket + [
                'depth' => count($parts) - 1,
                'parent' => count($parts) > 1
                    ? implode(' ' . $separator . ' ', array_slice($parts, 0, -1))
                    : null,
                'children' => [],
            ];
        }

        $roots = [];

        foreach ($depth as $path => $node) {
            if ($node['parent'] !== null && isset($depth[$node['parent']])) {
                continue;
            }

            $roots[] = $this->branch($path, $depth);
        }

        return $roots;
    }

    /**
     * @param array<string, array<string, mixed>> $nodes
     * @return array<string, mixed>
     */
    private function branch(string $path, array $nodes): array
    {
        $node = $nodes[$path];
        $children = [];

        foreach ($nodes as $candidate => $child) {
            if ($child['parent'] === $path) {
                $children[] = $this->branch($candidate, $nodes);
            }
        }

        $node['children'] = $children;

        return $node;
    }

    // -----------------------------------------------------------------------------------------

    private function cloneState(): QueryState
    {
        return QueryState::fromArray($this->state->toArray());
    }

    private function label(AttributeDefinition $definition, mixed $value): string
    {
        if ($definition->facetType === AttributeDefinition::FACET_BOOLEAN) {
            return $value
                ? Craft::t('caffeine', 'Yes')
                : Craft::t('caffeine', 'No');
        }

        if ($definition->facetType === AttributeDefinition::FACET_HIERARCHICAL) {
            // Only the leaf: a menu already shows the ancestors as the path the visitor took to
            // get there, and repeating them makes every label unreadably long.
            $parts = explode(trim($definition->hierarchySeparator), (string)$value);

            return trim((string)end($parts));
        }

        if ($definition->facetType === AttributeDefinition::FACET_DATE && is_numeric($value)) {
            return Craft::$app->getFormatter()->asDate(
                (new \DateTime())->setTimestamp((int)$value),
                'medium',
            );
        }

        return FacetValue::toKey($value);
    }

    private function distanceLabel(float $metres): string
    {
        return $metres >= 1000
            ? Craft::t('caffeine', '{km} km', ['km' => FacetValue::toKey(round($metres / 1000, 1))])
            : Craft::t('caffeine', '{m} m', ['m' => FacetValue::toKey(round($metres))]);
    }

    /**
     * @param array{min?: float, max?: float} $range
     */
    private function rangeLabel(array $range): string
    {
        $min = $range['min'] ?? null;
        $max = $range['max'] ?? null;

        if ($min !== null && $max !== null) {
            return FacetValue::toKey($min) . ' – ' . FacetValue::toKey($max);
        }

        return $min !== null
            ? Craft::t('caffeine', '{min} and up', ['min' => FacetValue::toKey($min)])
            : Craft::t('caffeine', 'Up to {max}', ['max' => FacetValue::toKey($max)]);
    }
}
