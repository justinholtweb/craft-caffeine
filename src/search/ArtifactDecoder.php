<?php

namespace justinholtweb\caffeine\search;

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
     */
    public function decode(array $index, ?array $payload = null, int $version = 0): Artifact
    {
        $format = (int)($index['format'] ?? 0);

        if ($format !== ArtifactEncoder::FORMAT) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported artifact format %d; this build reads format %d.',
                $format,
                ArtifactEncoder::FORMAT,
            ));
        }

        $payload ??= $index;
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
                'records' => $this->decodeLists(
                    (string)($facet['records'] ?? ''),
                    (string)($facet['recordLengths'] ?? ''),
                ),
            ];
        }

        $sortings = [];

        foreach ((array)($index['sortings'] ?? []) as $name => $order) {
            $sortings[$name] = Varint::decode((string)$order);
        }

        return new Artifact(
            index: (string)($index['index'] ?? ''),
            version: $version,
            objectIds: array_values((array)($payload['objectIds'] ?? [])),
            payloads: array_values((array)($payload['payloads'] ?? [])),
            facets: $facets,
            sortings: $sortings,
            tokens: array_values((array)($index['tokens'] ?? [])),
            tokenPostings: $this->decodeTokenPostings(
                (string)($index['tokenIds'] ?? ''),
                (string)($index['tokenWeights'] ?? ''),
                (string)($index['tokenLengths'] ?? ''),
            ),
            sortableValues: (array)($index['sortableValues'] ?? []),
            stopwords: array_values((array)($index['stopwords'] ?? [])),
            geo: (array)($index['geo'] ?? []),
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
     * @return list<list<array{0: int, 1: float}>>
     */
    private function decodeTokenPostings(string $ids, string $weights, string $lengths): array
    {
        $idValues = Varint::decode($ids);
        $weightValues = Varint::decode($weights);
        $counts = Varint::decode($lengths);

        $postings = [];
        $offset = 0;

        foreach ($counts as $count) {
            $list = [];
            $running = 0;

            for ($i = 0; $i < $count; $i++) {
                $running += $idValues[$offset] ?? 0;
                // Cast, because PHP's `/` hands back an int when the division comes out even,
                // and a weight of int(1) where the compiler produced float(1.0) would fail the
                // strict round-trip assertion for a reason that has nothing to do with the codec.
                $list[] = [$running, (float)($weightValues[$offset] / ArtifactEncoder::WEIGHT_SCALE)];
                $offset++;
            }

            $postings[] = $list;
        }

        return $postings;
    }
}
