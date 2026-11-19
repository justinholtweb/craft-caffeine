<?php

declare(strict_types=1);

namespace JustinHoltWeb\Caffeine\Http\Controllers;

use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\View\TemplateMode;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use JustinHoltWeb\Caffeine\Services\Indexes;
use JustinHoltWeb\Caffeine\Services\Search;
use Symfony\Component\HttpFoundation\Response;

use function CraftCms\Cms\template;

/**
 * Re-renders the page a `{% caffeine %}` block lives on and returns just its regions.
 *
 * Public: it serves the site's own front end. What it may render is fixed by the encrypted
 * `caffeineToken` the page embedded — a template Caffeine was already rendering — never by a
 * request parameter.
 */
class FragmentController
{
    public const PARAM_TOKEN = 'caffeineToken';

    public function __invoke(Request $request, Indexes $indexes, Search $search): Response
    {
        $payload = $this->decodeToken((string) $request->input(self::PARAM_TOKEN, ''));

        $index = $indexes->getByHandle($payload['index']);
        abort_if($index === null, 400, 'Unknown index.');

        // Every URL in the re-rendered block has to point back at the page, not at this endpoint.
        $search->overridePath($payload['path']);

        $variables = $payload['variables'];

        if (! empty($payload['element'])) {
            $element = Elements::getElementById(
                (int) $payload['element']['id'],
                null,
                (int) $payload['element']['siteId'],
            );

            if ($element !== null) {
                $variables[$payload['element']['type']] = $element;
            }
        }

        // Rendered whole, then narrowed. The block's markup can depend on anything else on the
        // page — a macro imported at the top, a variable set above it — so rendering it in
        // isolation would work until the first template that does something ordinary.
        template($payload['template'], $variables, TemplateMode::Site);

        $context = $search->registered($payload['index'], $payload['prefix']);

        abort_if($context === null || $context->getRenderedResults() === null, 400, sprintf(
            'The template “%s” did not render a {%% caffeineresults %%} block for “%s”.',
            $payload['template'],
            $payload['index'],
        ));

        // JSON for the bundled runtime: every state-dependent region, so the facet counts beside
        // the results update with them. Plain HTML for HTMX and anything else that swaps a
        // fragment: the results block alone.
        if ($request->input('format') === 'json' || $request->expectsJson()) {
            return response()->json([
                'regions' => $context->getRegions(),
                'html' => $context->getRenderedResults(),
                'nbHits' => $context->getNbHits(),
                'page' => $context->getPage(),
                'nbPages' => $context->getNbPages(),
                'version' => $context->version,
                'url' => $context->urlFor($context->state),
            ]);
        }

        return response((string) $context->getRenderedResults(), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Caffeine-Version' => (string) $context->version,
            'X-Caffeine-Hits' => (string) $context->getNbHits(),
        ]);
    }

    /**
     * @return array{index: string, prefix: string, path: string, template: string, element: array{type: string, id: int, siteId: int}|null, variables: array<string, mixed>}
     */
    private function decodeToken(string $token): array
    {
        try {
            $json = Crypt::decryptString($token);
        } catch (DecryptException) {
            abort(400, 'Invalid or missing Caffeine token.');
        }

        $payload = json_decode($json, true);

        abort_if(! is_array($payload) || empty($payload['template']) || empty($payload['index']), 400, 'Malformed Caffeine token.');

        return $payload + ['prefix' => '', 'path' => '/', 'element' => null, 'variables' => []];
    }
}
