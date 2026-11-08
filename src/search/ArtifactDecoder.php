<?php

namespace justinholtweb\caffeine\search;

use Closure;
use InvalidArgumentException;

/**
 * Rebuilds an `Artifact` from the wire format. Twin of runtime/src/decode.js.
 *
 * Used server-side wherever an artifact is read back rather than recompiled — serving a
 * fragment, answering an Algolia-shaped request, the CP's query playground — and it is the
 * reference the JavaScript decoder is checked against, because the browser runs the same
 * decode on the same bytes.
 */
class ArtifactDecoder
{
    /**
     * @param array<string, mixed> $index The index document. May already carry the payload.
     * @param array<string, mixed>|null $payload The payload document, when it was sharded out.
     * @param int $version From the manifest — shard documents deliberately do not carry it, so
     *                     that byte-identical rebuilds hash to the same filename.
     * @param array<string, mixed>|null $lookup The server's lookup document, read instead of the
     *                     payload document when payloads are loaded on demand.
     * @param (Closure(list<int>, Artifact): array<int, array<string, mixed>>)|null $payloadLoader Loads
     *                     them, when `$lookup` is given.
     */
    public function decode(array $index, ?array $payload = null, int $version = 0, ?array $lookup = null, ?Closure $payloadLoader = null): Artifact
    {
        $format = (int)($index['format'] ?? 0);

        if ($format !== ArtifactEncoder::FORMAT) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported artifact format %d; this build reads format %d.',
                $format,
                ArtifactEncoder::FORMAT,
            ));
        }

        $lazy = $payload === null && $lookup !== null && $payloadLoader !== null;
        $payload ??= $lazy ? $lookup : $index;
        $recordCount = (int)($index['nbRecords'] ?? 0);

        $facets = [];

        foreach ((array)($index['facets'] ?? []) as $key => $facet) {
            $facets[$key] = [
                // Rebuilt in the order the compiler writes them, so a decoded artifact is
                // identical to a compiled one under a strict comparison and not merely
                // equivalent — which is what the round-trip test asserts.
                'type' => $facet['type'],
                'operator' => $facet['operator'],
                'sort' => $facet['sort'] ?? null,
                'valueOrder' => $facet['valueOrder'] ?? [],
                'maxValues' => $facet['maxValues'] ?? 0,
                'values' => array_values((array)($facet['values'] ?? [])),
                'postings' => $this->decodeLists(
                    (string)($facet['postings'] ?? ''),
                    (string)($facet['postingLengths'] ?? ''),
                ),
                // `records`, the reverse map, is left on the wire for the browser. This side
                // counts from postings — see `Artifact::$facets`.
            ];
        }

        $sortings = [];

        foreach ((array)($index['sortings'] ?? []) as $name => $order) {
            $sortings[$name] = Varint::decode((string)$order);
        }

        // Unpacked first: PHP accepts named arguments after an unpacked array, not before one.
        return new Artifact(
            ...$this->decodeTokenPostings(
                (string)($index['tokenIds'] ?? ''),
                (string)($index['tokenWeights'] ?? ''),
                (string)($index['tokenLengths'] ?? ''),
            ),
            index: (string)($index['index'] ?? ''),
            version: $version,
            objectIds: array_values((array)($payload['objectIds'] ?? [])),
            payloads: $lazy ? [] : array_values((array)($payload['payloads'] ?? [])),
            facets: $facets,
            sortings: $sortings,
            tokens: array_values((array)($index['tokens'] ?? [])),
            sortableValues: (array)($index['sortableValues'] ?? []),
            stopwords: array_values((array)($index['stopwords'] ?? [])),
            geo: (array)($index['geo'] ?? []),
            payloadHashes: $lazy ? (string)($lookup['payloadHashes'] ?? '') : '',
            payloadLoader: $lazy ? $payloadLoader : null,
        );
    }

    /**
     * Whether a document carries its own payload, rather than pointing at a separate shard.
     *
     * @param array<string, mixed> $document
     */
    public static function hasPayload(array $document): bool
    {
        return isset($document['objectIds']);
    }

    /**
     * @return list<list<int>>
     */
    private function decodeLists(string $flat, string $lengths, bool $delta = true): array
    {
        $values = Varint::decode($flat);
        $counts = Varint::decode($lengths);
        $lists = [];
        $offset = 0;

        foreach ($counts as $count) {
            $list = [];
            $running = 0;

            for ($i = 0; $i < $count; $i++) {
                if (!array_key_exists($offset, $values)) {
                    throw new InvalidArgumentException('Artifact postings are shorter than their lengths claim.');
                }

                if ($delta) {
                    $running += $values[$offset];
                    $list[] = $running;
                } else {
                    $list[] = $values[$offset];
                }

                $offset++;
            }

            $lists[] = $list;
        }

        return $lists;
    }

    /**
     * Token postings into the flat lists `Artifact` holds: ids undeltaed per token, weights
     * unscaled, and an offset where each token starts.
     *
     * @return array{tokenIds: list<int>, tokenWeights: list<float>, tokenOffsets: list<int>}
     */
    private function decodeTokenPostings(string $ids, string $weights, string $lengths): array
    {
        $idValues = Varint::decode($ids);
        $weightValues = Varint::decode($weights);
        $offsets = [0];
        $offset = 0;

        foreach (Varint::decode($lengths) as $count) {
            $running = 0;
            $end = $offset + $count;

            if ($end > count($idValues) || $end > count($weightValues)) {
                throw new InvalidArgumentException('Artifact token postings are shorter than their lengths claim.');
            }

            // In place: the deltas become ids and the scaled weights become weights, without a
            // second pair of multi-million-entry lists alongside.
            for (; $offset < $end; $offset++) {
                $running += $idValues[$offset];
                $idValues[$offset] = $running;
                // Cast, because PHP's `/` hands back an int when the division comes out even,
                // and a weight of int(1) where the compiler produced float(1.0) would fail the
                // strict round-trip assertion for a reason that has nothing to do with the codec.
                $weightValues[$offset] = (float)($weightValues[$offset] / ArtifactEncoder::WEIGHT_SCALE);
            }

            $offsets[] = $offset;
        }

        return [
            'tokenIds' => array_slice($idValues, 0, $offset),
            'tokenWeights' => array_slice($weightValues, 0, $offset),
            'tokenOffsets' => $offsets,
        ];
    }
}
