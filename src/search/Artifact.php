<?php

namespace justinholtweb\caffeine\search;

/**
 * A compiled index, in the exact shape both engines read.
 *
 * This class is a typed view over plain arrays that round-trip through JSON unchanged, because
 * the JavaScript engine consumes the same structure. Anything that cannot survive
 * `json_encode` / `JSON.parse` intact does not belong in here.
 *
 * Records are addressed by **internal id** — their position in index order — rather than by
 * element id. Integer positions keep postings lists dense and let both engines use plain array
 * indexing rather than hash lookups on the hot path.
 */
class Artifact
{
    public function __construct(
        public readonly string $index,
        public readonly int $version,
        /** @var list<string> internal id → objectID */
        public readonly array $objectIds,
        /** @var list<array<string, mixed>> internal id → payload */
        public readonly array $payloads,
        /**
         * Facet data, keyed by facet key. Each entry:
         *
         *   type      string|hierarchical|numeric|boolean|date
         *   operator  or|and
         *   values    list of distinct values, the interning dictionary
         *   postings  parallel to `values`: sorted list of internal ids carrying each value
         *   records   internal id → list of value indexes carried by that record
         *
         * Both directions are stored. `postings` answers "which records have this value",
         * which is filtering; `records` answers "which values does this record have", which is
         * counting. Deriving either from the other at query time would cost more than the
         * bytes they take up.
         *
         * @var array<string, array{type: string, operator: string, values: list<mixed>, postings: list<list<int>>, records: list<list<int>>}>
         */
        public readonly array $facets,
        /**
         * Precomputed orderings, keyed by sorting name: a list of internal ids.
         *
         * Used directly when no text query is active, which is the common case for a filtered
         * listing. With a query, text score sits between the sort key and the tie-break (see
         * QUERY_SPEC §3.4), so the order has to be recomputed — but only over the matching
         * records, which a query has already narrowed.
         *
         * @var array<string, list<int>>
         */
        public readonly array $sortings,
        /** @var list<string> Distinct tokens, sorted, so a prefix match is a binary search. */
        public readonly array $tokens,
        /**
         * Parallel to `tokens`: the records carrying each token, as `[internalId, weight]`
         * pairs sorted by internal id.
         *
         * @var list<list<array{0: int, 1: float}>>
         */
        public readonly array $tokenPostings,
        /**
         * Sortable values by attribute key, internal id → value or null. Needed at query time
         * only to re-sort when a text query is active.
         *
         * @var array<string, list<string|float|null>>
         */
        public readonly array $sortableValues = [],
        /**
         * Words both engines drop from a query, per QUERY_SPEC §7.
         *
         * Shipped with the data rather than read from config, because the browser has no access
         * to project config and a stopword list that differed between the two engines would make
         * the same search return different results on the server and in the browser.
         *
         * @var list<string>
         */
        public readonly array $stopwords = [],
        /**
         * Coordinates by facet key, internal id → `[lat, lng]` or null.
         *
         * Parallel to the records, not interned: a geo facet is filtered by distance from a
         * point the visitor chose, which no amount of build-time work can precompute.
         *
         * @var array<string, list<array{0: float, 1: float}|null>>
         */
        public readonly array $geo = [],
    ) {
    }

    public function recordCount(): int
    {
        return count($this->objectIds);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'index' => $this->index,
            'version' => $this->version,
            'objectIds' => $this->objectIds,
            'payloads' => $this->payloads,
            'facets' => $this->facets,
            'sortings' => $this->sortings,
            'tokens' => $this->tokens,
            'tokenPostings' => $this->tokenPostings,
            'sortableValues' => $this->sortableValues,
            'stopwords' => $this->stopwords,
            'geo' => $this->geo,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            index: (string)($data['index'] ?? ''),
            version: (int)($data['version'] ?? 0),
            objectIds: array_values((array)($data['objectIds'] ?? [])),
            payloads: array_values((array)($data['payloads'] ?? [])),
            facets: (array)($data['facets'] ?? []),
            sortings: (array)($data['sortings'] ?? []),
            tokens: array_values((array)($data['tokens'] ?? [])),
            tokenPostings: array_values((array)($data['tokenPostings'] ?? [])),
            sortableValues: (array)($data['sortableValues'] ?? []),
            stopwords: array_values((array)($data['stopwords'] ?? [])),
            geo: (array)($data['geo'] ?? []),
        );
    }
}
