<?php
// Exercises Phase 5's auto-update against the plugin-testing harness.
//
// Nothing here can be a fixture: it is entirely about what Craft's element events do, when they
// fire, and how a bulk operation changes that. It leaves the indexes rebuilt and republished.
//
//   ddev exec bash -c "cd /var/www/html && php /var/www/craft-caffeine/tests/integration/autoupdate-checks.php"

require '/var/www/html/vendor/autoload.php';
define('CRAFT_BASE_PATH', '/var/www/html');
$app = require '/var/www/html/vendor/craftcms/cms/bootstrap/console.php';

use craft\elements\Entry;
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

$plugin = Plugin::getInstance();
$elements = Craft::$app->getElements();
$queue = Craft::$app->getQueue();
$index = $plugin->indexes->getByHandle('products');

/**
 * Caffeine's queued jobs, optionally for one index.
 *
 * Counted per index on purpose. A save frequently touches more than one — the entry is a record
 * in `products` and something the `related` index reads — and one job each is the correct
 * outcome, not a coalescing failure.
 */
$queued = function(?string $name = null) use ($queue): int {
    $count = 0;

    foreach ($queue->getJobInfo() as $job) {
        $description = (string)($job['description'] ?? '');

        if (!str_contains($description, 'search index')) {
            continue;
        }

        if ($name === null || str_contains($description, $name)) {
            $count++;
        }
    }

    return $count;
};

$drain = function() use ($queue, $plugin, $index) {
    Craft::$app->getDb()->createCommand()->delete('{{%queue}}')->execute();
    $plugin->autoUpdate->clearPending($index->uid);
};

$entries = Entry::find()->section('testEntries')->limit(3)->all();

if (count($entries) < 2) {
    echo "Needs at least two entries in testEntries.\n";
    exit(1);
}

echo "A save marks and schedules\n";

$drain();
$plugin->builder->buildAll($index);
$plugin->artifacts->publish($index);

$before = $plugin->records->countFor($index->uid, true);
$entries[0]->title = $entries[0]->title;
$elements->saveElement($entries[0]);

check('the saved element goes dirty', $plugin->records->countFor($index->uid, true) > $before,
    $plugin->records->countFor($index->uid, true) . ' dirty');
check('an update is queued for it', $queued('Products') === 1, $queued('Products') . ' queued');
check('and the index is marked pending', $plugin->autoUpdate->isPending($index->uid));

echo "\nThe debounce coalesces\n";

$elements->saveElement($entries[1]);
check('a second save queues nothing more', $queued('Products') === 1, $queued('Products') . ' queued');
check('but still marks it dirty', $plugin->records->countFor($index->uid, true) >= 2,
    $plugin->records->countFor($index->uid, true) . ' dirty');

echo "\nA bulk operation collapses to one job\n";

$drain();

$key = $elements->beginBulkOp();

foreach ($entries as $entry) {
    $elements->saveElement($entry);
}

check('nothing is queued while the operation runs', $queued('Products') === 0, $queued('Products') . ' queued');

$elements->endBulkOp($key);

check('one job is queued when it finishes', $queued('Products') === 1, $queued('Products') . ' queued');
check('every element it touched is dirty', $plugin->records->countFor($index->uid, true) >= count($entries),
    $plugin->records->countFor($index->uid, true) . ' dirty');

echo "\nThe dependency map carries a related change through\n";

$related = $plugin->indexes->getByHandle('related');

$drain();
$plugin->builder->buildAll($related);
$plugin->artifacts->publish($related);

check('the related index starts clean', $plugin->records->countFor($related->uid, true) === 0);

// The entry the `related` index descends *into* — it has no record of its own there, so the only
// thing that can mark the dependent record dirty is caffeine_deps.
$target = Entry::find()->section('testEntries')->one();
$dependents = $plugin->records->indexUidsDependingOn([(int)$target->id]);

check('caffeine_deps knows who reads it', in_array($related->uid, $dependents, true),
    count($dependents) . ' dependent index(es)');
check('and only indexes that still exist', array_filter($dependents, fn($uid) => $plugin->indexes->getByUid($uid) === null) === [],
    'orphans are collected by craft gc');

$elements->saveElement($target);

check('saving it marks the dependent record dirty', $plugin->records->countFor($related->uid, true) > 0,
    $plugin->records->countFor($related->uid, true) . ' dirty');

echo "\nThe job rebuilds and republishes\n";

$drain();
$versionBefore = (int)($plugin->artifacts->live($index->uid)['version'] ?? 0);

$plugin->builder->buildAll($index);
$elements->saveElement($entries[0]);

$job = new justinholtweb\caffeine\queue\jobs\UpdateJob(['indexUid' => $index->uid]);
$job->execute($queue);

check('the dirty rows are gone', $plugin->records->countFor($index->uid, true) === 0,
    $plugin->records->countFor($index->uid, true) . ' dirty');
check('the pending marker is cleared', !$plugin->autoUpdate->isPending($index->uid));

$after = $plugin->artifacts->live($index->uid);
check('the artifact is live and current', ($after['status'] ?? null) === 'live',
    'v' . ($after['version'] ?? '?'));

$published = $plugin->artifacts->published($index);
check('and matches a fresh compile',
    $published !== null && $published->toArray() === $plugin->artifacts->compile($index, $published->version)->toArray());

echo "\nRestoring\n";

$drain();

foreach ([$index, $related] as $each) {
    $plugin->builder->buildAll($each);
    $plugin->artifacts->publish($each);
}

$drain();

echo "  rebuilt and republished, queue drained\n";
echo $failures === 0 ? "\nAll checks passed.\n" : "\n{$failures} check(s) failed.\n";

exit($failures === 0 ? 0 : 1);
