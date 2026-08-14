<?php

namespace justinholtweb\caffeine\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\caffeine\Plugin;
use justinholtweb\caffeine\search\Engine;
use justinholtweb\caffeine\search\QueryState;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * The Algolia-shaped JSON endpoint, for the `algolia-json` transport.
 *
 * Answers in the shape of QUERY_SPEC §9, which is what lets InstantSearch, React/Vue
 * InstantSearch and Autocomplete.js talk to Caffeine unmodified — and what lets a site graduate
 * to Typesense or Algolia later without rewriting its front end.
 *
 * Available per index rather than globally: an index whose transport is `client` ships its whole
 * artifact to the browser anyway, and one whose transport is `htmx` has no reason to expose its
 * payload as JSON. Opening it everywhere would publish every indexed field of every index on
 * every site, whether or not the site meant to.
 */
class SearchController extends Controller
{
    public array|bool|int $allowAnonymous = true;

    /**
     * Off, because this endpoint reads and never writes.
     *
     * An InstantSearch adapter posts a JSON body from a script that has no way to obtain a Craft
     * CSRF token, and there is nothing here for a forged request to accomplish that a plain GET
     * could not — the same data is served either way.
     */
    public $enableCsrfValidation = false;

    public function actionIndex(?string $handle = null): Response
    {
        $request = Craft::$app->getRequest();
        $handle ??= (string)$request->getParam('index', '');

        $index = Plugin::getInstance()->indexes->getByHandle($handle);

        if ($index === null || !Plugin::getInstance()->indexes->isAllowed($index)) {
            throw new BadRequestHttpException('Unknown index.');
        }

        if ($index->transport !== $index::TRANSPORT_ALGOLIA) {
            throw new ForbiddenHttpException(sprintf(
                'The index “%s” does not serve JSON. Set its transport to “%s” to expose this endpoint.',
                $handle,
                $index::TRANSPORT_ALGOLIA,
            ));
        }

        $search = Plugin::getInstance()->search;
        $artifact = $search->artifact($index);

        // Accepts both a posted JSON body — what an InstantSearch adapter sends — and a plain
        // query string, so the endpoint can be opened in a browser tab while debugging.
        $body = $request->getIsPost() ? $request->getBodyParams() : [];

        $state = $body !== []
            ? QueryState::fromArray($body)
            : $search->resolveState($index, $artifact, [], '');

        $result = (new Engine($artifact))->search($state, $index->hitsPerPage);

        return $this->asJson($result);
    }
}
