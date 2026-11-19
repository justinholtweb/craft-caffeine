<?php

declare(strict_types=1);

namespace JustinHoltWeb\Caffeine\Console\Commands;

use Illuminate\Console\Command;
use JustinHoltWeb\Caffeine\Services\Builder;
use JustinHoltWeb\Caffeine\Services\Indexes;

/**
 * One worker of a parallel build: one slice, no pruning, result to a file.
 *
 * Internal — spawned by `caffeine:index:build --all --workers=N`, never run by hand. In Craft 5
 * this was the build action itself, invoked with the internal `--worker-range` and
 * `--worker-result` options; a separate hidden command keeps those off the public signature.
 */
class IndexBuildWorkerCommand extends Command
{
    #[\Override]
    protected $signature = 'caffeine:index:build-worker
        {handle : The index being built.}
        {--range= : The element id range this worker covers, as from:to (to may be empty).}
        {--result= : Where to write the result for the parent to collect.}';

    #[\Override]
    protected $description = 'Internal: one worker of a parallel Caffeine build.';

    #[\Override]
    protected $hidden = true;

    public function handle(Indexes $indexes, Builder $builder): int
    {
        $index = $indexes->getByHandle((string) $this->argument('handle'));
        $range = (string) $this->option('range');
        $resultFile = (string) $this->option('result');

        if ($index === null || $resultFile === '' || preg_match('/^(\d+):(\d*)$/', $range, $m) !== 1) {
            $this->error('Invalid worker invocation.');

            return self::INVALID;
        }

        $result = $builder->buildAll($index, function (int $done, int $total) {
            $this->output->writeln("progress {$done} {$total}");
        }, [(int) $m[1], $m[2] === '' ? null : (int) $m[2]], false);

        file_put_contents($resultFile, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
