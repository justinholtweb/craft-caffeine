<?php

/**
 * A catalogue shaped like the one in issue #5, where the server side stopped scaling.
 *
 * The other benchmarks use six facets and short titles. A supplier catalogue has eight product
 * types, sixteen attribute facets per type that only that type carries, and a sixty-word
 * description per product, and it is the shape that showed where the time went: decoding the
 * artifact on every request, and counting facets by walking every record once per facet.
 *
 * Reports what a server-rendered request pays: reading the shards, decoding them, holding the
 * result, and each query. With OPcache on, also what a request pays once the decoded artifact is
 * cached there (see `ArtifactCache`).
 *
 *   php -d memory_limit=-1 tests/bench/heavy-catalogue.php 37272
 *   php -d memory_limit=-1 -d opcache.enable_cli=1 -d opcache.memory_consumption=512 \
 *       -d opcache.interned_strings_buffer=64 tests/bench/heavy-catalogue.php 37272
 *
 * (Add `-d zend_extension=opcache` where PHP does not load OPcache already.)
 */

require __DIR__ . '/../../vendor/autoload.php';

use justinholtweb\caffeine\models\IndexDefinition;
use justinholtweb\caffeine\models\MappedRecord;
use justinholtweb\caffeine\search\Artifact;
use justinholtweb\caffeine\search\ArtifactDecoder;
use justinholtweb\caffeine\search\ArtifactEncoder;
use justinholtweb\caffeine\search\Compiler;
use justinholtweb\caffeine\search\Engine;
use justinholtweb\caffeine\search\QueryState;
use justinholtweb\caffeine\services\Tokenizer;

$count = (int)($argv[1] ?? 37272);
$types = 8;
$perType = 16;

$config = json_decode(file_get_contents(__DIR__ . '/../Conformance/products.json'), true)['index'];

for ($t = 0; $t < $types; $t++) {
    for ($f = 0; $f < $perType; $f++) {
        $config['attributes'][] = ['key' => "a{$t}_{$f}", 'source' => 'field', 'path' => "a{$t}_{$f}", 'roles' => ['facet'], 'facetType' => 'string', 'facetOperator' => 'or'];
    }
}

$config['attributes'][] = ['key' => 'description', 'source' => 'field', 'path' => 'description', 'roles' => ['searchable']];

$index = IndexDefinition::fromConfig('bench-uid', $config);
$tokenizer = new Tokenizer();

// Seeded, and skewed so a few words are common and most are rare, the way prose is.
mt_srand(42);
$vocabulary = array_map(fn($i) => 'w' . base_convert((string)($i * 7919), 10, 36), range(1, 3000));
$records = [];

for ($i = 1; $i <= $count; $i++) {
    $type = $i % $types;
    $facets = [
        'brand' => ['Brand ' . mt_rand(1, 400)],
        'colour' => ['c' . mt_rand(1, 12)],
        'tags' => ['tag-' . mt_rand(1, 50), 'tag-' . mt_rand(1, 50)],
        'price' => [mt_rand(5, 5000)],
        'featured' => [mt_rand(0, 4) === 0],
        'category' => ['T' . $type . ' > S' . mt_rand(1, 6)],
    ];

    for ($f = 0; $f < $perType; $f++) {
        $facets["a{$type}_{$f}"] = ['v' . mt_rand(1, 3 + $f * 2)];
    }

    $words = [];

    for ($w = 0; $w < 60; $w++) {
        $words[] = $vocabulary[(int)floor(3000 * (mt_rand() / mt_getrandmax()) ** 2) % 3000];
    }

    $title = "Product {$i} " . $vocabulary[mt_rand(0, 2999)];

    $mapped = new MappedRecord(
        elementId: $i,
        siteId: 1,
        objectId: "{$i}-1",
        facets: $facets,
        sortable: ['title' => $title, 'price' => $facets['price'][0]],
        payload: [
            'title' => $title,
            'url' => "/shop/p/product-{$i}",
            'thumb' => "/img/{$i}.jpg",
            'price' => $facets['price'][0],
            'sku' => "SKU-{$i}",
            'brand' => $facets['brand'][0],
            'summary' => implode(' ', array_slice($words, 0, 25)),
        ],
        searchable: ['title' => $title, 'description' => implode(' ', $words)],
    );

    $records[] = [
        'elementId' => $i,
        'siteId' => 1,
        'objectId' => "{$i}-1",
        'content' => $mapped->toArray(),
        'tokens' => $tokenizer->tokenizeRecord($index, $mapped),
    ];
}

$startedAt = microtime(true);
$encoded = (new ArtifactEncoder())->encode((new Compiler())->compile($index, $records));
$compileMs = (microtime(true) - $startedAt) * 1000;
unset($records);

$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;
$indexJson = json_encode($encoded['index'], $flags);
$payloadJson = json_encode($encoded['payload'], $flags);
unset($encoded);
// The server-only lookup a sharded publish writes beside the shards (see `Publisher`).
$artifact = (new ArtifactDecoder())->decode(json_decode($indexJson, true), json_decode($payloadJson, true), 1);
$lookupJson = json_encode((new ArtifactEncoder())->encodeLookup($artifact), $flags);
$payloadsById = $artifact->payloads;
unset($artifact);
gc_collect_cycles();

printf("Records:            %s, %d facets\n", number_format($count), $types * $perType + 6);
printf("Compile + encode:   %.0f ms\n", $compileMs);
printf("Shards:             index %.1f MB, payload %.1f MB, %.1f MB gzipped\n\n", strlen($indexJson) / 1e6, strlen($payloadJson) / 1e6, strlen(gzencode($indexJson . $payloadJson, 6)) / 1e6);

$before = memory_get_usage();
$startedAt = microtime(true);
$artifact = (new ArtifactDecoder())->decode(json_decode($indexJson, true), json_decode($payloadJson, true), 1);
printf("Per request, decoding:\n");
printf("  read and decode   %6.0f ms\n", (microtime(true) - $startedAt) * 1000);
gc_collect_cycles();
printf("  held              %6.1f MB\n", (memory_get_usage() - $before) / 1e6);
$eager = $artifact;
unset($artifact);
gc_collect_cycles();

// Sharded, the server reads the index shard and the lookup and loads a page's payloads on
// demand. Here they come from an array; in Craft, one query against the records table.
$before = memory_get_usage();
$startedAt = microtime(true);
$artifact = (new ArtifactDecoder())->decode(
    json_decode($indexJson, true),
    null,
    1,
    json_decode($lookupJson, true),
    fn(array $ids) => array_intersect_key($payloadsById, array_flip($ids)),
);
printf("  sharded, payloads per page of hits:\n");
printf("    read and decode %6.0f ms\n", (microtime(true) - $startedAt) * 1000);
gc_collect_cycles();
printf("    held            %6.1f MB   (lookup %.1f MB on disk)\n\n", (memory_get_usage() - $before) / 1e6, strlen($lookupJson) / 1e6);
unset($artifact);
$artifact = $eager;
unset($eager);

$queries = [
    'unrefined' => [],
    'one facet value' => ['refinements' => ['a1_3' => ['v2']]],
    'two facets + range' => ['refinements' => ['a1_3' => ['v2'], 'colour' => ['c3']], 'ranges' => ['price' => ['min' => 100, 'max' => 900]]],
    'text query' => ['query' => $vocabulary[40]],
    'sorted, page 21' => ['sortBy' => 'price_asc', 'page' => 20],
];

$run = function(Artifact $artifact) use ($queries) {
    foreach ($queries as $name => $state) {
        $times = [];

        for ($i = 0; $i < 5; $i++) {
            $startedAt = microtime(true);
            $result = (new Engine($artifact))->search(QueryState::fromArray($state), 24);
            $times[] = (microtime(true) - $startedAt) * 1000;
        }

        sort($times);
        printf("  %-20s %6.1f ms   %s hits\n", $name, $times[2], number_format($result['nbHits']));
    }
};

printf("Queries (median of five):\n");
$run($artifact);

if (!function_exists('opcache_compile_file') || !filter_var(ini_get('opcache.enable_cli'), FILTER_VALIDATE_BOOL)) {
    printf("\nOPcache is off; run with -d opcache.enable_cli=1 to measure the artifact cache.\n");
    exit(0);
}

// What `ArtifactCache` writes, minus the plumbing.
$file = sys_get_temp_dir() . '/caffeine-heavy-catalogue.php';
$handle = fopen($file, 'wb');
fwrite($handle, "<?php return [\n");

foreach (['index', 'objectIds', 'payloads', 'facets', 'sortings', 'tokens', 'tokenIds', 'tokenWeights', 'tokenOffsets', 'sortableValues', 'stopwords', 'geo'] as $key) {
    fwrite($handle, var_export($key, true) . ' => ' . var_export($artifact->{$key}, true) . ",\n");
}

fwrite($handle, "];\n");
fclose($handle);
touch($file, time() - 60);
unset($artifact);
gc_collect_cycles();

$startedAt = microtime(true);
$compiled = opcache_compile_file($file) && opcache_is_script_cached($file);
printf("\nOPcache:\n  compile, once      %6.0f ms   %s\n", (microtime(true) - $startedAt) * 1000, $compiled ? 'cached' : 'NOT cached: raise opcache.memory_consumption');

if ($compiled) {
    $before = memory_get_usage();
    $startedAt = microtime(true);
    $data = include $file;
    $artifact = new Artifact(...$data, version: 1);
    printf("  per request       %6.1f ms   %.1f MB\n", (microtime(true) - $startedAt) * 1000, (memory_get_usage() - $before) / 1e6);

    $status = opcache_get_status(false);
    printf("  OPcache in use    %6.0f MB, interned strings %.0f MB\n\n", $status['memory_usage']['used_memory'] / 1e6, $status['interned_strings_usage']['used_memory'] / 1e6);

    printf("Queries against the cached artifact:\n");
    $run($artifact);
}

@unlink($file);
