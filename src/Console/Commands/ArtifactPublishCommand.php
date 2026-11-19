<?php

declare(strict_types=1);

namespace JustinHoltWeb\Caffeine\Console\Commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Command;
use JustinHoltWeb\Caffeine\Console\Commands\Concerns\ResolvesIndexes;
use JustinHoltWeb\Caffeine\Services\Artifacts;
use JustinHoltWeb\Caffeine\Services\Indexes;
use Throwable;

/**
 * Compiles and publishes one index, or every index when no handle is given.
 *
 * Separate from the `caffeine:index:*` commands, which deal in records. The two halves of a
 * build are genuinely separate operations — records are rebuilt whenever content changes,
 * artifacts are cut when that should become visible — and a deploy script usually wants to run
 * them at different moments.
 */
class ArtifactPublishCommand extends Command
{
    use CraftCommand;
    use ResolvesIndexes;

    #[\Override]
    protected $signature = 'caffeine:artifact:publish
        {handle? : The index to publish. Every index when omitted.}
        {--force : Publish even when the compiled artifact is identical to what is already live.}';

    #[\Override]
    protected $description = 'Compiles and publishes Caffeine artifacts.';

    #[\Override]
    protected $aliases = ['caffeine/artifact/publish'];

    public function handle(Indexes $indexes, Artifacts $artifacts): int
    {
        $handle = $this->argument('handle');
        $selected = $this->resolveIndexes(is_string($handle) ? $handle : null);

        if ($selected === []) {
            $this->error('No matching index.');

            return self::FAILURE;
        }

        $failed = false;

        foreach ($selected as $index) {
            if (!$indexes->isAllowed($index)) {
                $this->warn("Skipping “{$index->handle}” — not available on this edition.");
                continue;
            }

            if ($this->option('force')) {
                // Spends a version deliberately. The usual reason is that something outside
                // Caffeine ate the published files — a wiped web root, a new environment — and
                // the checksum match would otherwise convince it there was nothing to do.
                $artifacts->forget($index);
            }

            $this->line("<fg=cyan>Publishing “{$this->escape($index->handle)}”…</>");

            try {
                $result = $artifacts->publish($index);
            } catch (Throwable $e) {
                $this->error('  ' . $this->escape($e->getMessage()));
                $failed = true;
                continue;
            }

            if (!$result['published']) {
                $this->warn(sprintf(
                    '  Unchanged — v%d is still current (%d records).',
                    $result['version'],
                    $result['records'],
                ));

                continue;
            }

            $this->info(sprintf(
                '  v%d — %d records, %s, %dms%s',
                $result['version'],
                $result['records'],
                $this->formatBytes($result['bytes']),
                $result['buildMs'],
                $result['pruned'] > 0 ? sprintf(', %d version(s) pruned', $result['pruned']) : '',
            ));

            if ($result['url'] !== null) {
                $this->muted("  {$result['url']}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
