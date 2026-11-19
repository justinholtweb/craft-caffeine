<?php

declare(strict_types=1);

namespace JustinHoltWeb\Caffeine\Console\Commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Command;
use JustinHoltWeb\Caffeine\Console\Commands\Concerns\ResolvesIndexes;
use JustinHoltWeb\Caffeine\Services\Artifacts;
use JustinHoltWeb\Caffeine\Services\Indexes;

/**
 * Lists an index's recent versions.
 */
class ArtifactVersionsCommand extends Command
{
    use CraftCommand;
    use ResolvesIndexes;

    #[\Override]
    protected $signature = 'caffeine:artifact:versions {handle : The index handle.}';

    #[\Override]
    protected $description = 'Lists a Caffeine index’s recent artifact versions.';

    #[\Override]
    protected $aliases = ['caffeine/artifact/versions'];

    public function handle(Indexes $indexes, Artifacts $artifacts): int
    {
        $handle = (string)$this->argument('handle');
        $index = $indexes->getByHandle($handle);

        if ($index === null) {
            $this->error("No index with the handle “{$handle}”.");

            return self::FAILURE;
        }

        $versions = $artifacts->versions($index->uid);

        if ($versions === []) {
            $this->warn('Never published.');

            return self::SUCCESS;
        }

        foreach ($versions as $row) {
            $line = sprintf(
                '  v%-5d %-11s %7d records  %10s  %6dms  %s',
                $row['version'],
                $row['status'],
                $row['recordCount'],
                $this->formatBytes((int)$row['byteSize']),
                (int)$row['buildMs'],
                $row['dateCreated'],
            );

            $row['status'] === Artifacts::STATUS_LIVE ? $this->info($line) : $this->muted($line);

            if (!empty($row['error'])) {
                $this->error('        ' . $this->escape((string)$row['error']));
            }
        }

        return self::SUCCESS;
    }
}
