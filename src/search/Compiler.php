<?php

namespace justinholtweb\caffeine\search;

use justinholtweb\caffeine\models\AttributeDefinition;
use justinholtweb\caffeine\models\IndexDefinition;
use justinholtweb\caffeine\models\SortingDefinition;
use justinholtweb\caffeine\services\Tokenizer as TokenizerService;

/**
 * Turns stored records into a compiled `Artifact`.
 *
 * Pure: it takes plain record arrays in and gives a plain structure out, with no database and
 * no Craft. That keeps it testable without an application, and means the same code compiles a
 * live index and a fixture.
 */
class Compiler
{
    /**
     * Decimal places token weights are rounded to at compile time.
     *
     * Not a cosmetic choice. The wire format stores weights as `round(weight * 1000)` varints,
     * and the whole design leans on `decode(encode(a))` being *exactly* `a` — the PHP engine
     * reads a freshly compiled artifact while the browser reads a decoded one, so any value the
     * codec cannot round-trip is a place the two engines would silently disagree. Rounding here,
     * once, means the quantised value is the only value either engine ever sees. Three places is
     * far finer than any real `searchWeight` distinction.
     */
    public const WEIGHT_PRECISION = 3;

    /**
     * @param iterable<array{elementId: int, siteId: int, objectId: string, content: array, tokens: array}> $records
     */
    public function compile(IndexDefinition $index, iterable $records, int $version = 1): Artifact
    {
        $objectIds = [];
        $payloads = [];
        $sortableValues = [];

        // facetKey → value → valueIndex, for interning.
        $valueIndexes = [];
        $facetValues = [];
        $facetPostings = [];
        $facetRecords = [];

        $tokenIndexes = [];
        $tokenPostings = [];

        foreach ($index->facets() as $facet) {
            if ($facet->facetType === AttributeDefinition::FACET_GEO) {
                continue;
            }

            $valueIndexes[$facet->key] = [];
            $facetValues[$facet->key] = [];
            $facetPostings[$facet->key] = [];
            $facetRecords[$facet->key] = [];
        }

        foreach ($index->attributes as $attribute) {
            if ($attribute->isSortable()) {
                $sortableValues[$attribute->key] = [];
            }
        }

        // Both hoisted out of the record loop. `facets()` filters the attribute list on every
        // call, and — the one that actually mattered — iterating `$sortableValues` while writing
        // into it made PHP snapshot the whole nested array once per record, turning the build
        // quadratic. It cost 60s at 100,000 records and was invisible at 10,000.
        // Geo facets are filtered by distance, never by equality, so they never enter the
        // interning-and-postings machinery — they would produce one value per record and a
        // postings list with a single id in each.
        $facetDefinitions = array_values(array_filter(
            $index->facets(),
            fn(AttributeDefinition $facet) => $facet->facetType !== AttributeDefinition::FACET_GEO,
        ));

        $geoDefinitions = array_values(array_filter(
            $index->facets(),
            fn(AttributeDefinition $facet) => $facet->facetType === AttributeDefinition::FACET_GEO,
        ));

        $geo = [];

        foreach ($geoDefinitions as $facet) {
            $geo[$facet->key] = [];
        }
        $sortableKeys = array_keys($sortableValues);

        $id = 0;

        foreach ($records as $record) {
            $objectIds[$id] = $record['objectId'];
            $payloads[$id] = (array)($record['content']['payload'] ?? []);

            foreach ($sortableKeys as $key) {
                $value = $record['content']['sortable'][$key] ?? null;
                $sortableValues[$key][$id] = is_numeric($value) && !is_string($value) ? (float)$value : $value;
            }

            foreach ($geoDefinitions as $facet) {
                $point = $record['content']['geo'][$facet->key] ?? null;

                // Dense, so a lookup is by position like everything else. Records with no
                // coordinates hold null and are simply never within any radius.
                $geo[$facet->key][$id] = Geo::isValid($point)
                    ? [(float)$point[0], (float)$point[1]]
                    : null;
            }

            foreach ($facetDefinitions as $facet) {
                $key = $facet->key;
                $values = (array)($record['content']['facets'][$key] ?? []);

                if ($facet->facetType === AttributeDefinition::FACET_HIERARCHICAL) {
                    // QUERY_SPEC §4: ancestors are expanded here, at build time, so a
                    // hierarchical facet is an ordinary string facet by the time either engine
                    // sees it and neither needs to know about paths.
                    $values = $this->expandHierarchy($values, $facet->hierarchySeparator);
                }

                $indexes = [];

                foreach ($values as $value) {
                    $lookup = $this->lookupKey($value);

                    if (!isset($valueIndexes[$key][$lookup])) {
                        $valueIndexes[$key][$lookup] = count($facetValues[$key]);
                        $facetValues[$key][] = $value;
                        $facetPostings[$key][] = [];
                    }

                    $valueIndex = $valueIndexes[$key][$lookup];

                    // Records arrive in ascending id order, so appending keeps each postings
                    // list sorted — which is what lets it delta-encode. The guard is for a
                    // record carrying the same value twice (a relation field pointing at two
                    // entries with the same category); the duplicate id would encode as a zero
                    // delta and inflate nothing, but it is dead weight in every artifact.
                    $last = $facetPostings[$key][$valueIndex][count($facetPostings[$key][$valueIndex]) - 1] ?? null;

                    if ($last !== $id) {
                        $facetPostings[$key][$valueIndex][] = $id;
                    }

                    $indexes[] = $valueIndex;
                }

                // Sorted as well as deduplicated, so this list delta-encodes too. Counting
                // walks it without caring about order, so this costs nothing and halves the
                // bytes the reverse map takes up.
                $indexes = array_values(array_unique($indexes));
                sort($indexes, SORT_NUMERIC);

                $facetRecords[$key][$id] = $indexes;
            }

            foreach ((array)$record['tokens'] as $token => $weight) {
                $token = (string)$token;

                if (!isset($tokenIndexes[$token])) {
                    $tokenIndexes[$token] = true;
                }

                $tokenPostings[$token][] = [$id, round((float)$weight, self::WEIGHT_PRECISION)];
            }

            $id++;
        }

        // Tokens are sorted so a prefix match is a binary search followed by a walk — the
        // reason the browser needs no search library (QUERY_SPEC §3.1).
        $tokens = array_keys($tokenIndexes);
        sort($tokens, SORT_STRING);

        $orderedPostings = [];

        foreach ($tokens as $token) {
            $orderedPostings[] = $tokenPostings[$token];
        }

        $facets = [];

        foreach ($facetDefinitions as $facet) {
            $key = $facet->key;

            $facets[$key] = [
                'type' => $facet->facetType,
                'operator' => $facet->isDisjunctive() ? 'or' : 'and',
                'sort' => $facet->facetSort,
                'valueOrder' => $facet->facetValueOrder,
                'maxValues' => $facet->maxValuesPerFacet ?: $index->maxValuesPerFacet,
                'values' => $facetValues[$key],
                'postings' => $facetPostings[$key],
                'records' => $facetRecords[$key],
            ];
        }

        return new Artifact(
            index: $index->handle,
            version: $version,
            objectIds: $objectIds,
            payloads: $payloads,
            facets: $facets,
            sortings: $this->buildSortings($index, $objectIds, $sortableValues),
            tokens: $tokens,
            tokenPostings: $orderedPostings,
            sortableValues: $sortableValues,
            // Normalised through the tokeniser, so the list the engines match against is in the
            // same shape as the tokens they are matching.
            stopwords: array_keys(TokenizerService::stopwords($index)),
            geo: $geo,
        );
    }

    /**
     * Expands `Home > Tools > Saws` into all three of its ancestor paths.
     *
     * @param list<mixed> $values
     * @return list<string>
     */
    private function expandHierarchy(array $values, string $separator): array
    {
        $expanded = [];

        foreach ($values as $value) {
            $parts = array_map('trim', explode(trim($separator), (string)$value));
            $path = [];

            foreach ($parts as $part) {
                if ($part === '') {
                    continue;
                }

                $path[] = $part;
                $expanded[implode($separator, $path)] = true;
            }
        }

        $paths = array_keys($expanded);
        sort($paths, SORT_STRING);

        return $paths;
    }

    /**
     * §3.4 — precomputed orderings.
     *
     * Each is the full record list ordered by the sorting's key, with nulls last and objectID
     * as the final tie-break, so it is a total order and a query can simply filter it.
     *
     * @param list<string> $objectIds
     * @param array<string, list<string|float|null>> $sortableValues
     * @return array<string, list<int>>
     */
    private function buildSortings(IndexDefinition $index, array $objectIds, array $sortableValues): array
    {
        $ids = array_keys($objectIds);
        $sortings = [];

        foreach ($index->allSortings() as $sorting) {
            if ($sorting->isRelevance()) {
                // Relevance with no query is score 0 for everything, so the order is the
                // tie-break alone.
                $relevance = $ids;
                usort($relevance, fn(int $a, int $b) => Comparator::compare($objectIds[$a], $objectIds[$b]));
                $sortings[SortingDefinition::RELEVANCE] = $relevance;
                continue;
            }

            $values = $sortableValues[$sorting->attribute] ?? null;

            if ($values === null) {
                continue;
            }

            $ordered = $ids;
            $descending = $sorting->isDescending();

            usort($ordered, function(int $a, int $b) use ($values, $descending, $objectIds) {
                $av = $values[$a] ?? null;
                $bv = $values[$b] ?? null;

                // Nulls last in both directions: a record with no value is unrankable rather
                // than smallest, and burying it is the useful behaviour.
                if ($av === null || $bv === null) {
                    if ($av === null && $bv === null) {
                        return Comparator::compare($objectIds[$a], $objectIds[$b]);
                    }

                    return $av === null ? 1 : -1;
                }

                $cmp = Comparator::compare($av, $bv);

                if ($cmp !== 0) {
                    return $descending ? -$cmp : $cmp;
                }

                return Comparator::compare($objectIds[$a], $objectIds[$b]);
            });

            $sortings[$sorting->name] = $ordered;
        }

        return $sortings;
    }

    /**
     * A key that distinguishes values by type as well as content, so the boolean `false` and
     * the string `"false"` intern to different buckets rather than colliding.
     */
    private function lookupKey(mixed $value): string
    {
        if (is_bool($value)) {
            return 'b:' . ($value ? '1' : '0');
        }

        if (is_int($value) || is_float($value)) {
            return 'n:' . $value;
        }

        return 's:' . $value;
    }
}
