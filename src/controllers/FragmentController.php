<?php

namespace justinholtweb\caffeine\controllers;

use Craft;
use craft\web\Controller;
use craft\web\View;
use justinholtweb\caffeine\Plugin;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * Re-renders the `{% caffeineresults %}` block for a new state.
 *
 * The endpoint behind the `htmx` transport, and the reason hit markup stays in Twig. It renders
 * the *same template* the page rendered, with the same element in context, and returns only the
 * results region — so a refinement produces markup identical to what a full page load would,
 * because it came from the same code.
 *
 * The template is named by a signed token rather than a request parameter. An endpoint that
 * rendered whatever template it was told to would be an arbitrary template-render primitive
 * aimed at the whole site, `{{ craft.app }}` and all.
 */
class FragmentController extends Controller
{
    /**
     * Not `token`: Craft reserves that query parameter for its own preview and share tokens and
     * rejects the request with "Invalid token" before any controller runs.
     */
    public const PARAM_TOKEN = 'caffeineToken';

    public array|bool|int $allowAnonymous = true;

    public function actionIndex(): Response
    {
        $request = Craft::$app->getRequest();
        $token = (string)$request->getParam(self::PARAM_TOKEN, '');

        $payload = $this->decodeToken($token);

        $index = Plugin::getInstance()->indexes->getByHandle($payload['index']);

        if ($index === null) {
            throw new BadRequestHttpException('Unknown index.');
        }

        $search = Plugin::getInstance()->search;

        // Every URL in the re-rendered block has to point back at the page, not at this endpoint.
        $search->overridePath($payload['path']);

        $variables = $payload['variables'] ?? [];

        if (!empty($payload['element'])) {
            $element = Craft::$app->getElements()->getElementById(
                (int)$payload['element']['id'],
                null,
                (int)$payload['element']['siteId'],
            );

            if ($element !== null) {
                $variables[$payload['element']['type']] = $element;
            }
        }

        $view = Craft::$app->getView();

        // Rendered whole, then narrowed. The block's markup can depend on anything else on the
        // page — a macro imported at the top, a variable set above it — so rendering it in
        // isolation would work until the first template that does something ordinary.
        $view->renderPageTemplate($payload['template'], $variables, View::TEMPLATE_MODE_SITE);

        $context = $search->registered($payload['index'], $payload['prefix'] ?? '');

        if ($context === null || $context->getRenderedResults() === null) {
            throw new BadRequestHttpException(sprintf(
                'The template “%s” did not render a {%% caffeineresults %%} block for “%s”.',
                $payload['template'],
                $payload['index'],
            ));
        }

        // Two shapes from one endpoint, chosen by what the caller asks for.
        //
        // JSON is what the bundled runtime wants: it carries every state-dependent region, so
        // the facet counts beside the results update with them. Raw HTML is what HTMX and
        // anything else expecting a fragment wants, and it is the results block alone — which is
        // all a plain `hx-get`/`hx-target` pair can swap.
        if ($this->wantsJson($request)) {
            return $this->asJson([
                'regions' => $context->getRegions(),
                'html' => $context->getRenderedResults(),
                'nbHits' => $context->getNbHits(),
                'page' => $context->getPage(),
                'nbPages' => $context->getNbPages(),
                'version' => $context->version,
                'url' => $context->urlFor($context->state),
            ]);
        }

        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->headers->set('Content-Type', 'text/html; charset=UTF-8');
        $response->headers->set('X-Caffeine-Version', (string)$context->version);
        $response->headers->set('X-Caffeine-Hits', (string)$context->getNbHits());

        $response->data = $context->getRenderedResults();

        return $response;
    }

    private function wantsJson(\craft\web\Request $request): bool
    {
        if ($request->getParam('format') === 'json') {
            return true;
        }

        return str_contains((string)$request->getHeaders()->get('Accept'), 'application/json');
    }

    /**
     * @return array{index: string, prefix: string, path: string, template: string, element: array|null, variables: array}
     */
    private function decodeToken(string $token): array
    {
        $json = Craft::$app->getSecurity()->validateData($token);

        if ($json === false) {
            throw new BadRequestHttpException('Invalid or missing Caffeine token.');
        }

        $payload = json_decode($json, true);

        if (!is_array($payload) || empty($payload['template']) || empty($payload['index'])) {
            throw new BadRequestHttpException('Malformed Caffeine token.');
        }

        return $payload + ['prefix' => '', 'path' => '/', 'element' => null, 'variables' => []];
    }
}
