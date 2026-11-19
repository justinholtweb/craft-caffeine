<?php

declare(strict_types=1);

namespace JustinHoltWeb\Caffeine\Events;

use JustinHoltWeb\Caffeine\Models\IndexDefinition;

/**
 * Dispatched as an index loads, so a plugin or module can add attributes and sortings to it.
 *
 * ```php
 * Event::listen(DefineIndex::class, function (DefineIndex $event) {
 *     $event->index->attributes[] = new AttributeDefinition([...]);
 * });
 * ```
 *
 * What a listener adds is never saved to project config.
 */
final class DefineIndex
{
    public function __construct(public IndexDefinition $index) {}
}
