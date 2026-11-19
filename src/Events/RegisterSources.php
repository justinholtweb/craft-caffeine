<?php

declare(strict_types=1);

namespace JustinHoltWeb\Caffeine\Events;

/**
 * Dispatched once, when Caffeine first needs its source types. Add class names to `$sources`
 * to index a custom element type.
 */
final class RegisterSources
{
    /** @param list<class-string> $sources */
    public function __construct(public array $sources = []) {}
}
