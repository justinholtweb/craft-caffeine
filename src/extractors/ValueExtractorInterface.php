<?php

namespace justinholtweb\caffeine\extractors;

/**
 * Turns a field's value object into something an index can hold.
 *
 * Without one of these, a Money field, a link field or an address indexes as whatever
 * `__toString()` happens to give — often the class name, sometimes nothing at all — and the
 * facet built from it is useless in a way that is hard to diagnose from the outside.
 *
 * Built-in extractors match on **shape rather than class name**, deliberately. Half the field
 * types worth handling belong to plugins Caffeine cannot depend on, and a link field is a link
 * field whether it came from Hyper, FreeLink, Craft's own Link field or something written last
 * week. Registering your own by class is still supported, and takes precedence.
 */
interface ValueExtractorInterface
{
    /**
     * Whether this extractor understands the value.
     *
     * Must not assume any class exists. Use `instanceof` against a class-string guarded by
     * `class_exists()`, or duck-type against the methods you are going to call.
     */
    public static function supports(object $value): bool;

    public function extract(object $value): ?ExtractedValue;
}
