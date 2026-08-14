<?php
// Exercises Phase 7's coverage work against the plugin-testing harness.
//
//   ddev exec bash -c "cd /var/www/html && php /var/www/craft-caffeine/tests/integration/coverage-checks.php"

require '/var/www/html/vendor/autoload.php';
define('CRAFT_BASE_PATH', '/var/www/html');
$app = require '/var/www/html/vendor/craftcms/cms/bootstrap/console.php';

use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\User;
use justinholtweb\caffeine\models\AttributeDefinition;
use justinholtweb\caffeine\models\IndexDefinition;
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

$plugin = Plugin::getInstance();
$sources = $plugin->sources;

echo "Sources\n";

$all = $sources->all();

check('every built-in source registers', count($all) >= 5, implode(', ', array_keys($all)));

foreach (['entry', 'category', 'tag', 'asset', 'user'] as $handle) {
    check("“{$handle}” is available", isset($all[$handle]));
}

check('Commerce products appear when Commerce is installed',
    isset($all['product']) === Craft::$app->getPlugins()->isPluginEnabled('commerce'));

echo "\nContainers and queries\n";

foreach ($all as $handle => $source) {
    $definition = new SourceDefinition(['type' => $handle]);

    try {
        $count = (int)$source->query($definition, Craft::$app->getSites()->getPrimarySite()->id)->count();
        check("“{$handle}” queries without erroring", true, "{$count} element(s)");
    } catch (Throwable $e) {
        check("“{$handle}” queries without erroring", false, $e->getMessage());
        continue;
    }

    // `containerOptions()` feeds the CP's help text, so an exception here is a broken edit screen.
    try {
        $source->containerOptions();
        $source->subTypeOptions();
        check("“{$handle}” lists its containers", true);
    } catch (Throwable $e) {
        check("“{$handle}” lists its containers", false, $e->getMessage());
    }
}

echo "\nStatus mapping\n";

// The bug this guards: `live` is an entry concept. Asking a category, asset or user query for it
// returns nothing at all rather than erroring — an empty index and no explanation.
foreach (['category' => Category::class, 'asset' => Asset::class, 'user' => User::class] as $handle => $class) {
    if (!isset($all[$handle])) {
        continue;
    }

    $live = (int)$all[$handle]->query(new SourceDefinition(['type' => $handle, 'status' => 'live']), Craft::$app->getSites()->getPrimarySite()->id)->count();
    $any = (int)$all[$handle]->query(new SourceDefinition(['type' => $handle, 'status' => 'any']), Craft::$app->getSites()->getPrimarySite()->id)->count();

    check("“{$handle}” returns something for the default status", $any === 0 || $live > 0, "{$live} live of {$any}");
}

echo "\nValue extractors against real fields\n";

// The harness carries a FreeLink, a Hyper, a Google Maps address and a Dropdown, which is most of
// the reason extractors exist. Nothing asserts a specific value — the point is that each produces
// something an index can hold rather than an opaque object or nothing at all.
$probes = [
    'freelinkItTest' => ['url'],
    'smokeHyper' => ['url'],
    'tpAddress' => ['lat', 'lng'],
    'smokeChoice' => ['value'],
];

foreach ($probes as $handle => $expectedParts) {
    if (Craft::$app->getFields()->getFieldByHandle($handle) === null) {
        echo "  skip {$handle} — not installed\n";
        continue;
    }

    $index = new IndexDefinition([
        'handle' => 'probe',
        'uid' => 'probe-uid',
        'sources' => [new SourceDefinition(['type' => 'entry'])],
        'attributes' => [
            new AttributeDefinition([
                'key' => 'probe',
                'source' => AttributeDefinition::SOURCE_FIELD,
                'path' => $handle,
                'roles' => [AttributeDefinition::ROLE_PAYLOAD, AttributeDefinition::ROLE_FACET],
            ]),
        ],
    ]);

    // Chosen by what it *maps to*, not by what the field returns. An unset dropdown and an empty
    // link collection are both non-empty objects, so picking on the raw value finds entries with
    // nothing in them and then blames the extractor.
    $record = null;
    $error = null;

    foreach (Entry::find()->status(null)->limit(300)->all() as $candidate) {
        try {
            $mapped = $plugin->mapper->map($index, $candidate);
        } catch (Throwable $e) {
            $error = $e->getMessage();
            break;
        }

        if (($mapped->payload['probe'] ?? null) !== null) {
            $record = $mapped;
            break;
        }
    }

    if ($error !== null) {
        check("{$handle} maps without erroring", false, $error);
        continue;
    }

    if ($record === null) {
        echo "  skip {$handle} — no entry has a value\n";
        continue;
    }

    $payload = $record->payload['probe'];
    $facets = $record->facets['probe'] ?? [];

    // A payload that came back as a class name is exactly the failure extractors exist to fix.
    $opaque = is_string($payload) && str_contains($payload, chr(92));

    check("{$handle} extracts to something usable", !$opaque && $facets !== [],
        substr((string)json_encode($payload), 0, 100));

    if (is_array($payload)) {
        check('  and exposes ' . implode('/', $expectedParts),
            array_intersect($expectedParts, array_keys($payload)) !== [],
            implode(', ', array_keys($payload)));
    }
}

echo $failures === 0 ? "\nAll checks passed.\n" : "\n{$failures} check(s) failed.\n";

exit($failures === 0 ? 0 : 1);
