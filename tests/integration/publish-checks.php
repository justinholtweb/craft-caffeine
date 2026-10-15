<?php
// Exercises the Phase 3 publish path against the plugin-testing harness.
//
// The conformance suite pins the encoder and the two decoders against each other with fixtures;
// this pins the parts fixtures cannot reach — a real filesystem, real project config, and the
// ledger. Everything it changes it changes back.
//
//   ddev exec bash -c "cd /var/www/html && php /var/www/craft-caffeine/tests/integration/publish-checks.php"

require '/var/www/html/vendor/autoload.php';
define('CRAFT_BASE_PATH', '/var/www/html');
$app = require '/var/www/html/vendor/craftcms/cms/bootstrap/console.php';

use justinholtweb\caffeine\models\AttributeDefinition;
use justinholtweb\caffeine\models\IndexDefinition;
use justinholtweb\caffeine\models\SourceDefinition;
use justinholtweb\caffeine\Plugin;

$plugin = Plugin::getInstance();
$index = $plugin->indexes->getByHandle('products');
$settings = $plugin->getSettings();

$failures = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;

    echo $ok ? "  ok   " : "  FAIL ", $label;
    echo $detail !== '' ? "  ({$detail})" : '';
    echo PHP_EOL;

    if (!$ok) {
        $failures++;
    }
}

$store = $plugin->publisher->store();
$originalShard = $index->shardPayload;
$originalKeep = $settings->keepVersions;

echo "Sharded publishing\n";

$index->shardPayload = true;
$plugin->indexes->save($index);
$index = $plugin->indexes->getByHandle('products');

$plugin->artifacts->forget($index);
$sharded = $plugin->artifacts->publish($index);
$manifest = $plugin->publisher->pointer($index);

check('publishes two shards', count($manifest['shards']) === 2, implode(', ', array_keys($manifest['shards'])));
check('manifest records sharding', ($manifest['sharded'] ?? null) === true);

$indexBytes = $manifest['shards']['index']['bytes'];
$payloadBytes = $manifest['shards']['payload']['bytes'];
check('payload is a separate document', $payloadBytes > 0, "index {$indexBytes} B, payload {$payloadBytes} B");

$reloaded = $plugin->artifacts->published($index);
check('sharded artifact reloads', $reloaded !== null && $reloaded->recordCount() === $sharded['records']);
check(
    'sharded artifact matches a fresh compile',
    $reloaded !== null && $reloaded->toArray() === $plugin->artifacts->compile($index, $reloaded->version)->toArray(),
);

echo "\nOrphan pruning\n";

// keepVersions=1 so the sharded files fall out of retention as soon as they are superseded,
// rather than surviving three more publishes.
$settings->keepVersions = 1;

$shardedFiles = array_map(
    fn(array $shard) => $plugin->publisher->path($index, $shard['file']),
    $manifest['shards'],
);

$index->shardPayload = false;
$plugin->indexes->save($index);
$index = $plugin->indexes->getByHandle('products');

$plugin->artifacts->forget($index);
$plugin->artifacts->publish($index);

$stillThere = array_filter($shardedFiles, fn(string $file) => $store->exists($file));
check('superseded shards are deleted', $stillThere === [], implode(', ', $stillThere));

$current = $plugin->publisher->pointer($index);
check('pointer names one shard again', count($current['shards']) === 1);
check(
    'the live shard survived pruning',
    $store->exists($plugin->publisher->path($index, $current['shards']['index']['file'])),
);

echo "\nIdempotence\n";

$again = $plugin->artifacts->publish($index);
check('an unchanged rebuild publishes nothing', $again['published'] === false, $again['reason']);
check('and spends no version', $again['version'] === $current['version']);

$pointerAfter = $plugin->publisher->pointer($index);
check('and leaves the pointer untouched', $pointerAfter['generatedAt'] === $current['generatedAt']);

echo "\nTeardown\n";

// A throwaway index, so deleting it cannot disturb the seeded pair.
$scratch = new IndexDefinition([
    'handle' => 'scratchIndex',
    'name' => 'Scratch',
    'sources' => [new SourceDefinition(['type' => 'entry', 'containers' => ['testEntries'], 'status' => 'live'])],
    'attributes' => [
        new AttributeDefinition([
            'key' => 'title',
            'source' => AttributeDefinition::SOURCE_ATTRIBUTE,
            'path' => 'title',
            'roles' => [AttributeDefinition::ROLE_SEARCHABLE, AttributeDefinition::ROLE_PAYLOAD],
        ]),
    ],
]);

$plugin->indexes->save($scratch);

// Committed before it is deleted, deliberately. Project config coalesces changes within a
// request, so adding and removing the same path in one run cancels out and no `onRemove` handler
// ever fires — which would make this check pass or fail for the wrong reason.
Craft::$app->getProjectConfig()->saveModifiedConfigData();

$scratch = $plugin->indexes->getByHandle('scratchIndex');
$plugin->builder->buildAll($scratch);
$plugin->artifacts->publish($scratch);

$scratchFiles = [$plugin->publisher->path($scratch, 'current.json')];
$scratchManifest = $plugin->publisher->pointer($scratch);

foreach ($scratchManifest['shards'] as $shard) {
    $scratchFiles[] = $plugin->publisher->path($scratch, $shard['file']);
}

check('a throwaway index publishes', $scratchManifest !== null);

$plugin->indexes->delete($scratch);
Craft::$app->getProjectConfig()->saveModifiedConfigData();

$survivors = array_filter($scratchFiles, fn(string $file) => $store->exists($file));
check('deleting an index removes its published files', $survivors === [], implode(', ', $survivors));
check('and its ledger rows', $plugin->artifacts->versions($scratch->uid) === []);

echo "\nProject-config handlers\n";

// The path a change takes on every *other* environment: it arrives through
// `project-config/apply`, and only the handlers run. Dispatched through `trigger()` rather than by
// calling the handlers directly, because it is Craft's dispatch that decides what `tokenMatches`
// holds — a hand-built event is how the handlers once passed while doing nothing at all.
$remote = new IndexDefinition([
    'handle' => 'remoteIndex',
    'name' => 'Remote',
    'sources' => [new SourceDefinition(['type' => 'entry', 'containers' => ['testEntries'], 'status' => 'live'])],
    'attributes' => [
        new AttributeDefinition([
            'key' => 'title',
            'source' => AttributeDefinition::SOURCE_ATTRIBUTE,
            'path' => 'title',
            'roles' => [AttributeDefinition::ROLE_SEARCHABLE, AttributeDefinition::ROLE_PAYLOAD],
        ]),
    ],
]);

$plugin->indexes->save($remote);
Craft::$app->getProjectConfig()->saveModifiedConfigData();

$remote = $plugin->indexes->getByHandle('remoteIndex');
$plugin->builder->buildAll($remote);
$plugin->artifacts->publish($remote);

$recordCount = fn(array $where) => (int)(new \craft\db\Query())
    ->from(\justinholtweb\caffeine\migrations\Install::TABLE_RECORDS)
    ->where(['indexUid' => $remote->uid] + $where)
    ->count();

$remoteFiles = [$plugin->publisher->path($remote, 'current.json')];

foreach ($plugin->publisher->pointer($remote)['shards'] as $shard) {
    $remoteFiles[] = $plugin->publisher->path($remote, $shard['file']);
}

$remotePath = \justinholtweb\caffeine\services\Indexes::CONFIG_PATH . '.' . $remote->uid;

Craft::$app->getDb()->createCommand()
    ->update(\justinholtweb\caffeine\migrations\Install::TABLE_RECORDS, ['dirty' => false], ['indexUid' => $remote->uid])
    ->execute();

Craft::$app->getProjectConfig()->trigger(\craft\services\ProjectConfig::EVENT_UPDATE_ITEM, new \craft\events\ConfigEvent([
    'path' => $remotePath,
    'oldValue' => ['handle' => 'remoteIndex'],
    'newValue' => ['handle' => 'remoteIndex', 'name' => 'Remote, changed'],
]));

check('a changed definition arriving by project config marks its records dirty', $recordCount(['dirty' => false]) === 0);

Craft::$app->getProjectConfig()->trigger(\craft\services\ProjectConfig::EVENT_REMOVE_ITEM, new \craft\events\ConfigEvent([
    'path' => $remotePath,
    'oldValue' => ['handle' => 'remoteIndex'],
    'newValue' => null,
]));

$survivors = array_filter($remoteFiles, fn(string $file) => $store->exists($file));
check('a deletion arriving by project config removes its records', $recordCount([]) === 0);
check('and its published files', $survivors === [], implode(', ', $survivors));
check('and its ledger rows', $plugin->artifacts->versions($remote->uid) === []);

// The definition itself is still in this environment's config; nothing above touched it.
$plugin->indexes->delete($remote);
Craft::$app->getProjectConfig()->saveModifiedConfigData();

echo "\nUnreferenced files\n";

// What a database restored to before a publish leaves behind: content-addressed files on disk
// that no ledger row lists. Pruning only deletes what rows list, so only the sweep can see these.
$stray = $plugin->publisher->path($index, '0123456789abcdef.index.json');
$store->write($stray, '{}');
$store->write("{$stray}.gz", 'x');

$notOurs = $plugin->publisher->path($index, 'README.txt');
$store->write($notOurs, 'left by someone else');

$staleTemp = $plugin->publisher->path($index, 'current.json.tmp.0123456789ab');
$freshTemp = $plugin->publisher->path($index, 'current.json.tmp.ba9876543210');
$store->write($staleTemp, '');
$store->write($freshTemp, '');
touch($store->describe() . '/' . $staleTemp, time() - 7200);

$liveFiles = [$plugin->publisher->path($index, 'current.json')];

foreach ($plugin->publisher->pointer($index)['shards'] as $shard) {
    $liveFiles[] = $plugin->publisher->path($index, $shard['file']);
}

// A publish holds the lock: the sweep must stand aside rather than race it.
$mutex = Craft::$app->getMutex();
$mutex->acquire("caffeine:publish:{$index->uid}");
check('the sweep stands aside while a publish holds the lock', $plugin->artifacts->sweep($index) === null);
check('and deletes nothing', $store->exists($stray));
$mutex->release("caffeine:publish:{$index->uid}");

$swept = $plugin->artifacts->sweep($index) ?? [];

check('a content-addressed file no row lists is deleted', !$store->exists($stray) && !$store->exists("{$stray}.gz"), implode(', ', $swept));
check('a stale temporary file is deleted', !$store->exists($staleTemp));
check('a fresh one is left, since a write may still be using it', $store->exists($freshTemp));
check('a file Caffeine did not name is left alone', $store->exists($notOurs));
check('everything the live pointer names survives', array_filter($liveFiles, fn($f) => !$store->exists($f)) === []);

$store->delete($notOurs);
$store->delete($freshTemp);

echo "\nRestoring\n";

$settings->keepVersions = $originalKeep;
$index->shardPayload = $originalShard;
$plugin->indexes->save($index);
$plugin->artifacts->forget($plugin->indexes->getByHandle('products'));
$plugin->artifacts->publish($plugin->indexes->getByHandle('products'));

echo "  shardPayload back to " . var_export($originalShard, true) . ", keepVersions back to {$originalKeep}\n";

echo $failures === 0 ? "\nAll checks passed.\n" : "\n{$failures} check(s) failed.\n";

exit($failures === 0 ? 0 : 1);
