<?php
// Exercises the Lite/Pro boundary against the plugin-testing harness.
//
// The harness runs as Pro, which makes the Lite path the least-exercised code in the plugin — so
// this switches editions for real, checks what changes, and switches back.
//
//   ddev exec bash -c "cd /var/www/html && php /var/www/craft-caffeine/tests/integration/edition-checks.php"

require '/var/www/html/vendor/autoload.php';
define('CRAFT_BASE_PATH', '/var/www/html');
$app = require '/var/www/html/vendor/craftcms/cms/bootstrap/console.php';

use justinholtweb\caffeine\models\AttributeDefinition;
use justinholtweb\caffeine\models\Edition;
use justinholtweb\caffeine\models\IndexDefinition;
use justinholtweb\caffeine\models\SortingDefinition;
use justinholtweb\caffeine\models\SourceDefinition;
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

$plugins = Craft::$app->getPlugins();
$plugin = Plugin::getInstance();
$original = $plugin->edition;

$switch = function(string $edition) use ($plugins, &$plugin) {
    $plugins->switchEdition('caffeine', $edition);

    // The instance caches its edition, so it has to be re-read rather than trusted.
    $plugin = Plugin::getInstance();
    $plugin->edition = $edition;
    $plugin->indexes->reset();
    $plugin->sources->reset();
};

echo "Starting on {$original}\n\n";

try {
    // ---------------------------------------------------------------------------------------------
    $switch(Plugin::EDITION_PRO);

    echo "Pro\n";

    check('every source is available', count($plugin->sources->all()) >= 5,
        implode(', ', array_keys($plugin->sources->all())));
    check('every index is allowed', count($plugin->indexes->allowed()) === count($plugin->indexes->all()),
        count($plugin->indexes->all()) . ' defined');

    $products = $plugin->indexes->getByHandle('products');
    $proFacets = count($products->facets());
    $proSortings = count($products->sortings);

    check('a definition passes through untouched',
        count($plugin->indexes->forEdition($products)->facets()) === $proFacets,
        "{$proFacets} facets, {$proSortings} sortings");

    // ---------------------------------------------------------------------------------------------
    $switch(Plugin::EDITION_LITE);

    echo "\nLite — the registry\n";

    $sources = $plugin->sources->all();

    check('only the entry source is offered', array_keys($sources) === ['entry'],
        implode(', ', array_keys($sources)));
    check('only one index is allowed', count($plugin->indexes->allowed()) === 1,
        implode(', ', array_keys($plugin->indexes->allowed())));
    check('but the others stay visible, so an upgrade shows what comes back',
        count($plugin->indexes->all()) > count($plugin->indexes->allowed()));

    echo "\nLite — a Pro definition is downgraded, not broken\n";

    $products = $plugin->indexes->getByHandle('products');
    $lite = $plugin->indexes->forEdition($products);

    $liteTypes = array_values(array_unique(array_map(
        fn(AttributeDefinition $a) => $a->facetType,
        $lite->facets(),
    )));

    check('unsupported facet types are dropped', array_diff($liteTypes, Edition::facetTypes(false)) === [],
        implode(', ', $liteTypes));
    check('supported facets survive', $lite->facets() !== []);
    check('named sortings are capped',
        count(array_filter($lite->sortings, fn(SortingDefinition $s) => !$s->isRelevance())) <= Edition::LITE_MAX_SORTINGS,
        count($lite->sortings) . ' kept of ' . $proSortings);
    check('the transport is forced to the fragment transport',
        $lite->transport === IndexDefinition::TRANSPORT_HTMX, $lite->transport);
    check('word lists are ignored', $lite->stopwords === [] && $lite->synonyms === []);

    // The point of downgrading rather than refusing: the stored definition is untouched, so
    // renewing a lapsed licence restores exactly what was there.
    check('the stored definition is left alone',
        count($plugin->indexes->getByHandle('products')->facets()) === $proFacets,
        "{$proFacets} facets still stored");

    echo "\nLite — it still builds and queries\n";

    $only = array_values($plugin->indexes->allowed())[0];

    try {
        $artifact = $plugin->artifacts->compile($only);
        check('an index compiles', true, $artifact->recordCount() . ' records');

        $geoOrNumeric = array_filter(
            array_keys($artifact->facets),
            fn(string $key) => in_array($key, ['postDate', 'price', 'near'], true),
        );

        check('and its artifact carries no Pro facet types', $geoOrNumeric === [],
            implode(', ', array_keys($artifact->facets)));
    } catch (Throwable $e) {
        check('an index compiles', false, $e->getMessage());
    }

    echo "\nLite — a Pro configuration is refused with a reason\n";

    $attempt = new IndexDefinition([
        'handle' => 'liteAttempt',
        'name' => 'Lite attempt',
        'transport' => IndexDefinition::TRANSPORT_CLIENT,
        'stopwords' => ['the'],
        'sources' => [new SourceDefinition(['type' => 'entry'])],
        'attributes' => [
            new AttributeDefinition([
                'key' => 'price', 'source' => AttributeDefinition::SOURCE_FIELD, 'path' => 'price',
                'roles' => [AttributeDefinition::ROLE_FACET],
                'facetType' => AttributeDefinition::FACET_NUMERIC,
            ]),
        ],
    ]);

    check('saving it fails', $plugin->indexes->save($attempt) === false);

    $errors = implode(' ', $attempt->getErrors('attributes'));

    check('and says which facet', str_contains($errors, 'price'), substr($errors, 0, 90));
    check('and mentions the transport', str_contains($errors, 'client'));
    check('and mentions the word lists', str_contains($errors, 'Stopwords'));
    check('nothing was written', $plugin->indexes->getByHandle('liteAttempt') === null);

    // ---------------------------------------------------------------------------------------------
    $switch(Plugin::EDITION_PRO);

    echo "\nUpgrading restores everything\n";

    $restored = $plugin->indexes->forEdition($plugin->indexes->getByHandle('products'));

    check('the facets come back', count($restored->facets()) === $proFacets, "{$proFacets} facets");
    check('the sortings come back', count($restored->sortings) === $proSortings, "{$proSortings} sortings");
    check('every source is offered again', count($plugin->sources->all()) >= 5);
} finally {
    $switch($original);
    Craft::$app->getProjectConfig()->saveModifiedConfigData();

    echo "\nRestored to {$original}\n";
}

echo $failures === 0 ? "\nAll checks passed.\n" : "\n{$failures} check(s) failed.\n";

exit($failures === 0 ? 0 : 1);
