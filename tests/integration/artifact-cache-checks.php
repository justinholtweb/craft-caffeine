<?php
// Exercises the OPcache artifact cache against the plugin-testing harness.
//
// OPcache is off in the harness's CLI, so it is turned on for the run. `file_update_protection`
// is left at its default on purpose: the cache has to work despite it.
//
//   ddev exec bash -c "cd /var/www/html && php -d opcache.enable=1 -d opcache.enable_cli=1 /var/www/craft-caffeine/tests/integration/artifact-cache-checks.php"

require '/var/www/html/vendor/autoload.php';
define('CRAFT_BASE_PATH', '/var/www/html');
$app = require '/var/www/html/vendor/craftcms/cms/bootstrap/console.php';

use justinholtweb\caffeine\Plugin;

$failures = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;

    echo $ok ? '  ok   ' : '  FAIL ', $label, $detail !== '' ? "  ({$detail})" : '', PHP_EOL;

    if (!$ok) {
        $failures++;
    }
}

// Run in a child process with OPcache too small for any artifact, to see the refusal handled.
if (($argv[1] ?? '') === '--refused') {
    $plugin = Plugin::getInstance();
    $plugin->getSettings()->cacheArtifacts = true;
    $index = $plugin->indexes->getByHandle('products');
    $manifest = $plugin->publisher->pointer($index);
    $path = $plugin->artifactCache->path($index, $manifest);

    $artifact = $plugin->artifacts->published($index);

    check('an artifact OPcache will not hold is still served, decoded', $artifact !== null && $artifact->recordCount() > 0);
    check('the refusal is remembered, so later requests do not each pay for a compile', file_exists("{$path}.failed"));
    check('and nothing is read from the cache', $plugin->artifactCache->get($index, $manifest) === null);

    exit($failures === 0 ? 0 : 1);
}

if (!filter_var(ini_get('opcache.enable_cli'), FILTER_VALIDATE_BOOL)) {
    echo "OPcache is off for the CLI. Run with -d opcache.enable=1 -d opcache.enable_cli=1.\n";
    exit(1);
}

$plugin = Plugin::getInstance();
$settings = $plugin->getSettings();
$cache = $plugin->artifactCache;
$original = $settings->cacheArtifacts;

$index = $plugin->indexes->getByHandle('products');
$originalShard = $index->shardPayload;

$plugin->artifacts->publish($index);
$manifest = $plugin->publisher->pointer($index);
$path = $cache->path($index, $manifest);
$directory = dirname($path);

// A clean start, whatever an earlier run left.
foreach (glob("{$directory}/*") ?: [] as $file) {
    unlink($file);
}

try {
    echo "Off\n";

    $settings->cacheArtifacts = false;
    $plain = $plugin->artifacts->published($index);

    check('off by default, so nothing is written', !file_exists($path));

    echo "\nFirst read\n";

    $settings->cacheArtifacts = true;
    $first = $plugin->artifacts->published($index);

    check('the first read decodes, and returns the same artifact', $first?->toArray() === $plain?->toArray());
    check('and writes the file', file_exists($path));
    check('which OPcache holds, despite file_update_protection', opcache_is_script_cached($path));
    check('no temporary file is left behind', glob("{$directory}/*.tmp.*") === []);

    echo "\nLater reads\n";

    $memory = memory_get_usage();
    $cached = $cache->get($index, $manifest);
    $cost = memory_get_usage() - $memory;

    check('come from OPcache', $cached !== null);
    check('identical to a decode', $cached?->toArray() === $plain?->toArray());
    check('carry the version from the pointer', $cached?->version === (int)$manifest['version']);
    check('and cost the request next to no memory', $cost < 64 * 1024, round($cost / 1024) . ' KB');

    $engine = new \justinholtweb\caffeine\search\Engine($cached);
    $result = $engine->search(\justinholtweb\caffeine\search\QueryState::fromArray([]), 24);

    check('and can be queried', $result['nbHits'] === $plain->recordCount());

    echo "\nA new publish\n";

    // Sharding changes the shard files without changing the records, which is the cheapest
    // way to get a genuinely different pointer.
    $index->shardPayload = !$originalShard;
    $plugin->indexes->save($index);
    $index = $plugin->indexes->getByHandle('products');
    $plugin->artifacts->publish($index);

    $newManifest = $plugin->publisher->pointer($index);
    $newPath = $cache->path($index, $newManifest);

    check('is cached under a new name', $newPath !== $path);
    check('nothing stale is served for it', $cache->get($index, $newManifest) === null);

    $plugin->artifacts->published($index);

    check('the next read caches it', opcache_is_script_cached($newPath));
    check('and deletes the old file', !file_exists($path));

    $fromCache = $cache->get($index, $newManifest, $index->shardPayload ? fn(array $ids) => [] : null);
    check('a sharded artifact is cached without its payloads', !$index->shardPayload || ($fromCache !== null && !$fromCache->hasPayloads()));

    if ($index->shardPayload) {
        $served = $plugin->artifacts->published($index);
        $fresh = $plugin->artifacts->compile($index, $served->version);
        $hits = fn($a) => (new \justinholtweb\caffeine\search\Engine($a))->search(new \justinholtweb\caffeine\search\QueryState(), 100)['hits'];

        check('and still serves every hit’s payload from there', $hits($served) === $hits($fresh) && $hits($fresh) !== []);
        check('but not without a way to load them', $cache->get($index, $newManifest) === null);
    }

    echo "\nTurned off\n";

    $settings->cacheArtifacts = false;

    check('nothing is read from the cache, even what OPcache still holds', $cache->get($index, $newManifest) === null);

    echo "\nRefused\n";

    $settings->cacheArtifacts = true;

    foreach (glob("{$directory}/*") ?: [] as $file) {
        unlink($file);
    }

    passthru(sprintf(
        'php -d opcache.enable=1 -d opcache.enable_cli=1 -d opcache.max_file_size=1024 %s --refused',
        escapeshellarg(__FILE__),
    ), $exit);

    if ($exit !== 0) {
        $failures++;
    }

    echo "\nOrphans\n";

    $orphan = dirname($directory) . '/no-such-index-uid';
    mkdir($orphan, 0775, true);
    file_put_contents("{$orphan}/0123456789abcdef.php", '<?php return [];');

    $removed = $cache->deleteOrphans(array_keys($plugin->indexes->allByUid()));

    check('the cache of a deleted index is removed', !is_dir($orphan), "{$removed} removed");
    check('and a live index keeps its own', is_dir($directory));

    echo "\nRestoring\n";
} finally {
    $settings->cacheArtifacts = $original;
    $index = $plugin->indexes->getByHandle('products');

    if ($index->shardPayload !== $originalShard) {
        $index->shardPayload = $originalShard;
        $plugin->indexes->save($index);
        $plugin->artifacts->publish($plugin->indexes->getByHandle('products'));
    }

    foreach (glob("{$directory}/*") ?: [] as $file) {
        unlink($file);
    }

    echo "  shardPayload back to " . var_export($originalShard, true) . "\n";
}

echo $failures === 0 ? "\nAll checks passed.\n" : "\n{$failures} check(s) failed.\n";

exit($failures === 0 ? 0 : 1);
