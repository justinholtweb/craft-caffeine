<?php

declare(strict_types=1);

namespace JustinHoltWeb\Caffeine\Events;

/**
 * Dispatched once, when Caffeine first needs its value extractors. Add class names to
 * `$extractors` to teach it a new field-value shape.
 */
final class RegisterExtractors
{
    /** @param list<class-string> $extractors */
    public function __construct(public array $extractors = []) {}
}
