<?php

declare(strict_types=1);

use justinholtweb\caffeine\models\AttributeDefinition;
use justinholtweb\caffeine\models\IndexDefinition;
use justinholtweb\caffeine\models\SortingDefinition;

/**
 * Attributes and sortings an `EVENT_DEFINE_INDEX` handler adds.
 *
 * Two promises: they never reach project config, and they do change the fingerprint, which is
 * how a build notices that what a module adds has changed since the last publish.
 */

function caffeineDynamicIndex(): IndexDefinition
{
    return new IndexDefinition([
        'uid' => 'uid-1',
        'handle' => 'products',
        'name' => 'Products',
        'attributes' => [
            new AttributeDefinition(['key' => 'title', 'path' => 'title', 'roles' => [AttributeDefinition::ROLE_SEARCHABLE]]),
        ],
    ]);
}

function caffeineMotorFacet(): AttributeDefinition
{
    return new AttributeDefinition([
        'key' => 'spec_motor',
        'source' => AttributeDefinition::SOURCE_FIELD,
        'path' => 'listingSpecs.Motor',
        'roles' => [AttributeDefinition::ROLE_FACET],
        'dynamic' => true,
    ]);
}

it('leaves dynamic attributes and sortings out of project config', function() {
    $index = caffeineDynamicIndex();
    $stored = $index->toConfig();

    $index->attributes[] = caffeineMotorFacet();
    $index->sortings[] = new SortingDefinition(['name' => 'motor', 'attribute' => 'spec_motor', 'dynamic' => true]);

    expect($index->toConfig())->toBe($stored);
});

it('includes them when asked', function() {
    $index = caffeineDynamicIndex();
    $index->attributes[] = caffeineMotorFacet();

    expect(array_column($index->toConfig(true)['attributes'], 'key'))->toBe(['title', 'spec_motor']);
});

it('never writes the flag itself into config', function() {
    expect(caffeineMotorFacet()->toConfig())->not->toHaveKey('dynamic');
});

it('fingerprints the definition the build sees, module additions included', function() {
    $index = caffeineDynamicIndex();
    $before = $index->fingerprint();

    expect(caffeineDynamicIndex()->fingerprint())->toBe($before);

    $index->attributes[] = caffeineMotorFacet();

    expect($index->fingerprint())->not->toBe($before)
        ->and($index->fingerprint())->toHaveLength(16);
});
