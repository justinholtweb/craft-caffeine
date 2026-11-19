<?php

declare(strict_types=1);

namespace JustinHoltWeb\Caffeine\Gql;

use CraftCms\Cms\Gql\GqlHelper;
use GraphQL\Error\UserError;
use JustinHoltWeb\Caffeine\Search\Engine;
use JustinHoltWeb\Caffeine\Search\QueryState;
use JustinHoltWeb\Caffeine\Services\Indexes;
use JustinHoltWeb\Caffeine\Services\Search;
use Throwable;

final class SearchResolver
{
    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function resolve(mixed $root, array $args): array
    {
        $indexes = app(Indexes::class);
        $index = $indexes->getByHandle((string) $args['index']);

        // Same message for "no such index" and "not in this schema", so a schema can't be used to
        // probe for the handles of indexes it isn't allowed to read.
        $allowed = GqlHelper::extractAllowedEntitiesFromSchema('read')[SearchQuery::COMPONENT] ?? [];

        if ($index === null || ! in_array($index->uid, $allowed, true) || ! $indexes->isAllowed($index)) {
            throw new UserError(sprintf('This schema can’t search an index called “%s”.', $args['index']));
        }

        try {
            $artifact = app(Search::class)->artifact($index);
        } catch (Throwable) {
            throw new UserError(sprintf('“%s” hasn’t been published yet.', $args['index']));
        }

        $state = QueryState::fromArray(array_filter([
            'query' => $args['query'] ?? '',
            'refinements' => (array) ($args['refinements'] ?? []),
            'ranges' => (array) ($args['ranges'] ?? []),
            'around' => (array) ($args['around'] ?? []),
            'sortBy' => $args['sortBy'] ?? 'relevance',
            'page' => $args['page'] ?? 0,
            'hitsPerPage' => isset($args['hitsPerPage']) ? max(1, min(1000, (int) $args['hitsPerPage'])) : null,
        ], fn ($value) => $value !== null));

        return (new Engine($artifact))->search($state, $index->hitsPerPage) + ['version' => $artifact->version];
    }
}
