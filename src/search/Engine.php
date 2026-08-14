<?php

namespace justinholtweb\caffeine\search;

/**
 * The PHP query engine.
 *
 * Implements docs/QUERY_SPEC.md. Its twin, `src/web/assets/runtime/src/engine.js`, implements
 * the same document, and `tests/Conformance/*.json` is run against both. When changing
 * anything in here, change the spec first and the fixtures second — a behaviour that exists
 * only in one engine is a bug that shows up as a page rearranging itself under the visitor.
 *
 * Sets of records are represented as `id => true` maps rather than lists, because every
 * operation here is membership-testing and intersection. The JS engine uses `Set` for the same
 * reason.
 */
class Engine
{
    /** The one sorting that cannot be precomputed: the point it measures from is the visitor's. */
    public const SORT_DISTANCE = 'distance';

    public function __construct(
        private readonly Artifact $artifact,
    ) {
    }

    /**
     * @return array<string, mixed> The Algolia-shaped response described in QUERY_SPEC §9.
     */
    public function search(QueryState $state, int $defaultHitsPerPage = 24): array
    {
        $startedAt = microtime(true);

        $hitsPerPage = max(1, $state->hitsPerPage ?? $defaultHitsPerPage);

        // §3.1 — text matching.
        [$candidates, $scores] = $this->match($state->query);

        // §3.2 — filtering. Each facet's filter is kept separately, because facet counting
        // needs to rebuild the intersection while leaving one facet out.
        $filters = $this->buildFilters($state);
        $result = $this->intersectAll($candidates, $filters);

        // §3.3 — counting.
        $facetKeys = $state->facets ?? array_keys($this->artifact->facets);
        [$facets, $caffeineFacets, $stats] = $this->countFacets($state, $facetKeys, $candidates, $filters, $result);

        // §3.4 — sorting.
        $ordered = $this->sort(
            $result,
            $scores,
            $state->sortBy,
            $state->query !== '',
            $state->sortBy === self::SORT_DISTANCE ? $this->distances($state) : [],
        );

        // §3.5 — pagination.
        $nbHits = count($ordered);
        $nbPages = (int)ceil($nbHits / $hitsPerPage);
        $page = $nbPages > 0 ? min($state->page, $nbPages - 1) : 0;
        $slice = array_slice($ordered, $page * $hitsPerPage, $hitsPerPage);

        return [
            'hits' => array_map($this->hit(...), $slice),
            'nbHits' => $nbHits,
            'page' => $page,
            'nbPages' => $nbPages,
            'hitsPerPage' => $hitsPerPage,
            'query' => $state->query,
            'params' => http_build_query($state->toArray()),
            'processingTimeMS' => (int)round((microtime(true) - $startedAt) * 1000),
            'exhaustiveNbHits' => true,
            'exhaustiveFacetsCount' => true,
            'facets' => $facets,
            'facets_stats' => $stats,
            'caffeineFacets' => $caffeineFacets,
        ];
    }

    /**
     * §3.1 — the records matching the text query, and their scores.
     *
     * @return array{0: array<int, true>, 1: array<int, float>}
     */
    private function match(string $query): array
    {
        $tokens = $this->queryTokens($query);

        if ($tokens === []) {
            $all = [];

            for ($i = 0, $n = $this->artifact->recordCount(); $i < $n; $i++) {
                $all[$i] = true;
            }

            return [$all, []];
        }

        $matched = null;
        $scores = [];
        $last = count($tokens) - 1;

        foreach ($tokens as $i => $token) {
            // Only the final token is prefix-matched: the visitor is still typing it.
            $postings = $i === $last
                ? $this->prefixPostings($token)
                : $this->exactPostings($token);

            if ($postings === []) {
                // Conjunctive matching — one token with no matches means no results at all.
                return [[], []];
            }

            $current = [];

            foreach ($postings as $id => $weight) {
                $current[$id] = true;
                $scores[$id] = ($scores[$id] ?? 0.0) + $weight;
            }

            $matched = $matched === null ? $current : array_intersect_key($matched, $current);

            if ($matched === []) {
                return [[], []];
            }
        }

        // Scores accumulated for records that failed a later token have to go, or a record
        // that matched two of three tokens would carry a score it never earned.
        return [$matched, array_intersect_key($scores, $matched)];
    }

    /**
     * @return array<int, float> internal id → weight
     */
    private function exactPostings(string $token): array
    {
        $index = $this->tokenIndex($token);

        if ($index === null) {
            return [];
        }

        $weights = [];

        foreach ($this->artifact->tokenPostings[$index] ?? [] as [$id, $weight]) {
            $weights[$id] = (float)$weight;
        }

        return $weights;
    }

    /**
     * Every record carrying a token starting with this prefix, taking the highest weight where
     * several tokens match (QUERY_SPEC §3.1).
     *
     * @return array<int, float>
     */
    private function prefixPostings(string $prefix): array
    {
        $weights = [];
        $tokens = $this->artifact->tokens;
        $count = count($tokens);

        // Binary search for the first token at or after the prefix, then walk while it holds.
        // This is the reason the artifact stores its token list sorted.
        $low = 0;
        $high = $count;

        while ($low < $high) {
            $mid = intdiv($low + $high, 2);

            if (strcmp($tokens[$mid], $prefix) < 0) {
                $low = $mid + 1;
            } else {
                $high = $mid;
            }
        }

        for ($i = $low; $i < $count; $i++) {
            if (!str_starts_with($tokens[$i], $prefix)) {
                break;
            }

            foreach ($this->artifact->tokenPostings[$i] ?? [] as [$id, $weight]) {
                $weight = (float)$weight;

                if (!isset($weights[$id]) || $weight > $weights[$id]) {
                    $weights[$id] = $weight;
                }
            }
        }

        return $weights;
    }

    /**
     * §7 — the query's tokens, with the index's stopwords removed.
     *
     * Dropped here as well as at index time, and that symmetry is the whole point. Matching is
     * conjunctive, so a token with no postings empties the result: if "the" were removed from
     * documents but left in the query, searching "the saw" would find nothing at all. A query
     * that is *entirely* stopwords is treated as no query rather than as no matches.
     *
     * @return list<string>
     */
    private function queryTokens(string $query): array
    {
        $tokens = Tokenizer::tokenize($query);

        if ($this->artifact->stopwords === []) {
            return $tokens;
        }

        $stopwords = array_fill_keys($this->artifact->stopwords, true);

        return array_values(array_filter($tokens, fn(string $token) => !isset($stopwords[$token])));
    }

    /**
     * §10 — distance from each `around` point to every record with coordinates.
     *
     * Computed once per query rather than inside the comparator: a sort does O(n log n)
     * comparisons and a haversine is not cheap enough to run that many times.
     *
     * @return array<int, int> internal id → metres
     */
    private function distances(QueryState $state): array
    {
        $distances = [];

        foreach ($state->around as $key => $around) {
            $points = $this->artifact->geo[$key] ?? null;

            if ($points === null) {
                continue;
            }

            foreach ($points as $id => $point) {
                if (!Geo::isValid($point)) {
                    continue;
                }

                $metres = Geo::distance($around['lat'], $around['lng'], $point[0], $point[1]);

                // With two geo facets in play, nearest means nearest to either.
                if (!isset($distances[$id]) || $metres < $distances[$id]) {
                    $distances[$id] = $metres;
                }
            }
        }

        return $distances;
    }

    private function tokenIndex(string $token): ?int
    {
        $tokens = $this->artifact->tokens;
        $low = 0;
        $high = count($tokens) - 1;

        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);
            $cmp = strcmp($tokens[$mid], $token);

            if ($cmp === 0) {
                return $mid;
            }

            if ($cmp < 0) {
                $low = $mid + 1;
            } else {
                $high = $mid - 1;
            }
        }

        return null;
    }

    /**
     * §3.2 — one predicate set per refined facet, kept separate for counting.
     *
     * @return array<string, array<int, true>>
     */
    private function buildFilters(QueryState $state): array
    {
        $filters = [];

        foreach ($state->refinements as $key => $values) {
            if ($values === [] || !isset($this->artifact->facets[$key])) {
                continue;
            }

            $facet = $this->artifact->facets[$key];
            $sets = [];

            foreach ($values as $value) {
                $index = $this->valueIndex($key, $value);
                $sets[] = $index === null ? [] : array_fill_keys($facet['postings'][$index] ?? [], true);
            }

            $filters[$key] = $facet['operator'] === 'and'
                ? $this->intersectSets($sets)
                : $this->unionSets($sets);
        }

        foreach ($state->ranges as $key => $range) {
            if (!isset($this->artifact->facets[$key])) {
                continue;
            }

            $filters[$key] = $this->rangeSet($key, $range);
        }

        // §10 — a geo facet filters by distance rather than by equality, so it has no postings
        // to intersect and the predicate is built by walking the coordinates. A radius of zero
        // filters nothing: it only turns on the distance sorting.
        foreach ($state->around as $key => $around) {
            $points = $this->artifact->geo[$key] ?? null;
            $radius = (float)($around['radius'] ?? 0);

            if ($points === null || $radius <= 0) {
                continue;
            }

            $set = [];

            foreach ($points as $id => $point) {
                if (!Geo::isValid($point)) {
                    continue;
                }

                if (Geo::distance($around['lat'], $around['lng'], $point[0], $point[1]) <= $radius) {
                    $set[$id] = true;
                }
            }

            $filters[$key] = $set;
        }

        return $filters;
    }

    /**
     * Records with at least one value inside an inclusive range.
     *
     * Scanning the value dictionary rather than the records: the dictionary holds only distinct
     * values, so it is invariably the smaller of the two.
     *
     * @param array{min?: float, max?: float} $range
     * @return array<int, true>
     */
    private function rangeSet(string $key, array $range): array
    {
        $facet = $this->artifact->facets[$key];
        $set = [];

        foreach ($facet['values'] as $index => $value) {
            if (!is_int($value) && !is_float($value)) {
                continue;
            }

            if (isset($range['min']) && $value < $range['min']) {
                continue;
            }

            if (isset($range['max']) && $value > $range['max']) {
                continue;
            }

            foreach ($facet['postings'][$index] ?? [] as $id) {
                $set[$id] = true;
            }
        }

        return $set;
    }

    private function valueIndex(string $key, mixed $value): ?int
    {
        foreach ($this->artifact->facets[$key]['values'] as $index => $candidate) {
            if ($candidate === $value) {
                return $index;
            }
        }

        // A refinement can name a value that no longer exists — a bookmarked URL after the
        // content changed. Not an error: it simply matches nothing.
        return null;
    }

    /**
     * §3.3 — facet counts, with the disjunctive/conjunctive asymmetry that makes each kind of
     * facet behave the way a visitor expects.
     *
     * @param list<string> $facetKeys
     * @param array<int, true> $candidates
     * @param array<string, array<int, true>> $filters
     * @param array<int, true> $result
     * @return array{0: array<string, array<string, int>>, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function countFacets(QueryState $state, array $facetKeys, array $candidates, array $filters, array $result): array
    {
        $facets = [];
        $caffeine = [];
        $stats = [];

        foreach ($facetKeys as $key) {
            if (!isset($this->artifact->facets[$key])) {
                continue;
            }

            $facet = $this->artifact->facets[$key];
            $disjunctive = $facet['operator'] !== 'and';

            // The whole point of §3.3: a disjunctive facet is counted with its *own*
            // refinements left out, so "Globex (12)" still shows the count the visitor would
            // get by adding it. A conjunctive facet counts against everything, including
            // itself, because each tick is meant to narrow.
            $base = $disjunctive
                ? $this->intersectAll($candidates, $filters, $key)
                : $result;

            $counts = [];
            $values = $facet['values'];
            $records = $facet['records'];

            // Walking the base set and reading each record's values, rather than walking every
            // value and intersecting its postings: the base set is nearly always the smaller.
            foreach ($base as $id => $_) {
                foreach ($records[$id] ?? [] as $valueIndex) {
                    $counts[$valueIndex] = ($counts[$valueIndex] ?? 0) + 1;
                }
            }

            $buckets = $this->buildBuckets($state, $key, $facet, $counts);

            $flat = [];

            foreach ($buckets as $bucket) {
                $flat[FacetValue::toKey($bucket['value'])] = $bucket['count'];
            }

            if ($flat !== []) {
                $facets[$key] = $flat;
            }

            $caffeine[$key] = [
                'operator' => $disjunctive ? 'or' : 'and',
                'type' => $facet['type'],
                'buckets' => $buckets,
            ];

            if (in_array($facet['type'], ['numeric', 'date'], true)) {
                $stat = $this->facetStats($values, $counts);

                if ($stat !== null) {
                    $stats[$key] = $stat;
                }
            }
        }

        return [$facets, $caffeine, $stats];
    }

    /**
     * §3.3 — which values appear, and in what order.
     *
     * @param array{type: string, operator: string, values: list<mixed>, postings: list<list<int>>, records: list<list<int>>} $facet
     * @param array<int, int> $counts value index → count
     * @return list<array{value: mixed, count: int, isRefined: bool}>
     */
    private function buildBuckets(QueryState $state, string $key, array $facet, array $counts): array
    {
        $buckets = [];

        foreach ($facet['values'] as $index => $value) {
            $count = $counts[$index] ?? 0;
            $isRefined = $state->isRefined($key, $value);

            // A refined value stays visible at zero — otherwise the control the visitor used to
            // refine disappears and they have no way to undo it.
            if ($count === 0 && !$isRefined) {
                continue;
            }

            $buckets[] = ['value' => $value, 'count' => $count, 'isRefined' => $isRefined];
        }

        $sort = $facet['sort'] ?? 'count';
        $order = $facet['valueOrder'] ?? [];

        usort($buckets, function(array $a, array $b) use ($sort, $order) {
            if ($sort === 'alpha') {
                return Comparator::compare($a['value'], $b['value']);
            }

            if ($sort === 'manual') {
                $ai = array_search($a['value'], $order, true);
                $bi = array_search($b['value'], $order, true);

                if ($ai !== false || $bi !== false) {
                    // Listed values first, in the order given; unlisted fall through to count.
                    if ($ai === false) {
                        return 1;
                    }

                    if ($bi === false) {
                        return -1;
                    }

                    return $ai <=> $bi;
                }
            }

            return $b['count'] <=> $a['count']
                ?: Comparator::compare($a['value'], $b['value']);
        });

        $limit = (int)($facet['maxValues'] ?? 20);

        if (count($buckets) <= $limit) {
            return $buckets;
        }

        // Truncation must never drop a refined value, or the visitor loses the control they
        // used. Refined values are kept regardless of where they landed in the order.
        $kept = [];
        $refined = [];

        foreach ($buckets as $bucket) {
            if ($bucket['isRefined']) {
                $refined[] = $bucket;
            }
        }

        foreach ($buckets as $bucket) {
            if (count($kept) >= $limit) {
                break;
            }

            if (!$bucket['isRefined']) {
                $kept[] = $bucket;
            }
        }

        // Re-merge in the original order rather than appending, so the sort still holds.
        $keep = [];

        foreach ($buckets as $bucket) {
            if (in_array($bucket, $refined, true) || in_array($bucket, $kept, true)) {
                $keep[] = $bucket;
            }
        }

        return $keep;
    }

    /**
     * §5 — stats over every value carried by records in the base set, counting a record once
     * per value it holds.
     *
     * @param list<mixed> $values
     * @param array<int, int> $counts
     * @return array{min: float, max: float, avg: float, sum: float}|null
     */
    private function facetStats(array $values, array $counts): ?array
    {
        $min = null;
        $max = null;
        $sum = 0.0;
        $n = 0;

        foreach ($counts as $index => $count) {
            $value = $values[$index] ?? null;

            if (!is_int($value) && !is_float($value)) {
                continue;
            }

            $value = (float)$value;
            $min = $min === null ? $value : min($min, $value);
            $max = $max === null ? $value : max($max, $value);
            $sum += $value * $count;
            $n += $count;
        }

        if ($n === 0 || $min === null || $max === null) {
            return null;
        }

        return ['min' => $min, 'max' => $max, 'avg' => $sum / $n, 'sum' => $sum];
    }

    /**
     * §3.4 — ordering.
     *
     * @param array<int, true> $result
     * @param array<int, float> $scores
     * @return list<int>
     */
    private function sort(array $result, array $scores, string $sortBy, bool $hasQuery, array $distances = []): array
    {
        // §10 — nearest first. Never precomputed like the other sortings, because the point it is
        // measured from is chosen by the visitor, not by the index.
        if ($sortBy === self::SORT_DISTANCE && $distances !== []) {
            $ids = array_keys($result);

            usort($ids, function(int $a, int $b) use ($distances) {
                return ($distances[$a] ?? PHP_INT_MAX) <=> ($distances[$b] ?? PHP_INT_MAX)
                    ?: Comparator::compare($this->artifact->objectIds[$a], $this->artifact->objectIds[$b]);
            });

            return $ids;
        }

        $sorting = $this->artifact->sortings[$sortBy] ?? null;

        // With no text query every score is 0, so the score tie-break is a no-op and the
        // precomputed order is already exactly right — filter it and take. This is the common
        // case for a filtered listing, and it avoids sorting entirely.
        if (!$hasQuery && $sorting !== null) {
            $ordered = [];

            foreach ($sorting as $id) {
                if (isset($result[$id])) {
                    $ordered[] = $id;
                }
            }

            return $ordered;
        }

        $ids = array_keys($result);

        if ($sortBy === 'relevance' || $sorting === null) {
            usort($ids, function(int $a, int $b) use ($scores) {
                return ($scores[$b] ?? 0.0) <=> ($scores[$a] ?? 0.0)
                    ?: Comparator::compare($this->artifact->objectIds[$a], $this->artifact->objectIds[$b]);
            });

            return $ids;
        }

        // A named sorting with a query active: the precomputed order cannot be used, because
        // text score sits between the sort key and the tie-break. Position in the precomputed
        // order is still the cheapest way to recover the sort key's ordering, including its
        // direction and its null handling, without re-reading values.
        $position = [];

        foreach ($sorting as $rank => $id) {
            $position[$id] = $rank;
        }

        $attribute = $this->artifact->sortableValues[$sortBy] ?? null;

        usort($ids, function(int $a, int $b) use ($scores, $position, $attribute) {
            if ($attribute !== null) {
                $av = $attribute[$a] ?? null;
                $bv = $attribute[$b] ?? null;

                // Equal sort keys fall through to score; unequal ones keep the precomputed
                // order, which already encodes direction and nulls-last.
                if (!$this->sameValue($av, $bv)) {
                    return ($position[$a] ?? PHP_INT_MAX) <=> ($position[$b] ?? PHP_INT_MAX);
                }
            } elseif (($position[$a] ?? PHP_INT_MAX) !== ($position[$b] ?? PHP_INT_MAX)) {
                return ($position[$a] ?? PHP_INT_MAX) <=> ($position[$b] ?? PHP_INT_MAX);
            }

            return ($scores[$b] ?? 0.0) <=> ($scores[$a] ?? 0.0)
                ?: Comparator::compare($this->artifact->objectIds[$a], $this->artifact->objectIds[$b]);
        });

        return $ids;
    }

    private function sameValue(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        return Comparator::compare($a, $b) === 0;
    }

    /**
     * @param array<int, true> $candidates
     * @param array<string, array<int, true>> $filters
     * @return array<int, true>
     */
    private function intersectAll(array $candidates, array $filters, ?string $except = null): array
    {
        $set = $candidates;

        foreach ($filters as $key => $filter) {
            if ($key === $except) {
                continue;
            }

            $set = array_intersect_key($set, $filter);

            if ($set === []) {
                return [];
            }
        }

        return $set;
    }

    /**
     * @param list<array<int, true>> $sets
     * @return array<int, true>
     */
    private function unionSets(array $sets): array
    {
        $union = [];

        foreach ($sets as $set) {
            $union += $set;
        }

        return $union;
    }

    /**
     * @param list<array<int, true>> $sets
     * @return array<int, true>
     */
    private function intersectSets(array $sets): array
    {
        if ($sets === []) {
            return [];
        }

        $result = array_shift($sets);

        foreach ($sets as $set) {
            $result = array_intersect_key($result, $set);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function hit(int $id): array
    {
        $payload = $this->artifact->payloads[$id] ?? [];
        $payload['objectID'] = $this->artifact->objectIds[$id];

        return $payload;
    }
}
