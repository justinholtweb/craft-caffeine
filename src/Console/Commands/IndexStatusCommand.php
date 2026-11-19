<?php

declare(strict_types=1);

namespace JustinHoltWeb\Caffeine\Console\Commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Command;
use JustinHoltWeb\Caffeine\Console\Commands\Concerns\ResolvesIndexes;
use JustinHoltWeb\Caffeine\Services\Indexes;
use JustinHoltWeb\Caffeine\Services\Records;

/**
 * Lists every index with its record counts.
 */
class IndexStatusCommand extends Command
{
    use CraftCommand;
    use ResolvesIndexes;

    #[\Override]
    protected $signature = 'caffeine:index:status';

    #[\Override]
    protected $description = 'Lists every Caffeine index with its record counts.';

    /** `caffeine/index` was the Craft 5 controller's default action. */
    #[\Override]
    protected $aliases = ['caffeine/index/status', 'caffeine/index'];

    public function handle(Indexes $indexes, Records $records): int
    {
        $all = $indexes->all();

        if ($all === []) {
            $this->warn('No indexes are defined yet.');

            return self::SUCCESS;
        }

        foreach ($all as $index) {
            $this->line(sprintf(
                '<fg=cyan;options=bold>%s</>  %s  %d records, %d dirty  [%s]',
                $this->escape($index->handle),
                $this->escape($index->name),
                $records->countFor($index->uid),
                $records->countFor($index->uid, true),
                $index->transport,
            ));

            if (!$indexes->isAllowed($index)) {
                $this->warn('  Not available on this edition — upgrade to Pro to build it.');
            }
        }

        return self::SUCCESS;
    }
}
