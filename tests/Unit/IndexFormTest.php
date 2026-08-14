<?php

declare(strict_types=1);

use justinholtweb\caffeine\models\AttributeDefinition;
use justinholtweb\caffeine\models\IndexDefinition;
use justinholtweb\caffeine\models\SortingDefinition;
use justinholtweb\caffeine\models\SourceDefinition;

/**
 * The control panel's form payload, turned back into a definition.
 *
 * The screen renders the common per-attribute settings and not the rest, so the test that
 * matters most here is the one asserting a save does not quietly discard what it never showed.
 */

function caffeineFormBody(array $overrides = []): array
{
    return array_merge([
        'uid' => 'existing-uid',
        'handle' => 'products',
        'name' => 'Products',
        'transport' => 'htmx',
        'hitsPerPage' => 24,
        'maxValuesPerFacet' => 20,
        'publishDebounce' => 30,
        'sources' => [
            ['type' => 'entry', 'containers' => 'shop, clearance', 'subTypes' => '', 'status' => 'live'],
        ],
        'attributes' => [
            [
                'key' => 'title', 'label' => '', 'source' => 'attribute', 'path' => 'title',
                'searchable' => '1', 'sortable' => '1', 'payload' => '1',
                'searchWeight' => '5', 'facetType' => 'string', 'facetOperator' => 'or',
            ],
            [
                'key' => 'category', 'label' => 'Category', 'source' => 'field', 'path' => 'category',
                'facet' => '1',
                'searchWeight' => '1', 'facetType' => 'hierarchical', 'facetOperator' => 'and',
            ],
        ],
        'sortings' => [
            ['name' => 'title_asc', 'label' => 'Title A–Z', 'attribute' => 'title', 'direction' => 'asc'],
        ],
    ], $overrides);
}

it('reads sources, splitting and trimming comma lists', function() {
    $index = IndexDefinition::fromForm(caffeineFormBody());

    expect($index->sources)->toHaveCount(1);
    expect($index->sources[0])->toBeInstanceOf(SourceDefinition::class);
    expect($index->sources[0]->containers)->toBe(['shop', 'clearance']);
    expect($index->sources[0]->subTypes)->toBe([]);
});

it('turns role checkboxes into a role list', function() {
    $index = IndexDefinition::fromForm(caffeineFormBody());

    expect($index->attributes[0]->roles)->toBe(['searchable', 'sortable', 'payload']);
    expect($index->attributes[1]->roles)->toBe(['facet']);
});

it('reads the scalar settings', function() {
    $index = IndexDefinition::fromForm(caffeineFormBody(['hitsPerPage' => '48', 'shardPayload' => '1']));

    expect($index->hitsPerPage)->toBe(48);
    expect($index->shardPayload)->toBeTrue();
    expect($index->attributes[0]->searchWeight)->toBe(5.0);
    expect($index->attributes[1]->facetType)->toBe(AttributeDefinition::FACET_HIERARCHICAL);
    expect($index->attributes[1]->facetOperator)->toBe('and');
});

it('reads sortings', function() {
    $index = IndexDefinition::fromForm(caffeineFormBody());

    expect($index->sortings)->toHaveCount(1);
    expect($index->sortings[0])->toBeInstanceOf(SortingDefinition::class);
    expect($index->sortings[0]->name)->toBe('title_asc');
});

it('skips blank rows rather than creating empty definitions', function() {
    $body = caffeineFormBody();
    $body['attributes'][] = ['key' => '  ', 'source' => 'field'];
    $body['sortings'][] = ['name' => '', 'attribute' => 'title'];
    $body['sources'][] = ['type' => '', 'containers' => 'nonsense'];

    $index = IndexDefinition::fromForm($body);

    expect($index->attributes)->toHaveCount(2);
    expect($index->sortings)->toHaveCount(1);
    expect($index->sources)->toHaveCount(1);
});

it('keeps per-attribute settings the screen does not render', function() {
    // Everything here is configurable in project config and absent from the CP form. A save that
    // rebuilt attributes from scratch would reset all of it, and the screen would quietly become
    // the only way to configure an index.
    $existing = new IndexDefinition([
        'uid' => 'existing-uid',
        'handle' => 'products',
        'attributes' => [
            new AttributeDefinition([
                'key' => 'category',
                'source' => AttributeDefinition::SOURCE_FIELD,
                'path' => 'category',
                'roles' => [AttributeDefinition::ROLE_FACET],
                'facetType' => AttributeDefinition::FACET_HIERARCHICAL,
                'hierarchySeparator' => ' / ',
                'facetSort' => 'manual',
                'facetValueOrder' => ['Home', 'Garden'],
                'maxValuesPerFacet' => 99,
                'numericBuckets' => [0.0, 25.0],
                'transforms' => ['stripTags', 'trim'],
            ]),
        ],
    ]);

    $index = IndexDefinition::fromForm(caffeineFormBody(), $existing);
    $category = $index->getAttribute('category');

    expect($category->hierarchySeparator)->toBe(' / ');
    expect($category->facetSort)->toBe('manual');
    expect($category->facetValueOrder)->toBe(['Home', 'Garden']);
    expect($category->maxValuesPerFacet)->toBe(99);
    expect($category->numericBuckets)->toBe([0.0, 25.0]);
    expect($category->transforms)->toBe(['stripTags', 'trim']);

    // And the values the form *does* own still win.
    expect($category->label)->toBe('Category');
    expect($category->facetOperator)->toBe('and');
});

it('starts an unknown attribute from defaults', function() {
    $existing = new IndexDefinition(['uid' => 'existing-uid', 'attributes' => []]);
    $index = IndexDefinition::fromForm(caffeineFormBody(), $existing);

    expect($index->getAttribute('title')->hierarchySeparator)->toBe(' > ');
    expect($index->getAttribute('title')->maxValuesPerFacet)->toBe(20);
});

it('refuses an index with no sources or attributes', function() {
    // The guard that matters most, and the one Yii silently skipped: inline validators are
    // skipped on empty values by default, so the rules meant to catch "nothing configured" were
    // exactly the rules that never ran.
    $index = IndexDefinition::fromForm([
        'handle' => 'empty',
        'name' => 'Empty',
        'sources' => [],
        'attributes' => [],
    ]);

    expect($index->validate())->toBeFalse();
    expect($index->getErrors('sources'))->not->toBeEmpty();
    expect($index->getErrors('attributes'))->not->toBeEmpty();
});

it('accepts an index with a source and an attribute', function() {
    $index = IndexDefinition::fromForm(caffeineFormBody(['uid' => '']));

    expect($index->validate())->toBeTrue()
        ->and($index->getErrors())->toBe([]);
});
