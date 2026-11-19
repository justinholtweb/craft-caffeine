<?php

declare(strict_types=1);

namespace JustinHoltWeb\Caffeine\Events;

use JustinHoltWeb\Caffeine\Models\IndexDefinition;
use JustinHoltWeb\Caffeine\Models\SourceDefinition;
use JustinHoltWeb\Caffeine\Sources\SourceInterface;

/**
 * Dispatched with the element query a build loads a source's elements with, so an index can
 * eager-load the relations its mapping reads.
 */
final class DefineSourceQuery
{
    public function __construct(
        public IndexDefinition $index,
        public SourceInterface $source,
        public SourceDefinition $definition,
        public int $siteId,
        public mixed $query,
    ) {}
}
