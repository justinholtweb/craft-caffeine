<?php

namespace justinholtweb\caffeine\extractors;

/**
 * What an extractor makes of a field's value object.
 *
 * Two halves, because two different questions get asked of the same value. A facet wants one
 * scalar — a link's URL, a price's amount — and a path like `link.text` wants a named part. A
 * plain associative array could not serve both: `flatten()` would explode it into its values and
 * a facet would end up with the label and the URL as two separate options.
 *
 * So the primary is what the value *is* when nothing more specific is asked for, and the parts
 * are what a path can reach into.
 */
final class ExtractedValue
{
    /**
     * @param mixed $primary The scalar this value stands for.
     * @param array<string, mixed> $parts Named pieces a dotted path can descend into.
     */
    public function __construct(
        public readonly mixed $primary,
        public readonly array $parts = [],
    ) {
    }

    public function part(string $name): mixed
    {
        return $this->parts[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->parts);
    }
}
