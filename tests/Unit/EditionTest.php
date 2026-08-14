<?php

declare(strict_types=1);

use justinholtweb\caffeine\models\AttributeDefinition;
use justinholtweb\caffeine\models\Edition;
use justinholtweb\caffeine\models\IndexDefinition;
use justinholtweb\caffeine\models\SortingDefinition;

/**
 * The Lite/Pro boundary, in one place and testable without an application.
 *
 * Worth testing properly for a reason that has nothing to do with correctness in the usual
 * sense: the harness runs as Pro, so the Lite path is the least-exercised code in the plugin, and
 * a boundary that leaks is a boundary that was never worth drawing.
 */

function caffeineProIndex(): IndexDefinition
{
    return new IndexDefinition([
        'handle' => 'products',
        'name' => 'Products',
        'transport' => IndexDefinition::TRANSPORT_CLIENT,
        'stopwords' => ['the'],
        'synonyms' => ['sofa, couch'],
        'attributes' => [
            new AttributeDefinition([
                'key' => 'title', 'source' => 'attribute', 'path' => 'title',
                'roles' => [AttributeDefinition::ROLE_SEARCHABLE, AttributeDefinition::ROLE_SORTABLE],
            ]),
            new AttributeDefinition([
                'key' => 'brand', 'source' => 'field', 'path' => 'brand',
                'roles' => [AttributeDefinition::ROLE_FACET],
                'facetType' => AttributeDefinition::FACET_STRING,
            ]),
            new AttributeDefinition([
                'key' => 'price', 'source' => 'field', 'path' => 'price',
                'roles' => [AttributeDefinition::ROLE_FACET, AttributeDefinition::ROLE_SORTABLE],
                'facetType' => AttributeDefinition::FACET_NUMERIC,
            ]),
            new AttributeDefinition([
                'key' => 'near', 'source' => 'field', 'path' => 'address',
                'roles' => [AttributeDefinition::ROLE_FACET],
                'facetType' => AttributeDefinition::FACET_GEO,
            ]),
        ],
        'sortings' => [
            new SortingDefinition(['name' => 'title_asc', 'attribute' => 'title', 'direction' => 'asc']),
            new SortingDefinition(['name' => 'price_asc', 'attribute' => 'price', 'direction' => 'asc']),
            new SortingDefinition(['name' => 'price_desc', 'attribute' => 'price', 'direction' => 'desc']),
        ],
    ]);
}

describe('what each edition allows', function() {
    it('gives Pro everything', function() {
        expect(Edition::facetTypes(true))->toBe(AttributeDefinition::FACET_TYPES);
        expect(Edition::transports(true))->toBe(IndexDefinition::TRANSPORTS);
        expect(Edition::maxSortings(true))->toBeNull();
        expect(Edition::maxIndexes(true))->toBeNull();
        expect(Edition::allowsWordLists(true))->toBeTrue();
        expect(Edition::allowsTools(true))->toBeTrue();
    });

    it('holds Lite to strings, booleans and the fragment transport', function() {
        expect(Edition::facetTypes(false))->toBe(['string', 'boolean']);
        expect(Edition::transports(false))->toBe(['htmx']);
        expect(Edition::maxSortings(false))->toBe(1);
        expect(Edition::maxIndexes(false))->toBe(1);
        expect(Edition::allowsWordLists(false))->toBeFalse();
        expect(Edition::allowsTools(false))->toBeFalse();
    });
});

describe('problems()', function() {
    it('finds nothing wrong on Pro', function() {
        expect(Edition::problems(caffeineProIndex(), true))->toBe([]);
    });

    it('reports every problem at once rather than one per save', function() {
        $problems = Edition::problems(caffeineProIndex(), false);

        // Numeric facet, geo facet, client transport, two extra sortings, word lists.
        expect($problems)->toHaveCount(5);
    });

    it('names the offending facet so the message is actionable', function() {
        $problems = implode(' ', Edition::problems(caffeineProIndex(), false));

        expect($problems)->toContain('price')
            ->and($problems)->toContain('near')
            ->and($problems)->toContain('client')
            ->and($problems)->toContain('Stopwords and synonyms');
    });

    it('passes a Lite-shaped index', function() {
        $index = new IndexDefinition([
            'handle' => 'products',
            'transport' => IndexDefinition::TRANSPORT_HTMX,
            'attributes' => [
                new AttributeDefinition([
                    'key' => 'brand', 'source' => 'field', 'path' => 'brand',
                    'roles' => [AttributeDefinition::ROLE_FACET],
                    'facetType' => AttributeDefinition::FACET_STRING,
                ]),
            ],
            'sortings' => [new SortingDefinition(['name' => 'brand_asc', 'attribute' => 'brand'])],
        ]);

        expect(Edition::problems($index, false))->toBe([]);
    });

    it('counts relevance as free', function() {
        $index = new IndexDefinition([
            'handle' => 'products',
            'attributes' => [new AttributeDefinition([
                'key' => 'brand', 'source' => 'field', 'path' => 'brand',
                'roles' => [AttributeDefinition::ROLE_FACET],
            ])],
            'sortings' => [
                new SortingDefinition(['name' => SortingDefinition::RELEVANCE]),
                new SortingDefinition(['name' => 'brand_asc', 'attribute' => 'brand']),
            ],
        ]);

        expect(Edition::problems($index, false))->toBe([]);
    });
});
