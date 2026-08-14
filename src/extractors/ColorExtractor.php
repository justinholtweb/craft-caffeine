<?php

namespace justinholtweb\caffeine\extractors;

use Throwable;

/**
 * Craft's colour field. The hex is the value; the rest is for rendering a swatch in a card.
 */
class ColorExtractor implements ValueExtractorInterface
{
    public static function supports(object $value): bool
    {
        return method_exists($value, 'getHex') && method_exists($value, 'getRgb');
    }

    public function extract(object $value): ?ExtractedValue
    {
        try {
            $hex = $value->getHex();
        } catch (Throwable) {
            return null;
        }

        if (!is_string($hex) || $hex === '') {
            return null;
        }

        $parts = ['hex' => $hex];

        try {
            $parts['rgb'] = $value->getRgb();
        } catch (Throwable) {
            // Optional.
        }

        return new ExtractedValue($hex, $parts);
    }
}
