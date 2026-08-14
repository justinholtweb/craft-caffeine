<?php

namespace justinholtweb\caffeine\extractors;

use Throwable;

/**
 * Craft's dropdown, radio, checkboxes and multi-select data.
 *
 * The stored value is the primary — it is what a refinement URL should carry, because it does not
 * change when someone edits the label — and the label is a part, for display.
 */
class OptionExtractor implements ValueExtractorInterface
{
    public static function supports(object $value): bool
    {
        // `selected` is the discriminator, and it is doing real work. "Has a value and a label"
        // matches far more than dropdown data — a FreeLink link model has both, and was being
        // read as an option until this was tightened, so `link.url` resolved to nothing while
        // `link.value` quietly worked.
        return property_exists($value, 'value')
            && property_exists($value, 'label')
            && property_exists($value, 'selected');
    }

    public function extract(object $value): ?ExtractedValue
    {
        try {
            $stored = $value->value;
            $label = $value->label;
        } catch (Throwable) {
            return null;
        }

        if ($stored === null || $stored === '') {
            return null;
        }

        return new ExtractedValue($stored, [
            'value' => $stored,
            'label' => is_string($label) && $label !== '' ? $label : (string)$stored,
        ]);
    }
}
