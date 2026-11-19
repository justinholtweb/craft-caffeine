<?php

namespace JustinHoltWeb\Caffeine\Services;

use CraftCms\Cms\Cms;
use CraftCms\Cms\Support\Url;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use JustinHoltWeb\Caffeine\Models\IndexDefinition;
use JustinHoltWeb\Caffeine\Models\SearchContext;
use JustinHoltWeb\Caffeine\Search\Artifact;
use JustinHoltWeb\Caffeine\Search\Engine;
use JustinHoltWeb\Caffeine\Search\FacetValue;
use JustinHoltWeb\Caffeine\Search\QueryState;
use JustinHoltWeb\Caffeine\Search\UrlState;

/**
 * Runs a search for the front end.
 *
 * The entire request path is: read the published artifact, parse the query string, run the
 * engine. No element query, no database read beyond Craft's own bootstrap. That is the promise
 * the plugin is built on, and this is the only place on the front end that could break it.
 */
class Search
{
    /**
     * @var array<string, Artifact|false> Per-request, keyed by index handle.
     *
     * Decoding a large artifact is not free, and a page with two indexes, or a fragment request
     * that re-renders a template touching the same index twice, would otherwise pay for it more
     * than once. Across requests, `ArtifactCache` keeps it in OPcache when that is turned on.
     */
    private array $artifacts = [];

    /** @var array<string, SearchContext> Contexts built this request, for the fragment endpoint. */
    private array $contexts = [];

    /**
     * Set by the fragment endpoint before it re-renders.
     *
     * Without it every URL in the re-rendered block would point at `/caffeine/fragment` — the
     * endpoint's own path — instead of the page the visitor is actually on, and the first click
     * after a refinement would navigate them into a bare HTML fragment.
     */
    private ?string $pathOverride = null;

    public function overridePath(?string $path): void
    {
        $this->pathOverride = $path;
    }

    /**
     * Builds the context a `{% caffeine %}` block exposes.
     *
     * @param array{state?: QueryState|array<string, mixed>|null, path?: string, prefix?: string, params?: array<string, mixed>, filters?: array<string, mixed>} $options
     */
    public function context(string $handle, array $options = []): SearchContext
    {
        $indexes = app(Indexes::class);
        $index = $indexes->getByHandle($handle);

        if ($index === null) {
            throw new InvalidArgumentException("No Caffeine index with the handle “{$handle}”.");
        }

        if (!$indexes->isAllowed($index)) {
            throw new InvalidArgumentException(
                "The Caffeine index “{$handle}” is not available on this edition. Upgrade to Pro to use more than one index."
            );
        }

        // The same downgrade the build applies, so a Lite site queries exactly what it built.
        $index = $indexes->forEdition($index);

        $artifact = $this->artifact($index);
        $prefix = (string)($options['prefix'] ?? '');
        $path = (string)($options['path'] ?? $this->currentPath());

        // Cloned, so filling in the filters never reaches a state object the caller passed in.
        $state = clone $this->resolveState($index, $artifact, $options, $prefix);
        $state->filters = $this->resolveFilters($artifact, (array)($options['filters'] ?? []));

        $result = (new Engine($artifact))->search($state, $index->hitsPerPage);

        $context = new SearchContext(
            index: $index,
            artifact: $artifact,
            state: $state,
            result: $result,
            path: $path,
            prefix: $prefix,
            version: $artifact->version,
            options: $options,
        );

        $this->contexts[$this->contextKey($handle, $prefix)] = $context;

        return $context;
    }

    /**
     * A context already built this request, which is how the fragment endpoint gets hold of the
     * one the template just rendered rather than building a second one.
     */
    public function registered(string $handle, string $prefix = ''): ?SearchContext
    {
        return $this->contexts[$this->contextKey($handle, $prefix)] ?? null;
    }

    /**
     * The published artifact for an index.
     *
     * Refuses rather than falling back to compiling from records on the fly: that would turn one
     * forgotten deploy step into a page that works in staging and takes three seconds in
     * production. In dev the exception says exactly which command to run; in production the page
     * renders empty and the reason goes to the log, because a 500 on a listing page is worse
     * than an empty one.
     */
    public function artifact(IndexDefinition $index): Artifact
    {
        $handle = $index->handle;

        if (isset($this->artifacts[$handle])) {
            if ($this->artifacts[$handle] === false) {
                return $this->emptyArtifact($index);
            }

            return $this->artifacts[$handle];
        }

        $artifact = app(Artifacts::class)->published($index);

        if ($artifact === null) {
            $this->artifacts[$handle] = false;

            $message = sprintf(
                'Caffeine has nothing published for “%s”. Run `php artisan caffeine:index:build --all` then `php artisan caffeine:artifact:publish %s`.',
                $handle,
                $handle,
            );

            Log::channel('caffeine')->error($message);

            if (Cms::config()->devMode) {
                throw new InvalidArgumentException($message);
            }

            return $this->emptyArtifact($index);
        }

        return $this->artifacts[$handle] = $artifact;
    }

    /**
     * Resolves what the visitor asked for.
     *
     * An explicitly passed state wins — that is the fragment endpoint handing over what the
     * runtime posted. Otherwise it comes from the query string, which is what a plain page load
     * and a no-JavaScript facet click both look like.
     *
     * @param array<string, mixed> $options
     */
    public function resolveState(IndexDefinition $index, Artifact $artifact, array $options, string $prefix = ''): QueryState
    {
        $given = $options['state'] ?? null;

        if ($given instanceof QueryState) {
            return $given;
        }

        if (is_array($given)) {
            return QueryState::fromArray($given);
        }

        $params = $options['params'] ?? $this->requestParams();

        return UrlState::parse((array)$params, $artifact->facets, [
            'prefix' => $prefix,
            'defaultSort' => $index->defaultSorting()->name,
        ]);
    }

    /**
     * Resolves the tag's `filters` option against the artifact's stored values.
     *
     * Not taken from the request, and not carried in the fragment token either: the endpoint
     * re-renders the same template, which passes the same filters again, so there is nothing in
     * the query string a visitor could edit to strip them.
     *
     * @param array<string, mixed> $filters facet key → value or list of values
     * @return array<string, list<string|bool|float>>
     */
    private function resolveFilters(Artifact $artifact, array $filters): array
    {
        $resolved = [];

        foreach ($filters as $key => $values) {
            $facet = $artifact->facets[$key] ?? null;

            if ($facet === null) {
                continue;
            }

            foreach ((array)$values as $value) {
                if ($value === null || $value === '') {
                    continue;
                }

                // Through the key and back, so `'10'` and `10` both land on whatever the artifact
                // actually stores — the engine compares strictly.
                $resolved[(string)$key][] = FacetValue::fromKey(FacetValue::toKey($value), $facet['values']);
            }
        }

        return $resolved;
    }

    /**
     * Query parameters from the request, where there is one.
     *
     * A console run has no query string worth reading — which is the case whenever a template is
     * rendered outside a web request: a console command, a queue job.
     *
     * @return array<string, mixed>
     */
    private function requestParams(): array
    {
        if (app()->runningInConsole()) {
            return [];
        }

        return (array)request()->query();
    }

    /**
     * The path facet links point back at.
     *
     * Deliberately without the query string: every URL the context builds rewrites it from the
     * state, so carrying the old one through would double up parameters.
     */
    private function currentPath(): string
    {
        if ($this->pathOverride !== null) {
            return $this->pathOverride;
        }

        if (app()->runningInConsole()) {
            return '/';
        }

        // Laravel's `path()` is `/` for the root and otherwise has no leading slash, which is the
        // shape Craft 5's `getPathInfo()` had.
        $path = request()->path();

        return Url::url($path === '/' ? '' : $path);
    }

    /**
     * A valid artifact with nothing in it, so a page whose index has never been published still
     * renders its markup — empty results, empty facets — rather than throwing in production.
     */
    private function emptyArtifact(IndexDefinition $index): Artifact
    {
        $facets = [];

        foreach ($index->facets() as $facet) {
            $facets[$facet->key] = [
                'type' => $facet->facetType,
                'operator' => $facet->isDisjunctive() ? 'or' : 'and',
                'sort' => $facet->facetSort,
                'valueOrder' => $facet->facetValueOrder,
                'maxValues' => $facet->maxValuesPerFacet ?: $index->maxValuesPerFacet,
                'values' => [],
                'postings' => [],
            ];
        }

        return new Artifact(
            index: $index->handle,
            version: 0,
            objectIds: [],
            payloads: [],
            facets: $facets,
            sortings: [],
            tokens: [],
        );
    }

    private function contextKey(string $handle, string $prefix): string
    {
        return $prefix . '|' . $handle;
    }
}
