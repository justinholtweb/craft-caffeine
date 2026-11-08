<?php

namespace justinholtweb\caffeine\search;

use Closure;
use InvalidArgumentException;

/**
 * A compiled index, as the PHP engine holds it.
 *
 * Plain arrays throughout, so that `toArray()` round-trips through JSON unchanged and hands the
 * JavaScript engine the structure both engines are specified against. Anything that cannot
 * survive `json_encode` / `JSON.parse` intact does not belong in here. What is held here is that
 * structure minus what can be derived from the rest: see `$facets` and `$tokenIds`, which is
 * where the memory went on a large catalogue.
 *
 * Records are addressed by **internal id** — their position in index order — rather than by
 * element id. Integer positions keep postings lists dense and let both engines use plain array
 * indexing rather than hash lookups on the hot path.
 */
class Artifact
{
    /**
     * Where each token's postings start in `tokenIds` / `tokenWeights`. One entry per token
     * plus a final one, so token `i` spans `[tokenOffsets[i], tokenOffsets[i + 1])`.
     *
     * @var list<int>
     */
    public readonly array $tokenOffsets;

    /** @var (Closure(list<int>, Artifact): array<int, array<string, mixed>>)|null */
    private ?Closure $payloadLoader;

    public function __construct(
        public readonly string $index,
        public readonly int $version,
        /** @var list<string> internal id → objectID */
        public readonly array $objectIds,
        /**
         * internal id → payload. Empty when the server holds only the index and loads a page's
         * payloads on demand: see `payloadsFor()`.
         *
         * @var list<array<string, mixed>>
         */
        public readonly array $payloads,
        /**
         * Facet data, keyed by facet key. Each entry:
         *
         *   type      string|hierarchical|numeric|boolean|date
         *   operator  or|and
         *   values    list of distinct values, the interning dictionary
         *   postings  parallel to `values`: sorted list of internal ids carrying each value
         *
         * The wire format also carries the reverse map — internal id → value indexes — because
         * the browser counts by walking it. This side does not hold it. It is one PHP array per
         * record per facet, which at 37,000 products and 140 facets was most of a gigabyte to
         * decode on every request, and counting from `postings` touches each (record, value)
         * pair once no matter how many facets there are. `records()` rebuilds it on demand
         * for the encoder and for `toArray()`.
         *
         * @var array<string, array{type: string, operator: string, sort?: string|null, valueOrder?: list<mixed>, maxValues?: int, values: list<mixed>, postings: list<list<int>>}>
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
         * Every token's postings, concatenated in token order: the internal ids carrying each
         * token, ascending within a token. `tokenWeights` is parallel to it.
         *
         * Two flat lists rather than a list of `[id, weight]` pairs per token. A pair is a PHP
         * array of its own, and a catalogue with product descriptions has millions of them.
         *
         * @var list<int>
         */
        public readonly array $tokenIds = [],
        /** @var list<float> Parallel to `tokenIds`. */
        public readonly array $tokenWeights = [],
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
        /** @var list<int> See the property. May be omitted only when there are no tokens. */
        array $tokenOffsets = [0],
        /**
         * Each record's published payload hash, `ArtifactEncoder::PAYLOAD_HASH_LENGTH` hex
         * characters apiece in internal id order, when the payloads are loaded on demand. One
         * string rather than a list, so it costs one allocation however many records there are.
         */
        public readonly string $payloadHashes = '',
        /**
         * Given internal ids and this artifact, returns their payloads keyed by id. Set when `$payloads` was left
         * out, so a server render pays only for the page of hits it shows.
         *
         * @var (Closure(list<int>, Artifact): array<int, array<string, mixed>>)|null
         */
        ?Closure $payloadLoader = null,
    ) {
        if (count($tokenOffsets) !== count($tokens) + 1) {
            throw new InvalidArgumentException(sprintf(
                'An artifact with %d tokens needs %d token offsets; got %d.',
                count($tokens),
                count($tokens) + 1,
                count($tokenOffsets),
            ));
        }

        $this->tokenOffsets = $tokenOffsets;
        $this->payloadLoader = $payloadLoader;
    }

    /** Whether `$payloads` holds every record's payload, rather than loading them on demand. */
    public function hasPayloads(): bool
    {
        return $this->payloadLoader === null;
    }

    /**
     * Payloads for some records, keyed by internal id: what a page of hits needs.
     *
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    public function payloadsFor(array $ids): array
    {
        $source = $this->payloadLoader !== null ? ($this->payloadLoader)($ids, $this) : $this->payloads;
        $payloads = [];

        // In the order asked for, whatever order the loader answered in.
        foreach ($ids as $id) {
            $payloads[$id] = $source[$id] ?? [];
        }

        return $payloads;
    }

    /**
     * One record's published payload hash, or null when this artifact does not carry them.
     */
    public function payloadHash(int $id): ?string
    {
        $length = ArtifactEncoder::PAYLOAD_HASH_LENGTH;

        if ($this->payloadHashes === '' || ($id + 1) * $length > strlen($this->payloadHashes)) {
            return null;
        }

        return substr($this->payloadHashes, $id * $length, $length);
    }

    /**
     * Builds the flat token lists from per-token `[id, weight]` pairs.
     *
     * @param list<list<array{0: int, 1: float}>> $postings Parallel to the token list.
     * @return array{tokenIds: list<int>, tokenWeights: list<float>, tokenOffsets: list<int>}
     */
    public static function flattenTokenPostings(array $postings): array
    {
        $ids = [];
        $weights = [];
        $offsets = [0];

        foreach ($postings as $list) {
            foreach ($list as [$id, $weight]) {
                $ids[] = (int)$id;
                $weights[] = (float)$weight;
            }

            $offsets[] = count($ids);
        }

        return ['tokenIds' => $ids, 'tokenWeights' => $weights, 'tokenOffsets' => $offsets];
    }

    public function recordCount(): int
    {
        return count($this->objectIds);
    }

    /**
     * One token's postings as `[id, weight]` pairs.
     *
     * @return list<array{0: int, 1: float}>
     */
    public function tokenPostings(int $token): array
    {
        $pairs = [];

        for ($i = $this->tokenOffsets[$token] ?? 0, $end = $this->tokenOffsets[$token + 1] ?? 0; $i < $end; $i++) {
            $pairs[] = [$this->tokenIds[$i], $this->tokenWeights[$i]];
        }

        return $pairs;
    }

    /**
     * A facet's reverse map — internal id → sorted value indexes — rebuilt from its postings.
     *
     * Dense, with an entry for every record, exactly as the compiler used to hold it. Postings
     * are walked in value order, so each record's list comes out sorted without a sort.
     *
     * @return list<list<int>>
     */
    public function records(string $facet): array
    {
        $records = array_fill(0, $this->recordCount(), []);

        foreach ($this->facets[$facet]['postings'] ?? [] as $valueIndex => $ids) {
            foreach ($ids as $id) {
                $records[$id][] = $valueIndex;
            }
        }

        return $records;
    }

    /**
     * The artifact in the shape both engines were specified against, and the one the
     * conformance fixtures hand the JavaScript engine: reverse maps included, token postings as
     * pairs. Rebuilt on every call, so not for the query path.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $facets = [];

        foreach ($this->facets as $key => $facet) {
            $facets[$key] = $facet + ['records' => $this->records($key)];
        }

        $tokenPostings = [];

        foreach (array_keys($this->tokens) as $i) {
            $tokenPostings[] = $this->tokenPostings($i);
        }

        return [
            'index' => $this->index,
            'version' => $this->version,
            'objectIds' => $this->objectIds,
            'payloads' => $this->hasPayloads()
                ? $this->payloads
                : array_values($this->payloadsFor(array_keys($this->objectIds))),
            'facets' => $facets,
            'sortings' => $this->sortings,
            'tokens' => $this->tokens,
            'tokenPostings' => $tokenPostings,
            'sortableValues' => $this->sortableValues,
            'stopwords' => $this->stopwords,
            'geo' => $this->geo,
        ];
    }

    /**
     * The inverse of `toArray()`. Any reverse map in `$data` is ignored: it is derived.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $facets = [];

        foreach ((array)($data['facets'] ?? []) as $key => $facet) {
            unset($facet['records']);
            $facets[$key] = $facet;
        }

        return new self(
            ...self::flattenTokenPostings(array_values((array)($data['tokenPostings'] ?? []))),
            index: (string)($data['index'] ?? ''),
            version: (int)($data['version'] ?? 0),
            objectIds: array_values((array)($data['objectIds'] ?? [])),
            payloads: array_values((array)($data['payloads'] ?? [])),
            facets: $facets,
            sortings: (array)($data['sortings'] ?? []),
            tokens: array_values((array)($data['tokens'] ?? [])),
            sortableValues: (array)($data['sortableValues'] ?? []),
            stopwords: array_values((array)($data['stopwords'] ?? [])),
            geo: (array)($data['geo'] ?? []),
        );
    }
}
