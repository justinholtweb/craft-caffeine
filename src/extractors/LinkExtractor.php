<?php

namespace justinholtweb\caffeine\extractors;

use Throwable;

/**
 * A link, from whichever plugin produced it.
 *
 * Craft's own Link field, Hyper, FreeLink and most hand-rolled link models all expose a URL and
 * a label under one of a handful of names. Matching on those rather than on a class means a new
 * link plugin works without a release here.
 *
 * The URL is the primary, because that is what a facet or a sort over a link should be. The text
 * is a part, so `banner.text` reaches it.
 */
class LinkExtractor implements ValueExtractorInterface
{
    private const URL_METHODS = ['getUrl', 'getLink'];
    private const TEXT_METHODS = ['getLabel', 'getText', 'getLinkText', 'getTitle'];

    public static function supports(object $value): bool
    {
        foreach (self::URL_METHODS as $method) {
            if (method_exists($value, $method)) {
                return true;
            }
        }

        return false;
    }

    public function extract(object $value): ?ExtractedValue
    {
        $url = self::call($value, self::URL_METHODS);

        // `getLink()` on some models returns rendered markup rather than a URL. A link that is
        // not a string is not a link this extractor can speak for.
        if (!is_string($url) || $url === '') {
            return null;
        }

        $parts = ['url' => $url];

        $text = self::call($value, self::TEXT_METHODS);

        if (is_string($text) && $text !== '') {
            $parts['text'] = $text;
        }

        $type = self::call($value, ['getType']);

        if (is_string($type) && $type !== '') {
            $parts['type'] = $type;
        }

        return new ExtractedValue($url, $parts);
    }

    /**
     * @param list<string> $methods
     */
    private static function call(object $value, array $methods): mixed
    {
        foreach ($methods as $method) {
            if (!method_exists($value, $method)) {
                continue;
            }

            try {
                return $value->$method();
            } catch (Throwable) {
                // A link pointing at a deleted element throws rather than returning null.
                continue;
            }
        }

        return null;
    }
}
