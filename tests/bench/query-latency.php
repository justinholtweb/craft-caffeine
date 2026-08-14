<?php

/**
 * How long a query takes, in the PHP engine.
 *
 * The number that matters for a server-rendered first paint, and the one the README publishes
 * instead of a claim. Also writes the compiled artifact where query-latency.mjs can read it, so
 * both engines are timed against exactly the same data.
 *
 *   ddev exec bash -c "cd /var/www/craft-caffeine && php tests/bench/query-latency.php 10000"
 *   node tests/bench/query-latency.mjs 10000
 */

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/fixture.php';

use justinholtweb\caffeine\search\ArtifactEncoder;
use justinholtweb\caffeine\search\Compiler;
use justinholtweb\caffeine\search\Engine;
use justinholtweb\caffeine\search\QueryState;

$count = (int)($argv[1] ?? 10000);
$iterations = (int)($argv[2] ?? 20);

[$index, $records] = caffeineBenchFixture($count);

$artifact = (new Compiler())->compile($index, $records);

$dir = __DIR__ . '/build';

if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}

$encoded = (new ArtifactEncoder())->encode($artifact);

file_put_contents(
    "{$dir}/bench-{$count}.encoded.json",
    json_encode(array_merge($encoded['index'], $encoded['payload']), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
);

$engine = new Engine($artifact);

printf("PHP engine — %s records, %d iterations\n\n", number_format($count), $iterations);
printf("%-26s %9s %9s %9s\n", '', 'median', 'mean', 'p95');

foreach (caffeineBenchQueries() as $label => $state) {
    $query = QueryState::fromArray($state);
    $timings = [];

    // One untimed pass first: the very first query pays for lazy autoloading that no real
    // request would, and including it would report a number nothing ever experiences.
    $engine->search($query, $index->hitsPerPage);

    for ($i = 0; $i < $iterations; $i++) {
        $startedAt = hrtime(true);
        $engine->search($query, $index->hitsPerPage);
        $timings[] = (hrtime(true) - $startedAt) / 1_000_000;
    }

    sort($timings);

    printf(
        "%-26s %8.2f %8.2f %8.2f  ms\n",
        $label,
        $timings[intdiv(count($timings), 2)],
        array_sum($timings) / count($timings),
        $timings[min(count($timings) - 1, (int)floor(count($timings) * 0.95))],
    );
}

printf("\nArtifact written to tests/bench/build/bench-%d.encoded.json\n", $count);
