<?php

namespace justinholtweb\caffeine\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\Json;
use justinholtweb\caffeine\models\IndexDefinition;
use justinholtweb\caffeine\Plugin;
use yii\console\ExitCode;

/**
 * Building and inspecting indexes from the command line.
 */
class IndexController extends Controller
{
    public $defaultAction = 'status';

    /** Rebuild every record rather than only the dirty ones. */
    public bool $all = false;

    public function options($actionID): array
    {
        $options = parent::options($actionID);

        if ($actionID === 'build') {
            $options[] = 'all';
        }

        return $options;
    }

    /**
     * Lists every index with its record counts.
     */
    public function actionStatus(): int
    {
        $indexes = Plugin::getInstance()->indexes->all();

        if ($indexes === []) {
            $this->stdout("No indexes are defined yet.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $records = Plugin::getInstance()->records;

        foreach ($indexes as $index) {
            $allowed = Plugin::getInstance()->indexes->isAllowed($index);
            $total = $records->countFor($index->uid);
            $dirty = $records->countFor($index->uid, true);

            $this->stdout($index->handle, Console::FG_CYAN, Console::BOLD);
            $this->stdout(sprintf(
                "  %s  %d records, %d dirty  [%s]\n",
                $index->name,
                $total,
                $dirty,
                $index->transport,
            ));

            if (!$allowed) {
                $this->stdout("  Not available on this edition — upgrade to Pro to build it.\n", Console::FG_YELLOW);
            }
        }

        return ExitCode::OK;
    }

    /**
     * Builds records for one index, or every index when no handle is given.
     */
    public function actionBuild(?string $handle = null): int
    {
        $indexes = $this->resolveIndexes($handle);

        if ($indexes === []) {
            $this->stderr("No matching index.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $plugin = Plugin::getInstance();
        $failed = false;

        foreach ($indexes as $index) {
            if (!$plugin->indexes->isAllowed($index)) {
                $this->stdout("Skipping “{$index->handle}” — not available on this edition.\n", Console::FG_YELLOW);
                continue;
            }

            $this->stdout("Building “{$index->handle}”…\n", Console::FG_CYAN);
            $started = microtime(true);

            if ($this->all) {
                $result = $plugin->builder->buildAll($index, function(int $done, int $total) {
                    Console::updateProgress($done, max($total, 1));
                });

                Console::endProgress();

                $this->stdout(sprintf(
                    "  %d records, %d pruned in %.2fs\n",
                    $result['records'],
                    $result['pruned'],
                    microtime(true) - $started,
                ), Console::FG_GREEN);
            } else {
                $result = $plugin->builder->buildDirty($index);

                $this->stdout(sprintf(
                    "  %d records rebuilt, %d removed in %.2fs\n",
                    $result['records'],
                    $result['removed'],
                    microtime(true) - $started,
                ), Console::FG_GREEN);
            }

            foreach ($result['errors'] as $error) {
                $this->stderr("  {$error}\n", Console::FG_RED);
                $failed = true;
            }
        }

        return $failed ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Prints the record Caffeine would build for one element, so a definition can be checked
     * against real content without rebuilding anything.
     */
    public function actionPreview(string $handle, int $elementId): int
    {
        $plugin = Plugin::getInstance();
        $index = $plugin->indexes->getByHandle($handle);

        if ($index === null) {
            $this->stderr("No index with the handle “{$handle}”.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $element = \Craft::$app->getElements()->getElementById($elementId);

        if ($element === null) {
            $this->stderr("No element with the id {$elementId}.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $record = $plugin->mapper->map($index, $element);

        $this->stdout(Json::encode([
            'objectID' => $record->objectId,
            'facets' => $record->facets,
            'sortable' => $record->sortable,
            'payload' => $record->payload,
            'searchable' => $record->searchable,
            'tokens' => $plugin->tokenizer->tokenizeRecord($index, $record),
            'dependsOn' => $record->dependencies,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

        return ExitCode::OK;
    }

    /**
     * Marks every record on an index as needing a rebuild.
     */
    public function actionTouch(string $handle): int
    {
        $index = Plugin::getInstance()->indexes->getByHandle($handle);

        if ($index === null) {
            $this->stderr("No index with the handle “{$handle}”.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $count = Plugin::getInstance()->records->markIndexDirty($index->uid);
        $this->stdout("Marked {$count} records dirty.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * @return IndexDefinition[]
     */
    private function resolveIndexes(?string $handle): array
    {
        $indexes = Plugin::getInstance()->indexes;

        if ($handle === null) {
            return array_values($indexes->all());
        }

        $index = $indexes->getByHandle($handle);

        return $index !== null ? [$index] : [];
    }
}
