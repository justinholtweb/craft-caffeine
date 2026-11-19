<?php

declare(strict_types=1);

namespace JustinHoltWeb\Caffeine\Console\Commands;

use CraftCms\Cms\Console\CraftCommand;
use CraftCms\Cms\Support\Facades\Path;
use CraftCms\Cms\Support\File;
use Illuminate\Console\Command;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Process;
use JustinHoltWeb\Caffeine\Console\Commands\Concerns\ResolvesIndexes;
use JustinHoltWeb\Caffeine\Models\IndexDefinition;
use JustinHoltWeb\Caffeine\Services\Builder;
use JustinHoltWeb\Caffeine\Services\Indexes;
use JustinHoltWeb\Caffeine\Services\Records;
use RuntimeException;
use Symfony\Component\Console\Helper\ProgressBar;
use Throwable;

/**
 * Builds records for one index, or every index when no handle is given.
 */
class IndexBuildCommand extends Command
{
    use CraftCommand;
    use ResolvesIndexes;

    #[\Override]
    protected $signature = 'caffeine:index:build
        {handle? : The index to build. Every index when omitted.}
        {--all : Rebuild every record rather than only the dirty ones.}
        {--workers=1 : With --all, how many processes to build in. Each takes an equal share of the element ids; pruning waits until every one of them has finished.}';

    #[\Override]
    protected $description = 'Builds Caffeine records for one index, or every index.';

    #[\Override]
    protected $aliases = ['caffeine/index/build'];

    public function handle(Indexes $indexes, Builder $builder): int
    {
        $handle = $this->argument('handle');
        $selected = $this->resolveIndexes(is_string($handle) ? $handle : null);

        if ($selected === []) {
            $this->error('No matching index.');

            return self::FAILURE;
        }

        $all = (bool)$this->option('all');
        $workers = max(1, (int)$this->option('workers'));
        $failed = false;

        foreach ($selected as $index) {
            if (!$indexes->isAllowed($index)) {
                $this->warn("Skipping “{$index->handle}” — not available on this edition.");
                continue;
            }

            $this->line("<fg=cyan>Building “{$this->escape($index->handle)}”…</>");
            $started = microtime(true);

            if ($all) {
                // Started here so the bar has a start time to measure its ETA from.
                $bar = $this->output->createProgressBar(max($builder->countElements($index), 1));
                $bar->start();

                $result = $workers > 1
                    ? $this->buildParallel($index, $workers, $bar, $builder)
                    : $builder->buildAll($index, function(int $done, int $total) use ($bar) {
                        $bar->setMaxSteps(max($total, 1));
                        $bar->setProgress($done);
                    });

                $bar->finish();
                $this->newLine();

                $this->info(sprintf(
                    '  %d records, %d pruned in %.2fs',
                    $result['records'],
                    $result['pruned'],
                    microtime(true) - $started,
                ));
            } else {
                $result = $builder->buildDirty($index);

                $this->info(sprintf(
                    '  %d records rebuilt, %d removed in %.2fs',
                    $result['records'],
                    $result['removed'],
                    microtime(true) - $started,
                ));
            }

            foreach ($result['errors'] as $error) {
                $this->error("  {$error}");
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * A full build split across worker processes by element id.
     *
     * Each worker is `caffeine:index:build-worker`, building one contiguous slice of ids without
     * pruning and writing what it saw to a file. The slices are contiguous rather than
     * interleaved so that between them they cover every id, including ones created while the
     * build runs: the first starts at zero and the last has no upper bound. Pruning happens here,
     * once, and only if every worker finished; a pass that is missing a slice would otherwise
     * delete it.
     *
     * @return array{records: int, pruned: int, errors: list<string>}
     */
    private function buildParallel(IndexDefinition $index, int $workers, ProgressBar $bar, Builder $builder): array
    {
        $ids = $builder->elementIds($index);

        // Nothing to divide when the index is empty, and there the single pass is also what
        // prunes every record.
        $workers = min($workers, count($ids));

        if ($workers < 2) {
            return $builder->buildAll($index, function(int $done, int $total) use ($bar) {
                $bar->setMaxSteps(max($total, 1));
                $bar->setProgress($done);
            });
        }

        $chunks = array_chunk($ids, (int)ceil(count($ids) / $workers));
        $tempDir = Path::temp('caffeine-build-' . bin2hex(random_bytes(4)));
        $slices = [];

        foreach ($chunks as $k => $chunk) {
            $slices[$k] = [
                'from' => $k === 0 ? 0 : $chunk[0],
                'to' => isset($chunks[$k + 1]) ? $chunks[$k + 1][0] - 1 : null,
                'file' => $tempDir . DIRECTORY_SEPARATOR . "worker-{$k}.json",
                'done' => 0,
                'total' => count($chunk),
                'buffer' => '',
            ];
        }

        $script = $this->entryScript();

        try {
            $pool = Process::pool(function(Pool $pool) use ($slices, $index, $script) {
                foreach ($slices as $k => $slice) {
                    $pool->as((string)$k)->forever()->command([
                        PHP_BINARY,
                        $script,
                        'caffeine:index:build-worker',
                        $index->handle,
                        "--range={$slice['from']}:" . ($slice['to'] ?? ''),
                        "--result={$slice['file']}",
                        '--no-interaction',
                        '--no-ansi',
                    ]);
                }
            })->start(function(string $type, string $output, int|string $key) use (&$slices) {
                if ($type !== 'out') {
                    // Worker errors go straight through, as they did when the worker shared
                    // this process's stderr.
                    fwrite(STDERR, $output);

                    return;
                }

                $k = (int)$key;
                $slices[$k]['buffer'] .= $output;

                // Workers report `progress <done> <total>` lines; only the latest complete one
                // matters, and a line still being written stays in the buffer.
                $end = strrpos($slices[$k]['buffer'], "\n");

                if ($end === false) {
                    return;
                }

                $lines = substr($slices[$k]['buffer'], 0, $end);
                $slices[$k]['buffer'] = substr($slices[$k]['buffer'], $end + 1);

                if (preg_match_all('/^progress (\d+) (\d+)$/m', $lines, $m) > 0) {
                    $slices[$k]['done'] = (int)end($m[1]);
                    $slices[$k]['total'] = (int)end($m[2]);
                }
            });
        } catch (Throwable $e) {
            File::deleteDirectory($tempDir);

            throw new RuntimeException('Could not start a build worker: ' . $e->getMessage(), 0, $e);
        }

        $total = count($ids);

        // Polling `running()` is also what reads the workers' pipes and fires the callback above.
        while ($pool->running()->isNotEmpty()) {
            $this->advanceParallel($bar, $slices, $total);
            usleep(100_000);
        }

        $results = $pool->wait();
        $this->advanceParallel($bar, $slices, $total);

        $records = 0;
        $errors = [];
        $seen = [];
        $complete = true;

        foreach ($slices as $k => $slice) {
            $exit = $results->collect()->get($k)?->exitCode();
            $result = is_file($slice['file']) ? json_decode((string)file_get_contents($slice['file']), true) : null;

            if (!is_array($result)) {
                $complete = false;
                $errors[] = sprintf('Worker %d of %d exited with code %d without finishing its slice.', $k + 1, count($slices), $exit ?? -1);
                continue;
            }

            $records += (int)$result['records'];
            array_push($errors, ...array_values((array)$result['errors']));

            foreach ((array)$result['seen'] as $id) {
                $seen[(int)$id] = true;
            }
        }

        File::deleteDirectory($tempDir);

        if (!$complete) {
            $errors[] = 'Records were not pruned, because not every slice was built. Run the build again.';

            return ['records' => $records, 'pruned' => 0, 'errors' => $errors];
        }

        $pruned = app(Records::class)->pruneMissing($index->uid, array_keys($seen));

        return ['records' => $records, 'pruned' => $pruned, 'errors' => $errors];
    }

    /**
     * @param array<int, array{done: int, total: int}> $slices
     */
    private function advanceParallel(ProgressBar $bar, array $slices, int $total): void
    {
        $done = array_sum(array_column($slices, 'done'));
        $bar->setMaxSteps(max($total, array_sum(array_column($slices, 'total')), 1));
        $bar->setProgress(min($done, $total));
    }

    /**
     * The console entry script workers run through: `artisan` where the project has one, or
     * Craft's `craft` script, which resolves the same commands.
     */
    private function entryScript(): string
    {
        foreach (['artisan', 'craft'] as $name) {
            $path = base_path($name);

            if (is_file($path)) {
                return $path;
            }
        }

        throw new RuntimeException('Could not find an artisan or craft script to run build workers through.');
    }
}
