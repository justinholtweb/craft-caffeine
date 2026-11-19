<?php

declare(strict_types=1);

namespace JustinHoltWeb\Caffeine\Gql;

use CraftCms\Cms\Gql\GqlEntityRegistry;
use CraftCms\Cms\Gql\GqlHelper;
use CraftCms\Cms\Gql\Queries\Query;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use JustinHoltWeb\Caffeine\Models\Edition;
use JustinHoltWeb\Caffeine\Plugin;

/**
 * `caffeineSearch` — the same engine and the same published artifact as the Twig tags and the
 * JSON endpoint, so a headless front end gets identical results.
 *
 * A schema only sees the query when it can read at least one index, and the resolver checks the
 * requested index against the schema's scope.
 */
final class SearchQuery extends Query
{
    /** Schema scopes are `caffeineIndexes.{uid}:read`. */
    public const string COMPONENT = 'caffeineIndexes';

    public static function getQueries(bool $checkToken = true): array
    {
        if (! Edition::allowsGraphql(Plugin::getInstance()->isPro())) {
            return [];
        }

        if ($checkToken && ! GqlHelper::isSchemaAwareOf(self::COMPONENT)) {
            return [];
        }

        return [
            'caffeineSearch' => [
                'type' => Type::nonNull(self::resultType()),
                'args' => [
                    'index' => [
                        'name' => 'index',
                        'type' => Type::nonNull(Type::string()),
                        'description' => 'The index handle.',
                    ],
                    'query' => [
                        'name' => 'query',
                        'type' => Type::string(),
                        'description' => 'Full-text query. Empty lists everything, in the chosen sort order.',
                    ],
                    'refinements' => [
                        'name' => 'refinements',
                        'type' => JsonType::getType(),
                        'description' => 'Facet values to filter by, keyed by attribute: `{"colour": ["red", "blue"]}`.',
                    ],
                    'ranges' => [
                        'name' => 'ranges',
                        'type' => JsonType::getType(),
                        'description' => 'Numeric and date ranges, keyed by attribute: `{"price": {"min": 10, "max": 50}}`.',
                    ],
                    'around' => [
                        'name' => 'around',
                        'type' => JsonType::getType(),
                        'description' => 'Geo filters, keyed by attribute: `{"location": {"lat": 51.5, "lng": -0.12, "radius": 5000}}` (metres).',
                    ],
                    'sortBy' => [
                        'name' => 'sortBy',
                        'type' => Type::string(),
                        'defaultValue' => 'relevance',
                        'description' => 'A sorting name from the index, or `relevance`.',
                    ],
                    'page' => [
                        'name' => 'page',
                        'type' => Type::int(),
                        'defaultValue' => 0,
                        'description' => 'Zero-based, as in the JSON endpoint and Algolia.',
                    ],
                    'hitsPerPage' => [
                        'name' => 'hitsPerPage',
                        'type' => Type::int(),
                        'description' => 'Defaults to the index’s own setting.',
                    ],
                ],
                'resolve' => SearchResolver::resolve(...),
                'description' => 'Searches a Caffeine index.',
            ],
        ];
    }

    private static function resultType(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate('CaffeineSearchResult', fn () => new ObjectType([
            'name' => 'CaffeineSearchResult',
            'description' => 'One page of results, with facet counts for the whole result set.',
            'fields' => fn () => [
                'hits' => [
                    'type' => Type::nonNull(Type::listOf(JsonType::getType())),
                    'description' => 'The page of hits, each the payload attributes the index publishes plus `objectID`.',
                ],
                'nbHits' => ['type' => Type::nonNull(Type::int()), 'description' => 'Hits across every page.'],
                'page' => ['type' => Type::nonNull(Type::int()), 'description' => 'Zero-based.'],
                'nbPages' => ['type' => Type::nonNull(Type::int())],
                'hitsPerPage' => ['type' => Type::nonNull(Type::int())],
                'facets' => [
                    'type' => JsonType::getType(),
                    'description' => 'Counts per facet value: `{"colour": {"red": 12, "blue": 4}}`.',
                ],
                'facetsStats' => [
                    'type' => JsonType::getType(),
                    'description' => 'Min, max, average and sum for numeric facets.',
                    'resolve' => fn (array $result) => $result['facets_stats'] ?? null,
                ],
                'version' => [
                    'type' => Type::int(),
                    'description' => 'The published artifact version the results came from.',
                ],
            ],
        ]));
    }
}
