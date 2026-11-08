<?php

namespace justinholtweb\caffeine\search;

/**
 * Encodes a compiled `Artifact` into the wire format described in docs/ARTIFACT.md.
 *
 * `Compiler` produces the shape both engines *read*; this produces the shape the artifact is
 * *stored and served* in. Keeping them apart is what let Phase 3 land without touching either
 * engine: the browser decodes once on load and queries exactly the structure the PHP engine
 * holds in memory.
 *
 * Two rules govern everything here.
 *
 * **The round trip is exact.** `ArtifactDecoder::decode($this->encode($a))` equals `$a`, value
 * for value, and a test asserts it against every fixture. The server renders the first paint
 * from a compiled artifact and the browser refines against a decoded one, so any value that
 * survived encoding only approximately would be a silent disagreement between the two engines.
 * This is why weights are quantised in the `Compiler` rather than here.
 *
 * **The version is not in here.** It lives in the manifest, which is the mutable pointer and the
 * right home for it. A shard's filename is a hash of its own bytes, so a version number inside
 * would make two byte-identical rebuilds hash differently and republish everything for the sake
 * of one integer — losing the property the whole publish path is built on. `ArtifactDecoder`
 * takes the version from the manifest instead.
 *
 * **The split is by access pattern, not by size.** The index document carries the query
 * machinery — postings, tokens, orderings — and the payload document carries the per-record card
 * data that is nearly all of the bytes and none of the matching. A facet-count request can be
 * answered from the index document alone, at a fraction of the transfer.
 */
class ArtifactEncoder
{
    /** Bumped when the layout changes incompatibly. The decoder refuses anything it predates. */
    public const FORMAT = 1;

    /** Weights are stored as integers; `Compiler::WEIGHT_PRECISION` is the matching rounding. */
    public const WEIGHT_SCALE = 1000;

    /** Hex characters of each record's hash in the lookup document. */
    public const PAYLOAD_HASH_LENGTH = 8;

    /**
     * @return array{index: array<string, mixed>, payload: array<string, mixed>}
     */
    public function encode(Artifact $artifact): array
    {
        return [
            'index' => $this->encodeIndex($artifact),
            'payload' => $this->encodePayload($artifact),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function encodeIndex(Artifact $artifact): array
    {
        $recordCount = $artifact->recordCount();
        $facets = [];

        foreach ($artifact->facets as $key => $facet) {
            [$postings, $postingLengths] = $this->encodeLists($facet['postings'], true);

            // The reverse map is only on the wire: the browser counts by walking it, while the PHP
            // side counts from postings and never holds it. Rebuilt from the postings here,
            // dense and in id order, which is how it is read back.
            [$recordIndexes, $recordLengths] = $this->encodeLists($artifact->records($key), true);

            $facets[$key] = [
                'type' => $facet['type'],
                'operator' => $facet['operator'],
                'sort' => $facet['sort'] ?? null,
                'valueOrder' => $facet['valueOrder'] ?? [],
                'maxValues' => $facet['maxValues'] ?? 0,
                'values' => array_values($facet['values']),
                'postings' => $postings,
                'postingLengths' => $postingLengths,
                'records' => $recordIndexes,
                'recordLengths' => $recordLengths,
            ];
        }

        $sortings = [];

        foreach ($artifact->sortings as $name => $order) {
            // A sorting is an arbitrary permutation, so its gaps go negative and it cannot be
            // delta-encoded. Raw varints still beat JSON: an id under 16,384 costs two bytes.
            $sortings[$name] = Varint::encode($order);
        }

        [$tokenIds, $tokenWeights, $tokenLengths] = $this->encodeTokenPostings($artifact);

        return [
            'format' => self::FORMAT,
            'index' => $artifact->index,
            'nbRecords' => $recordCount,
            'facets' => $facets,
            'sortings' => $sortings,
            'tokens' => $artifact->tokens,
            'tokenIds' => $tokenIds,
            'tokenWeights' => $tokenWeights,
            'tokenLengths' => $tokenLengths,
            'sortableValues' => $artifact->sortableValues,
            'stopwords' => $artifact->stopwords,
            'geo' => $artifact->geo,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function encodePayload(Artifact $artifact): array
    {
        return [
            'format' => self::FORMAT,
            'index' => $artifact->index,
            'nbRecords' => $artifact->recordCount(),
            'objectIds' => $artifact->objectIds,
            'payloads' => $artifact->payloads,
        ];
    }

    /**
     * The server's lookup document: object ids and a hash of each record's payload, nothing a
     * browser needs.
     *
     * With the payload sharded out, a server render reads the index shard and this, and loads
     * the payloads for one page of hits from the records they were compiled from. The hash is
     * what makes that exact rather than nearly so: a record rebuilt since the publish no longer
     * matches its hash, and that hit is answered from the payload shard instead, so the server
     * never shows a card the published artifact does not hold.
     *
     * @return array<string, mixed>
     */
    public function encodeLookup(Artifact $artifact): array
    {
        $hashes = '';

        foreach ($artifact->payloads as $payload) {
            $hashes .= self::payloadHash($payload);
        }

        return [
            'format' => self::FORMAT,
            'index' => $artifact->index,
            'nbRecords' => $artifact->recordCount(),
            'objectIds' => $artifact->objectIds,
            'payloadHashes' => $hashes,
        ];
    }

    /**
     * A payload's fingerprint, as the lookup document carries it.
     *
     * @param array<string, mixed> $payload
     */
    public static function payloadHash(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);

        return substr(hash('xxh3', $json), 0, self::PAYLOAD_HASH_LENGTH);
    }

    /**
     * Flattens a list of integer lists into one varint blob plus a blob of their lengths.
     *
     * Two blobs rather than one per list: a facet with 4,000 values would otherwise become 4,000
     * short base64 strings, and the JSON punctuation around them would cost more than the ids.
     *
     * @param list<list<int>> $lists
     * @param bool $delta Whether each inner list is ascending and can store its gaps.
     * @return array{0: string, 1: string}
     */
    private function encodeLists(array $lists, bool $delta): array
    {
        $flat = [];
        $lengths = [];

        foreach ($lists as $list) {
            $list = array_values((array)$list);
            $lengths[] = count($list);

            if ($delta) {
                // Delta is per list, not across the concatenation: each list restarts at zero so
                // the decoder can slice the blob without replaying everything before it.
                $previous = 0;

                foreach ($list as $value) {
                    $flat[] = (int)$value - $previous;
                    $previous = (int)$value;
                }

                continue;
            }

            foreach ($list as $value) {
                $flat[] = (int)$value;
            }
        }

        return [Varint::encode($flat), Varint::encode($lengths)];
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function encodeTokenPostings(Artifact $artifact): array
    {
        $ids = [];
        $weights = [];
        $lengths = [];
        $offsets = $artifact->tokenOffsets;

        for ($token = 0, $count = count($artifact->tokens); $token < $count; $token++) {
            $lengths[] = $offsets[$token + 1] - $offsets[$token];
            $previous = 0;

            for ($i = $offsets[$token]; $i < $offsets[$token + 1]; $i++) {
                $id = $artifact->tokenIds[$i];
                $ids[] = $id - $previous;
                $previous = $id;

                $weights[] = (int)round($artifact->tokenWeights[$i] * self::WEIGHT_SCALE);
            }
        }

        return [Varint::encode($ids), Varint::encode($weights), Varint::encode($lengths)];
    }
}
