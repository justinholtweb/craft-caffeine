<?php

namespace justinholtweb\caffeine\extractors;

use Throwable;

/**
 * Anything that is really a list wearing an object.
 *
 * Hyper and FreeLink both hand back a link *collection* rather than a link, and Craft's own
 * collections turn up nested inside Matrix content. Unwrapping them here means every other
 * extractor only ever has to understand a single item.
 */
class CollectionExtractor implements ValueExtractorInterface
{
    public static function supports(object $value): bool
    {
        foreach (['getAll', 'all'] as $method) {
            if (method_exists($value, $method)) {
                return true;
            }
        }

        return false;
    }

    public function extract(object $value): ?ExtractedValue
    {
        foreach (['getAll', 'all'] as $method) {
            if (!method_exists($value, $method)) {
                continue;
            }

            try {
                $items = $value->$method();
            } catch (Throwable) {
                continue;
            }

            if (is_array($items)) {
                // The list is the primary *and* the whole of it: a path segment after a
                // collection is meant for the items, and `descend()` walks lists already.
                return new ExtractedValue($items);
            }
        }

        return null;
    }
}
