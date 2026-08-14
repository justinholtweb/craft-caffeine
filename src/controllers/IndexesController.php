<?php

namespace justinholtweb\caffeine\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use justinholtweb\caffeine\models\IndexDefinition;
use justinholtweb\caffeine\models\SourceDefinition;
use justinholtweb\caffeine\Plugin;
use justinholtweb\caffeine\queue\jobs\UpdateJob;
use justinholtweb\caffeine\search\Engine;
use justinholtweb\caffeine\search\QueryState;
use Throwable;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The control panel.
 *
 * An index definition is project config, so this screen is a form over YAML rather than over a
 * table — which is why saving goes through `Indexes::save()` and never touches the database, and
 * why the editor is careful not to discard settings it does not render.
 */
class IndexesController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(match ($action->id) {
            'build', 'publish' => Plugin::PERMISSION_BUILD,
            default => Plugin::PERMISSION_MANAGE_INDEXES,
        });

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $rows = [];

        foreach ($plugin->indexes->all() as $index) {
            $rows[] = [
                'index' => $index,
                'allowed' => $plugin->indexes->isAllowed($index),
                'status' => $plugin->artifacts->status($index),
                'pending' => $plugin->autoUpdate->isPending($index->uid),
            ];
        }

        return $this->renderTemplate('caffeine/indexes/index', [
            'rows' => $rows,
            'store' => $this->describeStore(),
            'canBuild' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_BUILD),
        ]);
    }

    public function actionEdit(?string $handle = null, ?IndexDefinition $index = null): Response
    {
        $plugin = Plugin::getInstance();

        // `$index` arrives populated when a failed save re-renders, so the editor shows what the
        // author typed rather than what was last saved.
        if ($index === null) {
            $index = $handle !== null ? $plugin->indexes->getByHandle($handle) : new IndexDefinition();

            if ($index === null) {
                throw new NotFoundHttpException("No index with the handle “{$handle}”.");
            }
        }

        if ($index->sources === []) {
            $index->sources = [new SourceDefinition()];
        }

        $sourceOptions = [];
        $containerHelp = [];

        foreach ($plugin->sources->all() as $source) {
            $sourceOptions[] = ['label' => $source::displayName(), 'value' => $source::handle()];

            $containers = array_keys($source->containerOptions());
            $subTypes = array_keys($source->subTypeOptions());

            $containerHelp[$source::handle()] = [
                'containers' => $containers,
                'subTypes' => $subTypes,
            ];
        }

        return $this->renderTemplate('caffeine/indexes/_edit', [
            'index' => $index,
            'isNew' => $index->uid === '',
            'sourceOptions' => $sourceOptions,
            'containerHelp' => $containerHelp,
            'siteOptions' => array_map(
                fn($site) => ['label' => $site->name, 'value' => (int)$site->id],
                Craft::$app->getSites()->getAllSites(),
            ),
            'status' => $index->uid !== '' ? $plugin->artifacts->status($index) : null,
            'isPro' => $plugin->isPro(),
            'facetTypeOptions' => array_map(
                fn(string $type) => ['label' => ucfirst($type), 'value' => $type],
                \justinholtweb\caffeine\models\Edition::facetTypes($plugin->isPro()),
            ),
            'transportOptions' => array_map(
                fn(string $transport) => ['label' => $transport, 'value' => $transport],
                \justinholtweb\caffeine\models\Edition::transports($plugin->isPro()),
            ),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $plugin = Plugin::getInstance();

        $uid = (string)$request->getBodyParam('uid', '');
        $existing = $uid !== '' ? $plugin->indexes->getByUid($uid) : null;

        $index = IndexDefinition::fromForm($request->getBodyParams(), $existing);

        if (!$plugin->indexes->save($index)) {
            Craft::$app->getSession()->setError(Craft::t('caffeine', 'Couldn’t save the index.'));

            Craft::$app->getUrlManager()->setRouteParams(['index' => $index]);

            return null;
        }

        // A changed definition invalidates every record built under the old one, and the handler
        // on project config has already marked them dirty. Say so, rather than leaving the
        // operator wondering why the counts look wrong.
        Craft::$app->getSession()->setNotice(Craft::t('caffeine', 'Index saved.'));

        return $this->redirectToPostedUrl($index);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();

        $handle = (string)Craft::$app->getRequest()->getBodyParam('handle', '');
        $index = Plugin::getInstance()->indexes->getByHandle($handle);

        if ($index === null) {
            throw new NotFoundHttpException("No index with the handle “{$handle}”.");
        }

        Plugin::getInstance()->indexes->delete($index);

        Craft::$app->getSession()->setNotice(Craft::t('caffeine', 'Index deleted.'));

        return $this->redirect('caffeine/indexes');
    }

    /**
     * Rebuilds records and republishes, through the same job the element events queue.
     */
    public function actionBuild(): Response
    {
        $this->requirePostRequest();

        $index = $this->indexFromRequest();
        $full = (bool)Craft::$app->getRequest()->getBodyParam('full', false);

        if ($full) {
            // A full rebuild has to mark everything first: `UpdateJob` only walks dirty rows, and
            // "rebuild everything" is exactly the button you press when you no longer trust what
            // is in there.
            Plugin::getInstance()->records->markIndexDirty($index->uid);
        }

        Plugin::getInstance()->autoUpdate->clearPending($index->uid);

        Craft::$app->getQueue()->push(new UpdateJob(['indexUid' => $index->uid]));

        Craft::$app->getSession()->setNotice(Craft::t('caffeine', 'Rebuilding “{name}”.', ['name' => $index->name]));

        return $this->redirect('caffeine/indexes');
    }

    public function actionPublish(): Response
    {
        $this->requirePostRequest();

        $index = $this->indexFromRequest();

        try {
            $result = Plugin::getInstance()->artifacts->publish($index);
        } catch (Throwable $e) {
            Craft::$app->getSession()->setError(Craft::t('caffeine', 'Couldn’t publish: {message}', [
                'message' => $e->getMessage(),
            ]));

            return $this->redirect('caffeine/indexes');
        }

        Craft::$app->getSession()->setNotice($result['published']
            ? Craft::t('caffeine', 'Published v{version}.', ['version' => $result['version']])
            : Craft::t('caffeine', 'Nothing changed — v{version} is still current.', ['version' => $result['version']]));

        return $this->redirect('caffeine/indexes');
    }

    /**
     * The record this definition would build for one element.
     *
     * The fastest way to find out why a facet is empty: look at what the mapper actually got,
     * rather than rebuilding and reading the artifact.
     */
    public function actionPreview(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePro();

        $index = $this->indexFromRequest();
        $elementId = (int)Craft::$app->getRequest()->getParam('elementId');
        $siteId = (int)Craft::$app->getRequest()->getParam('siteId', Craft::$app->getSites()->getCurrentSite()->id);

        $element = Craft::$app->getElements()->getElementById($elementId, null, $siteId);

        if ($element === null) {
            return $this->asJson(['error' => Craft::t('caffeine', 'No element with that ID.')]);
        }

        try {
            $startedAt = microtime(true);
            $record = Plugin::getInstance()->mapper->map($index, $element);
            $tokens = Plugin::getInstance()->tokenizer->tokenizeRecord($index, $record);

            return $this->asJson([
                'element' => (string)$element,
                'covered' => Plugin::getInstance()->sources->covers($index, $element),
                'record' => $record->toArray(),
                'tokens' => $tokens,
                'dependencies' => $record->dependencies,
                'ms' => round((microtime(true) - $startedAt) * 1000, 2),
            ]);
        } catch (Throwable $e) {
            return $this->asJson(['error' => $e->getMessage()]);
        }
    }

    /**
     * Runs a state against the published artifact and returns what the engine produced.
     *
     * Deliberately the *published* artifact rather than a fresh compile: the question this
     * answers is "what are visitors getting", and a playground that quietly recompiled would
     * answer a different one.
     */
    public function actionQuery(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePro();

        $index = $this->indexFromRequest();
        $request = Craft::$app->getRequest();

        $raw = (string)$request->getParam('state', '{}');
        $state = json_decode($raw, true);

        if (!is_array($state)) {
            return $this->asJson(['error' => Craft::t('caffeine', 'That is not valid JSON.')]);
        }

        $artifact = Plugin::getInstance()->artifacts->published($index);

        if ($artifact === null) {
            return $this->asJson(['error' => Craft::t('caffeine', 'Nothing is published for this index yet.')]);
        }

        try {
            $startedAt = microtime(true);
            $result = (new Engine($artifact))->search(QueryState::fromArray($state), $index->hitsPerPage);

            return $this->asJson([
                'version' => $artifact->version,
                'ms' => round((microtime(true) - $startedAt) * 1000, 3),
                'result' => $result,
            ]);
        } catch (Throwable $e) {
            return $this->asJson(['error' => $e->getMessage()]);
        }
    }

    // ---------------------------------------------------------------------------------------------

    /**
     * The record preview and the query playground are Pro.
     *
     * Checked on the server as well as hidden in the template: a hidden button is a courtesy, not
     * a boundary, and these two actions run a mapper and a query engine on demand.
     */
    private function requirePro(): void
    {
        if (!Plugin::getInstance()->isPro()) {
            throw new ForbiddenHttpException(Craft::t(
                'caffeine',
                'The record preview and query playground need Caffeine Pro.',
            ));
        }
    }

    private function indexFromRequest(): IndexDefinition
    {
        $request = Craft::$app->getRequest();
        $handle = (string)($request->getParam('handle') ?? '');
        $index = Plugin::getInstance()->indexes->getByHandle($handle);

        if ($index === null) {
            throw new NotFoundHttpException("No index with the handle “{$handle}”.");
        }

        return $index;
    }





    private function describeStore(): string
    {
        try {
            return Plugin::getInstance()->publisher->store()->describe();
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }
}
