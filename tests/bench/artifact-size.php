<?php

/**
 * Measures what the wire format actually costs and saves, at a realistic index size.
 *
 * The encoded form is *larger* than plain JSON on a handful of records — base64 adds a third,
 * and a postings list of two ids is cheaper as `[0,1]` than as a base64 blob with a length
 * sidecar. The design bets that this reverses well before any real index, and a bet like that
 * belongs in a measurement rather than in a comment.
 *
 *   ddev exec bash -c "cd /var/www/craft-caffeine && php tests/bench/artifact-size.php 10000"
 */

require __DIR__ . '/../../vendor/autoload.php';

require __DIR__ . '/fixture.php';

use justinholtweb\caffeine\search\ArtifactEncoder;
use justinholtweb\caffeine\search\Compiler;

$count = (int)($argv[1] ?? 10000);

[$index, $records] = caffeineBenchFixture($count);

$startedAt = microtime(true);
$artifact = (new Compiler())->compile($index, $records);
$compileMs = (microtime(true) - $startedAt) * 1000;

$startedAt = microtime(true);
$encoded = (new ArtifactEncoder())->encode($artifact);
$encodeMs = (microtime(true) - $startedAt) * 1000;

$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

$plain = json_encode($artifact->toArray(), $flags);
$whole = json_encode(array_merge($encoded['index'], $encoded['payload']), $flags);
$indexShard = json_encode($encoded['index'], $flags);
$payloadShard = json_encode($encoded['payload'], $flags);

printf("Records:            %s\n", number_format($count));
printf("Distinct tokens:    %s\n", number_format(count($artifact->tokens)));
printf("Compile:            %.0f ms\n", $compileMs);
printf("Encode:             %.0f ms\n\n", $encodeMs);

printf("%-22s %12s %12s %8s\n", '', 'raw', 'gzip -9', 'ratio');
row('Plain JSON', $plain);
row('Encoded (one file)', $whole);
row('  index shard', $indexShard);
row('  payload shard', $payloadShard);

$plainGz = strlen(gzencode($plain, 9));
$wholeGz = strlen(gzencode($whole, 9));
$indexGz = strlen(gzencode($indexShard, 9));

printf("\nIndex shard, by component (gzipped):\n");

$components = [
    'facet postings' => array_map(fn($f) => ['postings' => $f['postings'], 'postingLengths' => $f['postingLengths']], $encoded['index']['facets']),
    'facet reverse map' => array_map(fn($f) => ['records' => $f['records'], 'recordLengths' => $f['recordLengths']], $encoded['index']['facets']),
    'facet values' => array_map(fn($f) => $f['values'], $encoded['index']['facets']),
    'token dictionary' => $encoded['index']['tokens'],
    'token postings' => [$encoded['index']['tokenIds'], $encoded['index']['tokenWeights'], $encoded['index']['tokenLengths']],
    'sortings' => $encoded['index']['sortings'],
    'sortable values' => $encoded['index']['sortableValues'],
];

foreach ($components as $label => $value) {
    $gz = strlen(gzencode(json_encode($value, $flags), 9));
    printf("  %-20s %10s  %5.1f%%\n", $label, bytes($gz), $gz / $indexGz * 100);
}

printf("\nEncoded vs plain:   %+.1f%% raw, %+.1f%% gzipped\n",
    (strlen($whole) / strlen($plain) - 1) * 100,
    ($wholeGz / $plainGz - 1) * 100);
printf("Facet-only query:   %s gzipped, %.1f%% of the whole artifact\n",
    bytes($indexGz), $indexGz / $wholeGz * 100);

function row(string $label, string $json): void
{
    $gz = gzencode($json, 9);

    printf("%-22s %12s %12s %7.1fx\n",
        $label,
        bytes(strlen($json)),
        bytes(strlen($gz)),
        strlen($json) / strlen($gz));
}

function bytes(int $n): string
{
    if ($n < 1024) {
        return "{$n} B";
    }

    if ($n < 1024 * 1024) {
        return sprintf('%.1f KB', $n / 1024);
    }

    return sprintf('%.2f MB', $n / 1024 / 1024);
}
