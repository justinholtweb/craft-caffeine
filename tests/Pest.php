<?php

declare(strict_types=1);

use justinholtweb\caffeine\models\AttributeDefinition;
use justinholtweb\caffeine\models\IndexDefinition;
use justinholtweb\caffeine\models\MappedRecord;
use justinholtweb\caffeine\search\Artifact;
use justinholtweb\caffeine\search\ArtifactEncoder;
use justinholtweb\caffeine\services\Tokenizer;

/*
 |--------------------------------------------------------------------------
 | Suites
 |--------------------------------------------------------------------------
 |
 | Unit        — plain PHP, no Craft application.
 | Conformance — the shared fixture suite the PHP and JS engines must both satisfy.
 |
 */

uses()->group('unit')->in('Unit');
uses()->group('conformance')->in('Conformance');

/*
 |--------------------------------------------------------------------------
 | Helpers
 |--------------------------------------------------------------------------
 */

/**
 * An attribute definition with sensible defaults, for tests that only care about one or two
 * of its knobs.
 *
 * @param array<string, mixed> $config
 */
function attribute(array $config = []): AttributeDefinition
{
    return new AttributeDefinition(array_merge([
        'key' => 'test',
        'source' => AttributeDefinition::SOURCE_FIELD,
        'path' => 'test',
        'roles' => [AttributeDefinition::ROLE_FACET],
    ], $config));
}

/*
 |--------------------------------------------------------------------------
 | Conformance helpers
 |--------------------------------------------------------------------------
 */

/**
 * Turns a fixture's records into the shape `Records::stream()` yields, tokenising the
 * searchable text with the real tokeniser rather than accepting pre-baked tokens — otherwise
 * the fixtures would be testing the engine while quietly assuming the tokeniser.
 *
 * @param array<string, mixed> $fixture
 * @return list<array{elementId: int, siteId: int, objectId: string, content: array, tokens: array}>
 */
function conformanceRecords(array $fixture, IndexDefinition $index): array
{
    $tokenizer = new Tokenizer();
    $records = [];

    foreach ($fixture['records'] as $i => $row) {
        $mapped = new MappedRecord(
            elementId: $i + 1,
            siteId: 1,
            objectId: $row['objectId'],
            facets: $row['facets'] ?? [],
            sortable: $row['sortable'] ?? [],
            payload: $row['payload'] ?? [],
            searchable: $row['searchable'] ?? [],
            geo: $row['geo'] ?? [],
        );

        $records[] = [
            'elementId' => $i + 1,
            'siteId' => 1,
            'objectId' => $row['objectId'],
            'content' => $mapped->toArray(),
            'tokens' => $tokenizer->tokenizeRecord($index, $mapped),
        ];
    }

    return $records;
}

/**
 * Writes a compiled artifact where the JavaScript runner can find it, in both forms.
 *
 * `.artifact.json` is the compiled structure the engines query directly. `.encoded.json` is the
 * same artifact through `ArtifactEncoder` — the bytes production actually serves. The JavaScript
 * runner executes every case against both, so the encoder, the two decoders and the two engines
 * are all pinned against each other by the same fixtures.
 *
 * The payload is written into the index document rather than beside it, because the sharded and
 * unsharded forms decode through the same path and one file keeps the runner simple. Sharding is
 * a publishing decision, exercised in the publisher's own tests.
 */
function conformanceWriteArtifact(string $name, Artifact $artifact): void
{
    $dir = __DIR__ . '/Conformance/build';

    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    // JSON_PRESERVE_ZERO_FRACTION for the same reason the publisher sets it: without it a
    // whole float decodes back as an int, and the two artifacts stop being comparable.
    $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;
    $encoded = (new ArtifactEncoder())->encode($artifact);

    file_put_contents("{$dir}/{$name}.artifact.json", json_encode($artifact->toArray(), $flags));
    file_put_contents(
        "{$dir}/{$name}.encoded.json",
        json_encode(array_merge($encoded['index'], $encoded['payload']), $flags),
    );
}

/**
 * Asserts a search result against a fixture's expectations.
 *
 * Facets are compared as ordered `[value, count]` pairs rather than as a map, so the assertion
 * pins both the counts and the ordering rules of QUERY_SPEC §3.3.1 — and keeps the real typed
 * value, so a boolean facet cannot quietly pass with the string "true".
 *
 * @param array<string, mixed> $result
 * @param array<string, mixed> $expect
 */
function conformanceAssert(array $result, array $expect): void
{
    if (array_key_exists('nbHits', $expect)) {
        expect($result['nbHits'])->toBe($expect['nbHits'], 'nbHits');
    }

    if (array_key_exists('page', $expect)) {
        expect($result['page'])->toBe($expect['page'], 'page');
    }

    if (array_key_exists('nbPages', $expect)) {
        expect($result['nbPages'])->toBe($expect['nbPages'], 'nbPages');
    }

    if (array_key_exists('hits', $expect)) {
        $actual = array_map(fn(array $hit) => $hit['objectID'], $result['hits']);
        expect($actual)->toBe($expect['hits'], 'hits');
    }

    foreach ($expect['facets'] ?? [] as $key => $pairs) {
        $buckets = $result['caffeineFacets'][$key]['buckets'] ?? [];
        $actual = array_map(fn(array $b) => [$b['value'], $b['count']], $buckets);

        expect($actual)->toBe(
            array_map(fn(array $pair) => [$pair[0], $pair[1]], $pairs),
            "facet {$key}",
        );
    }

    foreach ($expect['facetsStats'] ?? [] as $key => $stats) {
        foreach ($stats as $stat => $value) {
            expect($result['facets_stats'][$key][$stat] ?? null)
                ->toEqual($value, "facets_stats.{$key}.{$stat}");
        }
    }
}
