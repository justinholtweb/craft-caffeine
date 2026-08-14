<?php

/**
 * The synthetic catalogue both benchmarks measure against.
 *
 * Shaped to look like a mid-sized store rather than to flatter the numbers: a long tail of
 * brands, a short list of colours, several tags per record, a numeric price, a boolean, a
 * four-way hierarchy, near-unique titles, and a payload with the four fields a result card
 * actually needs. Seeded, so two runs measure the same data.
 */

use justinholtweb\caffeine\models\IndexDefinition;
use justinholtweb\caffeine\models\MappedRecord;
use justinholtweb\caffeine\services\Tokenizer;

/**
 * @return array{0: IndexDefinition, 1: list<array<string, mixed>>}
 */
function caffeineBenchFixture(int $count): array
{
    $config = json_decode(file_get_contents(__DIR__ . '/../Conformance/products.json'), true)['index'];
    $index = IndexDefinition::fromConfig('bench-uid', $config);
    $tokenizer = new Tokenizer();

    $brands = array_map(fn($i) => "Brand {$i}", range(1, 200));
    $colours = ['red', 'blue', 'green', 'black', 'white', 'grey', 'navy', 'olive', 'tan', 'pink', 'gold', 'silver'];
    $tagPool = array_map(fn($i) => "tag-{$i}", range(1, 50));
    $categories = ['Home > Tools > Saws', 'Home > Tools > Drills', 'Home > Garden > Hoses', 'Outdoor > Camping > Tents'];
    $words = ['acme', 'globex', 'saw', 'drill', 'hose', 'tent', 'compact', 'heavy', 'duty', 'pro', 'lite', 'steel', 'timber', 'garden', 'cordless'];

    mt_srand(42);

    $records = [];

    for ($i = 1; $i <= $count; $i++) {
        $title = implode(' ', [
            $words[mt_rand(0, count($words) - 1)],
            $words[mt_rand(0, count($words) - 1)],
            $words[mt_rand(0, count($words) - 1)],
            "model {$i}",
        ]);

        $tags = [];

        for ($t = 0; $t < 3; $t++) {
            $tags[] = $tagPool[mt_rand(0, count($tagPool) - 1)];
        }

        $mapped = new MappedRecord(
            elementId: $i,
            siteId: 1,
            objectId: "{$i}-1",
            facets: [
                'brand' => [$brands[mt_rand(0, count($brands) - 1)]],
                'colour' => [$colours[mt_rand(0, count($colours) - 1)]],
                'tags' => array_values(array_unique($tags)),
                'price' => [mt_rand(5, 500)],
                'featured' => [mt_rand(0, 4) === 0],
                'category' => [$categories[mt_rand(0, count($categories) - 1)]],
            ],
            sortable: ['title' => $title, 'price' => mt_rand(5, 500)],
            payload: [
                'title' => $title,
                'url' => "/products/model-{$i}",
                'thumb' => "/img/products/{$i}.jpg",
                'price' => mt_rand(5, 500),
            ],
            searchable: ['title' => $title],
        );

        $records[] = [
            'elementId' => $i,
            'siteId' => 1,
            'objectId' => "{$i}-1",
            'content' => $mapped->toArray(),
            'tokens' => $tokenizer->tokenizeRecord($index, $mapped),
        ];
    }

    return [$index, $records];
}

/**
 * The queries both engines are timed on.
 *
 * Chosen to cover the distinct code paths rather than to average nicely: an unrefined listing
 * touches every record, a disjunctive refinement exercises the counting asymmetry, a range
 * exercises the numeric path, and a text query is the only one that has to rebuild the ordering
 * because the score sits between the sort key and the tie-break.
 *
 * @return array<string, array<string, mixed>>
 */
function caffeineBenchQueries(): array
{
    return [
        'unrefined' => [],
        'one refinement' => ['refinements' => ['colour' => ['red']]],
        'two facets and a range' => [
            'refinements' => ['colour' => ['red'], 'tags' => ['tag-7']],
            'ranges' => ['price' => ['min' => 20, 'max' => 200]],
        ],
        'text query' => ['query' => 'cordless dri'],
        'text query and a facet' => ['query' => 'steel', 'refinements' => ['colour' => ['navy']]],
        'sorted, deep page' => ['sortBy' => 'price_asc', 'page' => 20],
    ];
}
