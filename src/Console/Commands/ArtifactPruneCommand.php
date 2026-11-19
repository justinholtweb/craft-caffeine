<?php

declare(strict_types=1);

namespace JustinHoltWeb\Caffeine\Console\Commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Command;
use JustinHoltWeb\Caffeine\Console\Commands\Concerns\ResolvesIndexes;
use JustinHoltWeb\Caffeine\Services\Artifacts;

/**
 * Retires versions beyond the `keepVersions` setting and deletes the files only they used.
 */
class ArtifactPruneCommand extends Command
{
    use CraftCommand;
    use ResolvesIndexes;

    #[\Override]
    protected $signature = 'caffeine:artifact:prune {handle? : The index to prune. Every index when omitted.}';

    #[\Override]
    protected $description = 'Retires Caffeine artifact versions beyond the keepVersions setting.';

    #[\Override]
    protected $aliases = ['caffeine/artifact/prune'];

    public function handle(Artifacts $artifacts): int
    {
        $handle = $this->argument('handle');
        $total = 0;

        foreach ($this->resolveIndexes(is_string($handle) ? $handle : null) as $index) {
            $pruned = $artifacts->prune($index);
            $total += $pruned;

            $this->line(sprintf('  %s — %d version(s) retired', $this->escape($index->handle), $pruned));
        }

        $this->info(sprintf('%d version(s) retired in total.', $total));

        return self::SUCCESS;
    }
}
