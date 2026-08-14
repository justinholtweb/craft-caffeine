<?php

namespace justinholtweb\caffeine\search;

/**
 * The canonical string projection of a facet value, per ARTIFACT.md §10.
 *
 * A facet value is a string, a number or a boolean, but three places need it as a string: the
 * Algolia-shaped `facets` map (QUERY_SPEC §9), the query string a facet link carries, and the
 * `data-` attributes the runtime reads back. All three must produce the same characters in PHP
 * and in JavaScript, or a link the server rendered would not match the value the browser looks
 * up — the refinement would appear to do nothing.
 *
 * Numbers are formatted rather than cast, deliberately. PHP's `(string)` uses the `precision`
 * ini setting and switches to exponent notation for large values; JavaScript's `String()` does
 * neither. Fixed-point formatting is the one rule both languages implement identically.
 *
 * Twin of runtime/src/facet-value.js; pinned by tests/Conformance/facetvalue.json.
 */
class FacetValue
{
    /** Decimal places kept for non-integral values. Beyond this the two languages can disagree. */
    public const PRECISION = 6;

    public static function toKey(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value)) {
            return (string)$value;
        }

        if (is_float($value)) {
            if (!is_finite($value)) {
                return (string)$value;
            }

            if ($value === floor($value)) {
                // `%.0F` rather than a cast: never exponent notation, and identical to
                // JavaScript's `toFixed(0)` for every value either language can hold.
                return sprintf('%.0F', $value);
            }

            return self::trimZeros(sprintf('%.' . self::PRECISION . 'F', $value));
        }

        return (string)$value;
    }

    /**
     * Resolves a projected key back to the value the artifact actually stores.
     *
     * Matching against the stored values rather than coercing by facet type is what keeps the
     * engine's strict comparison working: a numeric facet may hold `int(10)` or `float(10.0)`
     * depending on how the field serialised, and guessing wrong means the refinement silently
     * matches nothing.
     *
     * @param list<mixed> $values The facet's interning dictionary.
     * @return mixed The stored value, or the key itself when nothing matches — a refinement can
     *               name a value that has since disappeared, which QUERY_SPEC §3.2 treats as
     *               matching nothing rather than as an error.
     */
    public static function fromKey(string $key, array $values): mixed
    {
        foreach ($values as $value) {
            if (self::toKey($value) === $key) {
                return $value;
            }
        }

        return $key;
    }

    private static function trimZeros(string $formatted): string
    {
        $trimmed = rtrim($formatted, '0');

        return rtrim($trimmed, '.');
    }
}
