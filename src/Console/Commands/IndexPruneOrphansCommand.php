<?php

declare(strict_types=1);

namespace JustinHoltWeb\Caffeine\Console\Commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Command;
use JustinHoltWeb\Caffeine\Console\Commands\Concerns\ResolvesIndexes;
use JustinHoltWeb\Caffeine\Services\Indexes;

/**
 * Removes records, ledger rows and published files left behind by deleted indexes, and
 * unreferenced files in the publish directories of the ones that remain.
 *
 * Craft's garbage collection does the same, but only when it happens to run. This is for
 * running it on purpose, after a deploy or a database restore.
 */
class IndexPruneOrphansCommand extends Command
{
    use CraftCommand;
    use ResolvesIndexes;

    #[\Override]
    protected $signature = 'caffeine:index:prune-orphans';

    #[\Override]
    protected $description = 'Removes everything left behind by deleted Caffeine indexes, and unreferenced published files.';

    #[\Override]
    protected $aliases = ['caffeine/index/prune-orphans'];

    public function handle(Indexes $indexes): int
    {
        $result = $indexes->collectGarbage();

        $this->line(sprintf('  %d deleted index(es) torn down', $result['indexes']));
        $this->line(sprintf('  %d orphaned record(s) removed', $result['records']));
        $this->line(sprintf('  %d unreferenced file(s) removed', count($result['files'])));
        $this->line(sprintf("  %d deleted index(es)' cached artifacts removed", $result['cache']));

        foreach ($result['files'] as $file) {
            $this->muted("    {$file}");
        }

        foreach ($result['busy'] as $handle) {
            $this->warn("  {$handle} is publishing right now; its files were left alone. Run this again shortly.");
        }

        return self::SUCCESS;
    }
}
