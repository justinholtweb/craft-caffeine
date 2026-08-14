<?php

namespace justinholtweb\caffeine\models;

use craft\base\ElementInterface;

/**
 * Carried through a single element's mapping, collecting the elements it turned out to depend
 * on along the way.
 *
 * Dependencies are gathered as a side effect of extraction rather than declared up front,
 * because only the extractor knows what it actually touched: a path like `categories.parent.title`
 * pulls in elements nobody configured explicitly.
 */
class MappingContext
{
    /** @var array<int, true> Used as a set; order does not matter and duplicates are common. */
    private array $dependencies = [];

    public function __construct(
        public readonly ElementInterface $element,
        public readonly int $siteId,
    ) {
        if ($element->id !== null) {
            $this->dependencies[$element->id] = true;
        }
    }

    public function dependOn(ElementInterface|int|null $element): void
    {
        $id = $element instanceof ElementInterface ? $element->id : $element;

        if ($id !== null && $id > 0) {
            $this->dependencies[$id] = true;
        }
    }

    /**
     * @return list<int>
     */
    public function dependencies(): array
    {
        return array_map('intval', array_keys($this->dependencies));
    }
}
