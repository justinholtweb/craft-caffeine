<?php
// Exercises Indexes::EVENT_DEFINE_INDEX against the plugin-testing harness.
//
// Fixtures cannot reach this: it is about what reaches project config, what the build and the
// query see, and whether a change in what a module adds is noticed without a deploy touching
// project config. Everything it changes it changes back.
//
//   ddev exec bash -c "cd /var/www/html && php /var/www/craft-caffeine/tests/integration/define-index-checks.php"

require '/var/www/html/vendor/autoload.php';
define('CRAFT_BASE_PATH', '/var/www/html');
$app = require '/var/www/html/vendor/craftcms/cms/bootstrap/console.php';

use justinholtweb\caffeine\events\DefineIndexEvent;
use justinholtweb\caffeine\models\AttributeDefinition;
use justinholtweb\caffeine\Plugin;
use justinholtweb\caffeine\services\Indexes;
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

$plugin = Plugin::getInstance();
$settings = $plugin->getSettings();
$originalAutoUpdate = $settings->autoUpdate;
$settings->autoUpdate = false;

// What a module would add: one facet per attribute in some content-managed set. `$specs` stands
// in for that set, so the checks can grow it the way an editor would.
$specs = ['dyn_title' => 'title'];

$handler = function(DefineIndexEvent $event) use (&$specs) {
    if ($event->index->handle !== 'products') {
        return;
    }

    foreach ($specs as $key => $path) {
        $event->index->attributes[] = new AttributeDefinition([
            'key' => $key,
            'label' => ucfirst($key),
            'source' => AttributeDefinition::SOURCE_ATTRIBUTE,
            'path' => $path,
            'roles' => [AttributeDefinition::ROLE_FACET],
        ]);
    }

    // Two mistakes a module could make. Each should cost only itself.
    $event->index->attributes[] = new AttributeDefinition(['key' => 'title', 'path' => 'title', 'roles' => [AttributeDefinition::ROLE_FACET]]);
    $event->index->attributes[] = new AttributeDefinition(['key' => '', 'path' => 'title']);
};

$recordsDirty = fn(string $uid) => $plugin->records->countFor($uid, true);

$storedConfig = Craft::$app->getProjectConfig()->get(Indexes::CONFIG_PATH);

try {
    Event::on(Indexes::class, Indexes::EVENT_DEFINE_INDEX, $handler);
    $plugin->indexes->reset();

    echo "Loading\n";

    $index = $plugin->indexes->getByHandle('products');
    $stored = $plugin->indexes->getStoredByHandle('products');
    $added = $index->getAttribute('dyn_title');

    check('a handler can add an attribute', $added !== null);
    check('and it is flagged dynamic without the handler saying so', $added?->dynamic === true);
    check('stored attributes are not', array_filter($index->attributes, fn($a) => $a->dynamic && $a->key !== 'dyn_title') === []);
    check('a duplicate key is dropped rather than shadowing the stored one',
        count(array_filter($index->attributes, fn($a) => $a->key === 'title')) === 1 && !$index->getAttribute('title')->dynamic);
    check('an invalid attribute is dropped', $index->getAttribute('') === null);
    check('the stored definition is untouched', $stored->getAttribute('dyn_title') === null);

    echo "\nSaving\n";

    $plugin->indexes->save($index);
    Craft::$app->getProjectConfig()->saveModifiedConfigData();

    $keys = array_column(Craft::$app->getProjectConfig()->get(Indexes::CONFIG_PATH . '.' . $index->uid . '.attributes') ?? [], 'key');

    check('saving the defined index keeps module attributes out of project config', !in_array('dyn_title', $keys, true), implode(', ', $keys));
    check('and the stored ones in', in_array('title', $keys, true));

    echo "\nBuilding and querying\n";

    $index = $plugin->indexes->getByHandle('products');
    $plugin->builder->buildAll($index);
    $plugin->artifacts->forget($index);
    $plugin->artifacts->publish($index);

    $context = $plugin->search->context('products', ['path' => '/listing', 'params' => []]);

    check('the facet is built into the artifact', isset($plugin->search->artifact($index)->facets['dyn_title']));
    check('and the query exposes it', ($context->facet('dyn_title')['buckets'] ?? []) !== []);
    check('the publish recorded the definition it came from', $plugin->artifacts->definitionHash($index->uid) === $index->fingerprint());
    check('so an unchanged definition marks nothing', !$plugin->indexes->checkDefinition($index) && $recordsDirty($index->uid) === 0);

    echo "\nA module's attribute set changes\n";

    // An editor added an attribute. No project-config change, no deploy.
    $specs['dyn_slug'] = 'slug';
    $plugin->indexes->reset();
    $index = $plugin->indexes->getByHandle('products');

    check('the next load sees it', $index->getAttribute('dyn_slug') !== null);

    $result = $plugin->builder->buildDirty($index);

    check('a dirty build notices the definition changed and rebuilds every record', $result['records'] > 0, "{$result['records']} rebuilt");

    $plugin->artifacts->publish($index);

    check('the publish records the new fingerprint', $plugin->artifacts->definitionHash($index->uid) === $index->fingerprint());
    check('so the next build has nothing to do', $plugin->builder->buildDirty($index)['records'] === 0);

    echo "\ninvalidate()\n";

    $marked = $plugin->indexes->invalidate('products');

    check('marks every record dirty', $marked > 0 && $recordsDirty($index->uid) === $marked, "{$marked} marked");
    check('an unknown index is a no-op', $plugin->indexes->invalidate('noSuchIndex') === 0);

    echo "\nRestoring\n";
} finally {
    Event::off(Indexes::class, Indexes::EVENT_DEFINE_INDEX, $handler);
    $plugin->indexes->reset();

    $index = $plugin->indexes->getByHandle('products');
    $plugin->builder->buildAll($index);
    $plugin->artifacts->publish($index);

    $settings->autoUpdate = $originalAutoUpdate;

    $unchanged = Craft::$app->getProjectConfig()->get(Indexes::CONFIG_PATH) == $storedConfig;
    echo $unchanged ? "  project config as it was\n" : "  WARNING: project config differs from before the run\n";
}

echo $failures === 0 ? "\nAll checks passed.\n" : "\n{$failures} check(s) failed.\n";
