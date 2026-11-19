<?php

declare(strict_types=1);

namespace JustinHoltWeb\Caffeine\Console\Commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Command;
use JustinHoltWeb\Caffeine\Services\Indexes;
use JustinHoltWeb\Caffeine\Services\Records;

/**
 * Marks every record on an index as needing a rebuild.
 */
class IndexTouchCommand extends Command
{
    use CraftCommand;

    #[\Override]
    protected $signature = 'caffeine:index:touch {handle : The index handle.}';

    #[\Override]
    protected $description = 'Marks every record on a Caffeine index as needing a rebuild.';

    #[\Override]
    protected $aliases = ['caffeine/index/touch'];

    public function handle(Indexes $indexes, Records $records): int
    {
        $handle = (string)$this->argument('handle');
        $index = $indexes->getByHandle($handle);

        if ($index === null) {
            $this->error("No index with the handle “{$handle}”.");

            return self::FAILURE;
        }

        $count = $records->markIndexDirty($index->uid);
        $this->info("Marked {$count} records dirty.");

        return self::SUCCESS;
    }
}
