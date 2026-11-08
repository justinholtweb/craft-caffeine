<?php

namespace justinholtweb\caffeine\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\FileHelper;
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

    /**
     * With `--all`, how many processes to build in. Each takes an equal share of the element
     * ids; pruning waits until every one of them has finished.
     */
    public int $workers = 1;

    /** Internal: the element id range one worker of a parallel build covers, as `from:to`. */
    public string $workerRange = '';

    /** Internal: where that worker writes its result for the parent to collect. */
    public string $workerResult = '';

    public function options($actionID): array
    {
        $options = parent::options($actionID);

        if ($actionID === 'build') {
            $options[] = 'all';
            $options[] = 'workers';
            $options[] = 'workerRange';
            $options[] = 'workerResult';
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
     * Removes records, ledger rows and published files left behind by deleted indexes, and
     * unreferenced files in the publish directories of the ones that remain.
     *
     * Craft's garbage collection does the same, but only when it happens to run. This is for
     * running it on purpose, after a deploy or a database restore.
     */
    public function actionPruneOrphans(): int
    {
        $result = Plugin::getInstance()->indexes->collectGarbage();

        $this->stdout(sprintf("  %d deleted index(es) torn down\n", $result['indexes']));
        $this->stdout(sprintf("  %d orphaned record(s) removed\n", $result['records']));
        $this->stdout(sprintf("  %d unreferenced file(s) removed\n", count($result['files'])));
        $this->stdout(sprintf("  %d deleted index(es)' cached artifacts removed\n", $result['cache']));

        foreach ($result['files'] as $file) {
            $this->stdout("    {$file}\n", Console::FG_GREY);
        }

        foreach ($result['busy'] as $handle) {
            $this->stdout("  {$handle} is publishing right now; its files were left alone. Run this again shortly.\n", Console::FG_YELLOW);
        }

        return ExitCode::OK;
    }

    /**
     * Builds records for one index, or every index when no handle is given.
     */
    public function actionBuild(?string $handle = null): int
    {
        if ($this->workerRange !== '') {
            return $this->runWorker((string)$handle);
        }

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
                // Started here so the bar has a start time to measure its ETA from.
                Console::startProgress(0, max($plugin->builder->countElements($index), 1));

                $result = $this->workers > 1
                    ? $this->buildParallel($index, $this->workers)
                    : $plugin->builder->buildAll($index, function(int $done, int $total) {
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
     * A full build split across worker processes by element id.
     *
     * Each worker is this command again, building one contiguous slice of ids without pruning
     * and writing what it saw to a file. The slices are contiguous rather than interleaved so
     * that between them they cover every id, including ones created while the build runs: the
     * first starts at zero and the last has no upper bound. Pruning happens here, once, and only
     * if every worker finished; a pass that is missing a slice would otherwise delete it.
     *
     * @return array{records: int, pruned: int, errors: list<string>}
     */
    private function buildParallel(IndexDefinition $index, int $workers): array
    {
        $plugin = Plugin::getInstance();
        $ids = $plugin->builder->elementIds($index);

        // Nothing to divide when the index is empty, and there the single pass is also what
        // prunes every record.
        $workers = min($workers, count($ids));

        if ($workers < 2) {
            return $plugin->builder->buildAll($index, function(int $done, int $total) {
                Console::updateProgress($done, max($total, 1));
            });
        }

        $chunks = array_chunk($ids, (int)ceil(count($ids) / $workers));
        $tempDir = Craft::$app->getPath()->getTempPath() . DIRECTORY_SEPARATOR . 'caffeine-build-' . bin2hex(random_bytes(4));
        FileHelper::createDirectory($tempDir);

        $processes = [];

        foreach ($chunks as $k => $chunk) {
            $from = $k === 0 ? 0 : $chunk[0];
            $to = isset($chunks[$k + 1]) ? $chunks[$k + 1][0] - 1 : null;
            $resultFile = $tempDir . DIRECTORY_SEPARATOR . "worker-{$k}.json";

            $command = [
                PHP_BINARY,
                Craft::getAlias('@root') . DIRECTORY_SEPARATOR . 'craft',
                'caffeine/index/build',
                $index->handle,
                '--all',
                "--worker-range={$from}:" . ($to ?? ''),
                "--worker-result={$resultFile}",
                '--interactive=0',
                '--color=0',
            ];

            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => STDERR], $pipes);

            if ($process === false) {
                $this->terminate($processes);
                FileHelper::removeDirectory($tempDir);

                throw new \RuntimeException('Could not start a build worker.');
            }

            stream_set_blocking($pipes[1], false);
            $processes[$k] = ['process' => $process, 'stdout' => $pipes[1], 'file' => $resultFile, 'done' => 0, 'total' => count($chunk), 'buffer' => ''];
        }

        $total = count($ids);
        $open = array_keys($processes);

        while ($open !== []) {
            $read = array_map(fn($k) => $processes[$k]['stdout'], $open);
            $write = $except = null;

            if (@stream_select($read, $write, $except, 1) === false) {
                break;
            }

            foreach ($open as $i => $k) {
                $chunk = fread($processes[$k]['stdout'], 65536);

                if ($chunk !== false && $chunk !== '') {
                    $processes[$k]['buffer'] .= $chunk;

                    // Workers report `progress <done> <total>` lines; only the latest complete
                    // one matters, and a line still being written stays in the buffer.
                    $end = strrpos($processes[$k]['buffer'], "\n");

                    if ($end !== false) {
                        $lines = substr($processes[$k]['buffer'], 0, $end);
                        $processes[$k]['buffer'] = substr($processes[$k]['buffer'], $end + 1);

                        if (preg_match_all('/^progress (\d+) (\d+)$/m', $lines, $m) > 0) {
                            $processes[$k]['done'] = (int)end($m[1]);
                            $processes[$k]['total'] = (int)end($m[2]);
                        }
                    }
                }

                if (feof($processes[$k]['stdout'])) {
                    unset($open[$i]);
                }
            }

            $done = array_sum(array_column($processes, 'done'));
            Console::updateProgress(min($done, $total), max($total, array_sum(array_column($processes, 'total')), 1));
        }

        $records = 0;
        $errors = [];
        $seen = [];
        $complete = true;

        foreach ($processes as $k => $worker) {
            fclose($worker['stdout']);
            $exit = proc_close($worker['process']);
            $result = is_file($worker['file']) ? json_decode((string)file_get_contents($worker['file']), true) : null;

            if (!is_array($result)) {
                $complete = false;
                $errors[] = sprintf('Worker %d of %d exited with code %d without finishing its slice.', $k + 1, count($processes), $exit);
                continue;
            }

            $records += (int)$result['records'];
            array_push($errors, ...array_values((array)$result['errors']));

            foreach ((array)$result['seen'] as $id) {
                $seen[(int)$id] = true;
            }
        }

        FileHelper::removeDirectory($tempDir);

        if (!$complete) {
            $errors[] = 'Records were not pruned, because not every slice was built. Run the build again.';

            return ['records' => $records, 'pruned' => 0, 'errors' => $errors];
        }

        $pruned = $plugin->records->pruneMissing($index->uid, array_keys($seen));

        return ['records' => $records, 'pruned' => $pruned, 'errors' => $errors];
    }

    /**
     * @param array<int, array{process: resource}> $processes
     */
    private function terminate(array $processes): void
    {
        foreach ($processes as $worker) {
            proc_terminate($worker['process']);
        }
    }

    /**
     * One worker of a parallel build: one slice, no pruning, result to a file.
     */
    private function runWorker(string $handle): int
    {
        $plugin = Plugin::getInstance();
        $index = $plugin->indexes->getByHandle($handle);

        if ($index === null || $this->workerResult === '' || preg_match('/^(\d+):(\d*)$/', $this->workerRange, $m) !== 1) {
            $this->stderr("Invalid worker invocation.\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        $range = [(int)$m[1], $m[2] === '' ? null : (int)$m[2]];

        $result = $plugin->builder->buildAll($index, function(int $done, int $total) {
            $this->stdout("progress {$done} {$total}\n");
        }, $range, false);

        file_put_contents($this->workerResult, Json::encode($result));

        return ExitCode::OK;
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
