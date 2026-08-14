<?php
// Defines two Caffeine indexes against the test site's content.
//
// `products` exercises the ordinary case: attributes, a string facet, a boolean facet, a date
// facet, sortings and payload. `related` exercises path descent through a relation field, which
// is what the dependency map exists for.
require '/var/www/html/vendor/autoload.php';
define('CRAFT_BASE_PATH', '/var/www/html');
$app = require '/var/www/html/vendor/craftcms/cms/bootstrap/console.php';

use justinholtweb\caffeine\models\AttributeDefinition as A;
use justinholtweb\caffeine\models\IndexDefinition;
use justinholtweb\caffeine\models\SortingDefinition;
use justinholtweb\caffeine\models\SourceDefinition;
use justinholtweb\caffeine\Plugin;

$plugin = Plugin::getInstance();

$products = new IndexDefinition([
    'handle' => 'products',
    'name' => 'Products',
    'sources' => [new SourceDefinition(['type' => 'entry', 'containers' => ['testEntries'], 'status' => 'live'])],
    'transport' => IndexDefinition::TRANSPORT_HTMX,
    'attributes' => [
        new A(['key' => 'title', 'source' => A::SOURCE_ATTRIBUTE, 'path' => 'title',
            'roles' => [A::ROLE_SEARCHABLE, A::ROLE_SORTABLE, A::ROLE_PAYLOAD], 'searchWeight' => 5.0]),
        new A(['key' => 'url', 'source' => A::SOURCE_ATTRIBUTE, 'path' => 'url', 'roles' => [A::ROLE_PAYLOAD]]),
        new A(['key' => 'body', 'source' => A::SOURCE_FIELD, 'path' => 'body',
            'roles' => [A::ROLE_SEARCHABLE, A::ROLE_PAYLOAD], 'searchWeight' => 1.0,
            'transforms' => ['stripTags', 'trim']]),
        new A(['key' => 'choice', 'label' => 'Choice', 'source' => A::SOURCE_FIELD, 'path' => 'smokeChoice',
            'roles' => [A::ROLE_FACET, A::ROLE_PAYLOAD], 'facetType' => A::FACET_STRING, 'facetOperator' => 'or']),
        new A(['key' => 'featured', 'label' => 'Featured', 'source' => A::SOURCE_FIELD, 'path' => 'smokeSwitch',
            'roles' => [A::ROLE_FACET], 'facetType' => A::FACET_BOOLEAN]),
        new A(['key' => 'postDate', 'source' => A::SOURCE_ATTRIBUTE, 'path' => 'postDate',
            'roles' => [A::ROLE_FACET, A::ROLE_SORTABLE, A::ROLE_PAYLOAD], 'facetType' => A::FACET_DATE]),
    ],
    'sortings' => [
        new SortingDefinition(['name' => 'title_asc', 'label' => 'Title A–Z', 'attribute' => 'title', 'direction' => 'asc']),
        new SortingDefinition(['name' => 'newest', 'label' => 'Newest', 'attribute' => 'postDate', 'direction' => 'desc']),
    ],
]);

$related = new IndexDefinition([
    'handle' => 'related',
    'name' => 'Related test',
    'sources' => [new SourceDefinition(['type' => 'entry', 'containers' => ['testRelations'], 'status' => 'live'])],
    'attributes' => [
        new A(['key' => 'title', 'source' => A::SOURCE_ATTRIBUTE, 'path' => 'title',
            'roles' => [A::ROLE_SEARCHABLE, A::ROLE_PAYLOAD]]),
        // Descends through the relation into the related entry's title. The related entry is
        // not a record here — it is a dependency, and renaming it must dirty this record.
        new A(['key' => 'relatedTitle', 'label' => 'Related to', 'source' => A::SOURCE_FIELD, 'path' => 'relation.title',
            'roles' => [A::ROLE_FACET, A::ROLE_PAYLOAD, A::ROLE_SEARCHABLE]]),
    ],
]);

foreach ([$products, $related] as $index) {
    if (!$plugin->indexes->save($index)) {
        echo "FAILED {$index->handle}: " . json_encode($index->getErrors()) . "\n";
        continue;
    }
    echo "saved {$index->handle} ({$index->uid})\n";
}

// A bare script's project-config writes are buffered until the request ends, and a console
// bootstrap never gets there — without this the save returns true and silently vanishes.
Craft::$app->getProjectConfig()->saveModifiedConfigData();
echo "project config flushed\n";
