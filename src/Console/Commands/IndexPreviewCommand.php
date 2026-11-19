<?php

declare(strict_types=1);

namespace JustinHoltWeb\Caffeine\Console\Commands;

use CraftCms\Cms\Console\CraftCommand;
use CraftCms\Cms\Support\Facades\Elements;
use Illuminate\Console\Command;
use JustinHoltWeb\Caffeine\Services\Indexes;
use JustinHoltWeb\Caffeine\Services\Mapper;
use JustinHoltWeb\Caffeine\Services\Tokenizer;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints the record Caffeine would build for one element, so a definition can be checked
 * against real content without rebuilding anything.
 */
class IndexPreviewCommand extends Command
{
    use CraftCommand;

    #[\Override]
    protected $signature = 'caffeine:index:preview
        {handle : The index handle.}
        {elementId : The element to map.}';

    #[\Override]
    protected $description = 'Prints the record Caffeine would build for one element.';

    #[\Override]
    protected $aliases = ['caffeine/index/preview'];

    public function handle(Indexes $indexes, Mapper $mapper, Tokenizer $tokenizer): int
    {
        $handle = (string) $this->argument('handle');
        $elementId = (int) $this->argument('elementId');
        $index = $indexes->getByHandle($handle);

        if ($index === null) {
            $this->error("No index with the handle “{$handle}”.");

            return self::FAILURE;
        }

        $element = Elements::getElementById($elementId);

        if ($element === null) {
            $this->error("No element with the id {$elementId}.");

            return self::FAILURE;
        }

        $record = $mapper->map($index, $element);

        $this->output->writeln((string) json_encode([
            'objectID' => $record->objectId,
            'facets' => $record->facets,
            'sortable' => $record->sortable,
            'payload' => $record->payload,
            'searchable' => $record->searchable,
            'tokens' => $tokenizer->tokenizeRecord($index, $record),
            'dependsOn' => $record->dependencies,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }
}
