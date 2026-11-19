<?php

declare(strict_types=1);

namespace JustinHoltWeb\Caffeine\Console\Commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Command;
use JustinHoltWeb\Caffeine\Services\Artifacts;
use JustinHoltWeb\Caffeine\Services\Indexes;

/**
 * Checks that what is published matches what the stored records would compile to.
 *
 * Reads the artifact back out of the store, decodes it, and compares it against a fresh compile
 * — so it exercises the encoder, the decoder and the publisher against real content rather than
 * fixtures. Answers the operational question too: is what visitors are being served actually
 * current?
 */
class ArtifactVerifyCommand extends Command
{
    use CraftCommand;

    #[\Override]
    protected $signature = 'caffeine:artifact:verify {handle : The index handle.}';

    #[\Override]
    protected $description = 'Checks that a published Caffeine artifact matches a fresh compile of its records.';

    #[\Override]
    protected $aliases = ['caffeine/artifact/verify'];

    public function handle(Indexes $indexes, Artifacts $artifacts): int
    {
        $handle = (string) $this->argument('handle');
        $index = $indexes->getByHandle($handle);

        if ($index === null) {
            $this->error("No index with the handle “{$handle}”.");

            return self::FAILURE;
        }

        $published = $artifacts->published($index);

        if ($published === null) {
            $this->error("Nothing is published for “{$handle}”.");

            return self::FAILURE;
        }

        $fresh = $artifacts->compile($index, $published->version)->toArray();
        $live = $published->toArray();

        $this->line(sprintf('  v%d, %d records', $published->version, $published->recordCount()));

        if ($live === $fresh) {
            $this->info('  Published artifact matches a fresh compile.');

            return self::SUCCESS;
        }

        $this->error('  Published artifact differs from a fresh compile.');

        foreach (array_keys($fresh) as $section) {
            if (($live[$section] ?? null) !== $fresh[$section]) {
                $this->error("    {$section} differs");
            }
        }

        $this->warn('  Run caffeine:index:build then caffeine:artifact:publish.');

        return self::FAILURE;
    }
}
