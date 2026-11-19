<?php

declare(strict_types=1);

namespace JustinHoltWeb\Caffeine\Console\Commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Command;
use JustinHoltWeb\Caffeine\Console\Commands\Concerns\ResolvesIndexes;
use JustinHoltWeb\Caffeine\Services\Artifacts;
use JustinHoltWeb\Caffeine\Services\Publisher;

/**
 * Shows what is published for each index, and whether it is behind the CMS.
 */
class ArtifactStatusCommand extends Command
{
    use CraftCommand;
    use ResolvesIndexes;

    #[\Override]
    protected $signature = 'caffeine:artifact:status {handle? : The index to show. Every index when omitted.}';

    #[\Override]
    protected $description = 'Shows what Caffeine has published for each index, and whether it is behind the CMS.';

    /** `caffeine/artifact` was the Craft 5 controller's default action. */
    #[\Override]
    protected $aliases = ['caffeine/artifact/status', 'caffeine/artifact'];

    public function handle(Artifacts $artifacts, Publisher $publisher): int
    {
        $handle = $this->argument('handle');
        $selected = $this->resolveIndexes(is_string($handle) ? $handle : null);

        if ($selected === []) {
            $this->warn('No indexes are defined yet.');

            return self::SUCCESS;
        }

        foreach ($selected as $index) {
            $status = $artifacts->status($index);

            $this->line(sprintf(
                '<fg=cyan;options=bold>%s</>  %s',
                $this->escape($index->handle),
                $this->escape($index->name),
            ));

            if ($status['version'] === null) {
                $this->warn('  Never published.');
            } else {
                $this->line(sprintf(
                    '  v%d  %d records  %s  built in %dms  %s',
                    $status['version'],
                    $status['records'],
                    $this->formatBytes($status['bytes']),
                    $status['buildMs'] ?? 0,
                    $status['publishedAt'] ?? '',
                ));
            }

            if ($status['dirty'] > 0) {
                $this->warn(sprintf(
                    '  %d record(s) changed since — run caffeine:index:build then caffeine:artifact:publish.',
                    $status['dirty'],
                ));
            }

            if ($status['url'] !== null) {
                $this->muted("  {$status['url']}");
            }
        }

        $this->newLine();
        $this->muted('Publishing to ' . $publisher->store()->describe());

        return self::SUCCESS;
    }
}
