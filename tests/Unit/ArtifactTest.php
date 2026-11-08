<?php

declare(strict_types=1);

use justinholtweb\caffeine\search\Artifact;
use justinholtweb\caffeine\search\ArtifactDecoder;
use justinholtweb\caffeine\search\ArtifactEncoder;

/**
 * The PHP side holds less than the shared structure: no facet reverse map, and token postings as
 * flat lists. These pin that the shared structure can still be produced exactly from what it
 * holds, since that structure is what the browser and the conformance fixtures see.
 */

/**
 * The shared shape, as the compiler used to hold it. Three records; record 1 carries no colour,
 * and record 2 carries two tags.
 *
 * @return array<string, mixed>
 */
function caffeineSharedArtifact(): array
{
    return [
        'index' => 'products',
        'version' => 1,
        'objectIds' => ['1-1', '2-1', '3-1'],
        'payloads' => [['title' => 'Saw'], ['title' => 'Drill'], ['title' => 'Hose']],
        'facets' => [
            'colour' => [
                'type' => 'string',
                'operator' => 'or',
                'sort' => null,
                'valueOrder' => [],
                'maxValues' => 20,
                'values' => ['red', 'blue'],
                'postings' => [[0], [2]],
                'records' => [[0], [], [1]],
            ],
            'tags' => [
                'type' => 'string',
                'operator' => 'and',
                'sort' => null,
                'valueOrder' => [],
                'maxValues' => 20,
                'values' => ['sale', 'new', 'eco'],
                'postings' => [[0, 2], [1], [2]],
                'records' => [[0], [1], [0, 2]],
            ],
        ],
        'sortings' => ['relevance' => [0, 1, 2]],
        'tokens' => ['drill', 'hose', 'saw'],
        'tokenPostings' => [[[1, 5.0]], [[2, 5.0]], [[0, 5.0], [2, 0.5]]],
        'sortableValues' => [],
        'stopwords' => [],
        'geo' => [],
    ];
}

it('rebuilds the shared structure exactly from what it holds', function() {
    $shared = caffeineSharedArtifact();
    $artifact = Artifact::fromArray($shared);

    expect($artifact->toArray())->toBe($shared);
});

it('holds token postings flat, with an offset per token', function() {
    $artifact = Artifact::fromArray(caffeineSharedArtifact());

    expect($artifact->tokenIds)->toBe([1, 2, 0, 2]);
    expect($artifact->tokenWeights)->toBe([5.0, 5.0, 5.0, 0.5]);
    expect($artifact->tokenOffsets)->toBe([0, 1, 2, 4]);
    expect($artifact->tokenPostings(2))->toBe([[0, 5.0], [2, 0.5]]);
});

it('does not hold the reverse map, but can rebuild it dense and sorted', function() {
    $artifact = Artifact::fromArray(caffeineSharedArtifact());

    expect($artifact->facets['tags'])->not->toHaveKey('records');
    expect($artifact->records('tags'))->toBe([[0], [1], [0, 2]]);
    expect($artifact->records('colour'))->toBe([[0], [], [1]]);
});

it('keeps the reverse map on the wire for the browser, and decodes without it', function() {
    $shared = caffeineSharedArtifact();
    $encoded = (new ArtifactEncoder())->encode(Artifact::fromArray($shared));

    expect($encoded['index']['facets']['tags'])->toHaveKey('records');
    expect((new ArtifactDecoder())->decode($encoded['index'], $encoded['payload'], 1)->toArray())->toBe($shared);
});

it('refuses token offsets that do not match the tokens', function() {
    new Artifact(
        index: 'products',
        version: 1,
        objectIds: [],
        payloads: [],
        facets: [],
        sortings: [],
        tokens: ['saw'],
    );
})->throws(InvalidArgumentException::class);

/*
 | Payloads on demand. With the payload sharded out, the server reads the index shard and the
 | lookup document, and loads one page's payloads through a loader.
 */

/**
 * The shared artifact decoded the way the server decodes it lazily, with a loader that answers
 * from `$source` and records every call.
 *
 * @param list<array<string, mixed>> $source
 * @param list<list<int>> $calls
 */
function caffeineLazyArtifact(array $source, array &$calls): Artifact
{
    $encoder = new ArtifactEncoder();
    $eager = Artifact::fromArray(caffeineSharedArtifact());
    $encoded = $encoder->encode($eager);

    return (new ArtifactDecoder())->decode(
        $encoded['index'],
        null,
        1,
        $encoder->encodeLookup($eager),
        function(array $ids, Artifact $artifact) use ($source, &$calls): array {
            $calls[] = $ids;

            // Answered out of order, to pin that the artifact puts them back.
            $out = [];

            foreach (array_reverse($ids) as $id) {
                $out[$id] = $source[$id];
            }

            return $out;
        },
    );
}

it('writes a lookup holding object ids and one hash per payload', function() {
    $shared = caffeineSharedArtifact();
    $lookup = (new ArtifactEncoder())->encodeLookup(Artifact::fromArray($shared));

    expect($lookup['objectIds'])->toBe($shared['objectIds']);
    expect($lookup['payloadHashes'])->toBe(implode('', array_map(ArtifactEncoder::payloadHash(...), $shared['payloads'])));
    expect(strlen($lookup['payloadHashes']))->toBe(3 * ArtifactEncoder::PAYLOAD_HASH_LENGTH);
    expect($lookup)->not->toHaveKey('payloads');
});

it('hashes payloads by value, so a changed card no longer matches', function() {
    expect(ArtifactEncoder::payloadHash(['title' => 'Saw', 'price' => 12.0]))
        ->toBe(ArtifactEncoder::payloadHash(['title' => 'Saw', 'price' => 12.0]))
        ->not->toBe(ArtifactEncoder::payloadHash(['title' => 'Saw', 'price' => 12]))
        ->not->toBe(ArtifactEncoder::payloadHash(['title' => 'Saws', 'price' => 12.0]));
});

it('decodes from the index and lookup alone, holding no payloads', function() {
    $calls = [];
    $artifact = caffeineLazyArtifact(caffeineSharedArtifact()['payloads'], $calls);

    expect($artifact->hasPayloads())->toBeFalse();
    expect($artifact->payloads)->toBe([]);
    expect($artifact->objectIds)->toBe(['1-1', '2-1', '3-1']);
    expect($artifact->payloadHash(1))->toBe(ArtifactEncoder::payloadHash(['title' => 'Drill']));
    expect($artifact->payloadHash(3))->toBeNull();
    expect($calls)->toBe([]);
});

it('loads a page of payloads in one call, in the order asked for', function() {
    $calls = [];
    $artifact = caffeineLazyArtifact(caffeineSharedArtifact()['payloads'], $calls);

    expect($artifact->payloadsFor([2, 0]))->toBe([2 => ['title' => 'Hose'], 0 => ['title' => 'Saw']]);
    expect($calls)->toBe([[2, 0]]);
});

it('answers a search with the same hits whether payloads are held or loaded', function() {
    $calls = [];
    $lazy = caffeineLazyArtifact(caffeineSharedArtifact()['payloads'], $calls);
    $eager = Artifact::fromArray(caffeineSharedArtifact());

    foreach ([[], ['query' => 'saw'], ['refinements' => ['tags' => ['sale']]], ['hitsPerPage' => 1, 'page' => 2]] as $state) {
        $state = justinholtweb\caffeine\search\QueryState::fromArray($state);
        $a = (new justinholtweb\caffeine\search\Engine($eager))->search($state);
        $b = (new justinholtweb\caffeine\search\Engine($lazy))->search($state);
        unset($a['processingTimeMS'], $b['processingTimeMS']);

        expect($b)->toBe($a);
    }

    // One load per search, each for that search's page only.
    expect($calls)->toHaveCount(4);
    expect($calls[3])->toBe([2]);
});

it('still produces the shared structure, payloads included, when they load on demand', function() {
    $calls = [];
    $artifact = caffeineLazyArtifact(caffeineSharedArtifact()['payloads'], $calls);

    expect($artifact->toArray())->toBe(caffeineSharedArtifact());
});

it('decodes eagerly when a lookup comes without a loader', function() {
    $encoder = new ArtifactEncoder();
    $eager = Artifact::fromArray(caffeineSharedArtifact());
    $encoded = $encoder->encode($eager);

    $artifact = (new ArtifactDecoder())->decode($encoded['index'], $encoded['payload'], 1, $encoder->encodeLookup($eager));

    expect($artifact->hasPayloads())->toBeTrue();
    expect($artifact->toArray())->toBe(caffeineSharedArtifact());
});
