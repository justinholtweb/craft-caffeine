<?php
// Exercises the build-side and read-side changes from issue #5 against the plugin-testing
// harness: the source query event, parallel builds, and payloads loaded per page of hits.
//
//   ddev exec bash -c "cd /var/www/html && php /var/www/craft-caffeine/tests/integration/build-checks.php"

require '/var/www/html/vendor/autoload.php';
define('CRAFT_BASE_PATH', '/var/www/html');
$app = require '/var/www/html/vendor/craftcms/cms/bootstrap/console.php';

use craft\db\Query;
use craft\helpers\Json;
use justinholtweb\caffeine\events\DefineSourceQueryEvent;
use justinholtweb\caffeine\migrations\Install;
use justinholtweb\caffeine\Plugin;
use justinholtweb\caffeine\search\Engine;
use justinholtweb\caffeine\search\QueryState;
use justinholtweb\caffeine\services\Builder;
use yii\base\Event;

$failures = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;

    echo $ok ? '  ok   ' : '  FAIL ', $label, $detail !== '' ? "  ({$detail})" : '', PHP_EOL;

    if (!$ok) {
        $failures++;
    }
}

/** objectId → content, for comparing two builds. */
function snapshot(string $indexUid): array
{
    return (new Query())
        ->select(['content', 'objectId'])
        ->from(Install::TABLE_RECORDS)
        ->where(['indexUid' => $indexUid, 'deleted' => false])
        ->orderBy(['objectId' => SORT_ASC])
        ->indexBy('objectId')
        ->column();
}

function queries(callable $callback): int
{
    $before = Craft::getLogger()->getDbProfiling()[0];
    $callback();

    return Craft::getLogger()->getDbProfiling()[0] - $before;
}

function console(string $arguments): array
{
    exec('cd /var/www/html && php craft ' . $arguments . ' 2>&1', $output, $exit);

    return [$exit, implode("\n", $output)];
}

Craft::$app->getDb()->enableProfiling = true;
Craft::getLogger()->flushInterval = PHP_INT_MAX;

$plugin = Plugin::getInstance();
$news = $plugin->indexes->getByHandle('newsArchive');

echo "The source query event\n";

$calls = [];
$eager = false;

Event::on(Builder::class, Builder::EVENT_DEFINE_SOURCE_QUERY, function(DefineSourceQueryEvent $event) use (&$calls, &$eager) {
    $calls[] = [$event->index->handle, $event->source::handle(), $event->definition->type, $event->siteId];

    if ($eager) {
        // Relation fields only. Craft takes any handle here, and a plain field named in `with()`
        // reads back as an empty set of related elements rather than as its value.
        $event->query->with(['newsTopics', 'newsTagsField']);
    }
});

$lazyQueries = queries(fn() => $plugin->builder->buildAll($news));
// Taken after a plain build rather than before it, so a run interrupted earlier cannot leave
// this comparing against whatever it left behind.
$before = snapshot($news->uid);
$siteCount = count($plugin->builder->siteIds($news));

check('fires once per site and source on a full build', count($calls) === $siteCount, count($calls) . ' call(s)');
check('naming the index, source, definition and site', ($calls[0] ?? null) === ['newsArchive', 'entry', 'entry', $plugin->builder->siteIds($news)[0]]);

$calls = [];
$plugin->builder->countElements($news);
$plugin->builder->elementIds($news);
check('does not fire for counting or listing ids', $calls === []);

$calls = [];
$ids = array_slice($plugin->builder->elementIds($news), 0, 5);
$plugin->builder->buildElements($news, $ids);
check('fires when changed elements are rebuilt', count($calls) === $siteCount);

$eager = true;
$eagerQueries = queries(fn() => $plugin->builder->buildAll($news));
$eager = false;

check('eager loading declared there cuts the build’s queries', $eagerQueries < $lazyQueries, "{$lazyQueries} → {$eagerQueries}");
check('without changing a single record', snapshot($news->uid) === $before);

echo "\nParallel builds\n";

// A record for a real element that the index does not cover, which a full build has to prune.
$outsider = (int)craft\elements\Entry::find()->section('testEntries')->status(null)->ids()[0];
$strayId = "{$outsider}-1";
Craft::$app->getDb()->createCommand()->insert(Install::TABLE_RECORDS, [
    'indexUid' => $news->uid,
    'elementId' => $outsider,
    'siteId' => 1,
    'objectId' => $strayId,
    'content' => Json::encode(['payload' => ['title' => 'stray']]),
    'tokens' => '[]',
    'dirty' => false,
    'deleted' => false,
    'dateCreated' => craft\helpers\Db::prepareDateForDb(new DateTime()),
    'dateUpdated' => craft\helpers\Db::prepareDateForDb(new DateTime()),
    'uid' => craft\helpers\StringHelper::UUID(),
])->execute();

[$exit, $output] = console('caffeine/index/build newsArchive --all --workers=3');
preg_match('/(\d+) records, (\d+) pruned/', $output, $m);

check('a three-worker build succeeds', $exit === 0, trim(substr($output, -160)));
check('builds every record', (int)($m[1] ?? -1) === count($before), ($m[1] ?? '?') . ' of ' . count($before));
check('identical to a single-process build', snapshot($news->uid) === $before);
check('and prunes once, after every worker', (int)($m[2] ?? 0) === 1 && !isset(snapshot($news->uid)[$strayId]));

[$exit, $output] = console('caffeine/index/build newsArchive --all --workers=500');
check('more workers than elements is fine', $exit === 0 && snapshot($news->uid) === $before);

[$exit] = console('caffeine/index/build newsArchive --all --worker-range=nonsense --worker-result=/tmp/x.json');
check('a malformed worker invocation is refused', $exit !== 0);

echo "\nPayloads per page of hits\n";

$index = $plugin->indexes->getByHandle('products');
$originalShard = $index->shardPayload;

try {
    $index->shardPayload = false;
    $plugin->indexes->save($index);
    $index = $plugin->indexes->getByHandle('products');
    $plugin->artifacts->publish($index);
    check('a merged artifact has no lookup', !isset($plugin->publisher->pointer($index)['lookup']));
    check('and holds its payloads', $plugin->artifacts->published($index)?->hasPayloads() === true);

    $index->shardPayload = true;
    $plugin->indexes->save($index);
    $index = $plugin->indexes->getByHandle('products');
    $plugin->artifacts->publish($index);
    $manifest = $plugin->publisher->pointer($index);
    $store = $plugin->publisher->store();

    check('a sharded artifact names a lookup beside its shards', isset($manifest['lookup']['file']) && !isset($manifest['shards']['lookup']));
    check('and the lookup is written', $store->exists($plugin->publisher->path($index, (string)($manifest['lookup']['file'] ?? ''))));
    check('and listed in the ledger, so pruning can find it', in_array(
        $plugin->publisher->path($index, (string)($manifest['lookup']['file'] ?? '')),
        (array)Json::decode((string)$plugin->artifacts->live($index->uid)['files']),
        true,
    ));
    check('republishing it unchanged is a no-op', $plugin->artifacts->publish($index)['published'] === false);

    $artifact = $plugin->artifacts->published($index);
    $fresh = $plugin->artifacts->compile($index, $artifact->version);

    check('it is read without its payloads', $artifact->hasPayloads() === false && $artifact->payloads === []);
    check('and still matches a fresh compile exactly', $artifact->toArray() === $fresh->toArray());

    $hits = fn($a) => (new Engine($a))->search(new QueryState(), 100)['hits'];
    check('a search shows the same hits as from held payloads', $hits($artifact) === $hits($fresh), count($hits($fresh)) . ' hit(s)');

    $loads = queries(fn() => $artifact->payloadsFor([0, 1, 2]));
    check('a page of payloads is one query', $loads === 1, "{$loads} queries");

    // A record rebuilt since the publish: the server must still show what was published.
    $target = $artifact->objectIds[0];
    $published = $artifact->payloadsFor([0])[0];
    $row = (new Query())->from(Install::TABLE_RECORDS)->where(['indexUid' => $index->uid, 'objectId' => $target])->one();
    $content = Json::decode($row['content']);
    $content['payload']['title'] = 'Changed since the publish';
    Craft::$app->getDb()->createCommand()->update(Install::TABLE_RECORDS, ['content' => Json::encode($content)], ['id' => $row['id']])->execute();

    $artifact = $plugin->artifacts->published($index);
    check('a record changed since the publish is shown as published', $artifact->payloadsFor([0, 1])[0] === $published);

    Craft::$app->getDb()->createCommand()->delete(Install::TABLE_RECORDS, ['id' => $row['id']])->execute();
    $artifact = $plugin->artifacts->published($index);
    check('so is one removed since', $artifact->payloadsFor([0])[0] === $published);
    check('and the rest still come from the records', $artifact->payloadsFor([1])[1] === $fresh->payloads[1]);

    $deleted = $plugin->artifacts->sweep($index) ?? [];
    check('sweeping leaves the lookup alone', !in_array($plugin->publisher->path($index, $manifest['lookup']['file']), $deleted, true));
} finally {
    echo "\nRestoring\n";

    $index = $plugin->indexes->getByHandle('products');
    $index->shardPayload = $originalShard;
    $plugin->indexes->save($index);
    $index = $plugin->indexes->getByHandle('products');
    $plugin->builder->buildAll($index);
    $plugin->artifacts->publish($index);
    $plugin->builder->buildAll($news);
    $plugin->artifacts->publish($news);

    echo "  shardPayload back to " . var_export($originalShard, true) . ", products and newsArchive rebuilt and republished\n";
}

echo $failures === 0 ? "\nAll checks passed.\n" : "\n{$failures} check(s) FAILED.\n";
exit($failures === 0 ? 0 : 1);
