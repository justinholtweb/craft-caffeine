<?php

declare(strict_types=1);

namespace JustinHoltWeb\Caffeine\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JustinHoltWeb\Caffeine\Models\IndexDefinition;
use JustinHoltWeb\Caffeine\Search\Engine;
use JustinHoltWeb\Caffeine\Search\QueryState;
use JustinHoltWeb\Caffeine\Services\Indexes;
use JustinHoltWeb\Caffeine\Services\Search;

/**
 * Algolia-shaped JSON for indexes on the `algolia-json` transport, so InstantSearch and its
 * adapters work unmodified.
 *
 * Public and exempt from CSRF (see `Plugin::boot()`): an InstantSearch client posts JSON from the
 * browser, and the endpoint only ever reads a published artifact.
 */
class SearchController
{
    public function __invoke(Request $request, Indexes $indexes, Search $search, ?string $handle = null): JsonResponse
    {
        $handle ??= (string) $request->input('index', '');

        $index = $indexes->getByHandle($handle);
        abort_if($index === null || ! $indexes->isAllowed($index), 400, 'Unknown index.');

        abort_if($index->transport !== IndexDefinition::TRANSPORT_ALGOLIA, 403, sprintf(
            'The index “%s” does not serve JSON. Set its transport to “%s” to expose this endpoint.',
            $handle,
            IndexDefinition::TRANSPORT_ALGOLIA,
        ));

        $artifact = $search->artifact($index);

        // Accepts both a posted JSON body — what an InstantSearch adapter sends — and a plain
        // query string, so the endpoint can be opened in a browser tab while debugging.
        $body = $request->isMethod('post') ? $request->all() : [];

        $state = $body !== []
            ? QueryState::fromArray($body)
            : $search->resolveState($index, $artifact, [], '');

        return response()->json((new Engine($artifact))->search($state, $index->hitsPerPage));
    }
}
