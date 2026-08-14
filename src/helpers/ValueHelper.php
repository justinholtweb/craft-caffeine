<?php

namespace justinholtweb\caffeine\helpers;

use craft\base\ElementInterface;
use craft\elements\Asset;
use justinholtweb\caffeine\extractors\ExtractedValue;
use craft\helpers\StringHelper;
use DateTimeInterface;
use justinholtweb\caffeine\models\AttributeDefinition;
use Throwable;

/**
 * Turning arbitrary Craft values into the narrow set of shapes an artifact can hold.
 *
 * Facet values must be scalars, because refinement is set membership and a visitor clicks one
 * value at a time. Payload values may be structured, because Twig renders them. Searchable
 * values are flattened to text. All three come from the same extracted value, which is why the
 * conversions live together.
 */
class ValueHelper
{
    /**
     * Flattens nested arrays into a single list, dropping nulls and empties.
     *
     * Only arrays are containers. This looks over-specific and is not: `yii\base\Model`
     * implements `IteratorAggregate`, so every Craft element is technically Traversable, and
     * flattening "anything iterable" silently explodes an element into its attribute values.
     * Relations then arrive here as a list of strings, no path segment can descend through
     * them, and every relation-backed facet comes out empty — with the dependency map still
     * looking correct, because the elements really were read.
     *
     * Element queries and collections are resolved upstream, in `Mapper::materialize()`, which
     * is also where their elements get recorded as dependencies.
     *
     * @return list<mixed>
     */
    public static function flatten(mixed $value, bool $unwrap = true): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if ($value instanceof ExtractedValue) {
            // Unwrapped to its primary by default, which is what a facet or a sort wants — the
            // URL of a link, the amount of a price. Callers that need the named parts, or the
            // path walker that needs to descend into them, ask for the wrapper itself.
            return $unwrap ? self::flatten($value->primary, true) : [$value];
        }

        if (!is_array($value)) {
            return [$value];
        }

        $flat = [];

        foreach ($value as $item) {
            foreach (self::flatten($item, $unwrap) as $inner) {
                $flat[] = $inner;
            }
        }

        return $flat;
    }

    /**
     * Facet values: a list of scalars, deduplicated, in a deterministic order.
     *
     * Determinism matters more than it looks. Facet values are interned to integers at build
     * time, and if the same content produced them in a different order on two builds, every
     * postings list would change and every artifact would look modified even when nothing was.
     *
     * @return list<string|float|bool>
     */
    public static function facetValues(mixed $value, AttributeDefinition $attribute): array
    {
        $values = [];

        foreach (self::flatten($value) as $item) {
            $scalar = self::toFacetScalar($item, $attribute);

            if ($scalar !== null) {
                $values[] = $scalar;
            }
        }

        // array_unique compares loosely by string, which would collapse 0 and "0" — fine here,
        // since a facet's values are all the same type by construction.
        $values = array_values(array_unique($values, SORT_REGULAR));

        sort($values, SORT_REGULAR);

        return $values;
    }

    private static function toFacetScalar(mixed $item, AttributeDefinition $attribute): string|float|bool|null
    {
        if ($item instanceof DateTimeInterface) {
            return $attribute->isNumericFacet()
                ? (float)$item->getTimestamp()
                : $item->format('Y-m-d');
        }

        if ($item instanceof ElementInterface) {
            // An element used as a facet value is its title. Its id would be stable but
            // meaningless in a URL, and stability is already handled by the dependency map
            // marking the record dirty when the title changes.
            $item = (string)$item->title;
        }

        if (is_bool($item)) {
            return $attribute->facetType === AttributeDefinition::FACET_BOOLEAN
                ? $item
                : ($item ? 'true' : 'false');
        }

        if (is_object($item)) {
            $item = self::stringify($item);
        }

        if ($item === null || $item === '') {
            return null;
        }

        if ($attribute->isNumericFacet()) {
            return is_numeric($item) ? (float)$item : null;
        }

        if ($attribute->facetType === AttributeDefinition::FACET_BOOLEAN) {
            return (bool)$item;
        }

        return (string)$item;
    }

    /**
     * The single scalar a sort orders by. Lists collapse to their first value, because a sort
     * needs one key per record and "the first colour" is at least predictable.
     */
    public static function sortableValue(mixed $value): string|float|null
    {
        $flat = self::flatten($value);

        if ($flat === []) {
            return null;
        }

        $first = $flat[0];

        if ($first instanceof DateTimeInterface) {
            return (float)$first->getTimestamp();
        }

        if ($first instanceof ElementInterface) {
            $first = (string)$first->title;
        }

        if (is_bool($first)) {
            return $first ? 1.0 : 0.0;
        }

        if (is_object($first)) {
            $first = self::stringify($first);
        }

        if ($first === null || $first === '') {
            return null;
        }

        return is_numeric($first) ? (float)$first : (string)$first;
    }

    /**
     * Payload: JSON-safe, and structured where structure is what the card needs.
     */
    public static function payloadValue(mixed $value): mixed
    {
        // Unwrapped: a card wants a link's text *and* its URL, an address's city *and* its
        // coordinates. This is the one place the named parts earn their keep.
        $flat = self::flatten($value, false);

        if ($flat === []) {
            return null;
        }

        $converted = array_map(self::toPayloadScalar(...), $flat);

        // A single value stays a single value. Wrapping everything in an array would make
        // every template write `{{ hit.title|first }}`.
        return count($converted) === 1 ? $converted[0] : $converted;
    }

    private static function toPayloadScalar(mixed $item): mixed
    {
        if ($item instanceof ExtractedValue) {
            return $item->parts !== []
                ? array_map(self::toPayloadScalar(...), $item->parts)
                : self::toPayloadScalar($item->primary);
        }

        if ($item instanceof DateTimeInterface) {
            return $item->format(DateTimeInterface::ATOM);
        }

        if ($item instanceof Asset) {
            return array_filter([
                'id' => $item->id,
                'title' => $item->title,
                'url' => self::assetUrl($item),
                'alt' => $item->alt,
                'width' => $item->getWidth(),
                'height' => $item->getHeight(),
            ], fn($v) => $v !== null);
        }

        if ($item instanceof ElementInterface) {
            return array_filter([
                'id' => $item->id,
                'title' => $item->title,
                'slug' => $item->slug ?? null,
                'url' => $item->getUrl(),
            ], fn($v) => $v !== null);
        }

        if (is_scalar($item)) {
            return $item;
        }

        return self::stringify($item);
    }

    /**
     * Searchable text: everything flattened and joined, ready for the tokeniser.
     */
    public static function searchableText(mixed $value): string
    {
        $parts = [];

        foreach (self::flatten($value, false) as $item) {
            if ($item instanceof ExtractedValue) {
                // A link's *text* is worth indexing; its URL is not — nobody searches for
                // "https". Same for an address: the formatted line, not the latitude.
                $item = $item->part('text')
                    ?? $item->part('label')
                    ?? $item->part('formatted')
                    ?? $item->primary;
            }

            if ($item instanceof DateTimeInterface) {
                continue;
            }

            if ($item instanceof ElementInterface) {
                $parts[] = (string)$item->title;
                continue;
            }

            if (is_bool($item)) {
                continue;
            }

            $parts[] = is_scalar($item) ? (string)$item : self::stringify($item);
        }

        return trim(implode(' ', array_filter($parts, fn(string $p) => $p !== '')));
    }

    /**
     * A coordinate pair, from an address value, a plain array, or a "lat,lng" string.
     *
     * Three shapes because three things produce them: an address field through its extractor, a
     * pair of numeric fields mapped by hand, and a plain text field someone typed coordinates
     * into. All three are common enough to be worth reading.
     *
     * @return array{0: float, 1: float}|null
     */
    public static function geoValue(mixed $value): ?array
    {
        foreach (self::flatten($value, false) as $item) {
            if ($item instanceof ExtractedValue) {
                $lat = $item->part('lat');
                $lng = $item->part('lng');

                if (is_numeric($lat) && is_numeric($lng)) {
                    return [(float)$lat, (float)$lng];
                }

                $item = $item->primary;
            }

            if (is_array($item) && count($item) === 2) {
                $pair = array_values($item);

                if (is_numeric($pair[0]) && is_numeric($pair[1])) {
                    return [(float)$pair[0], (float)$pair[1]];
                }
            }

            if (is_string($item) && str_contains($item, ',')) {
                $pair = array_map('trim', explode(',', $item, 2));

                if (is_numeric($pair[0]) && is_numeric($pair[1])) {
                    return [(float)$pair[0], (float)$pair[1]];
                }
            }
        }

        return null;
    }

    /**
     * Applies an attribute's transform list.
     *
     * A fixed vocabulary, not an expression language: this runs over every value of every
     * record on every build, and it arrives from project config, which is neither a safe nor a
     * fast place for arbitrary code.
     *
     * @param list<mixed> $values
     * @param string[] $transforms
     * @return list<mixed>
     */
    public static function applyTransforms(array $values, array $transforms): array
    {
        foreach ($transforms as $transform) {
            [$name, $argument] = array_pad(explode(':', $transform, 2), 2, null);

            $values = match ($name) {
                'trim' => array_map(fn($v) => is_string($v) ? trim($v) : $v, $values),
                'lower' => array_map(fn($v) => is_string($v) ? mb_strtolower($v) : $v, $values),
                'upper' => array_map(fn($v) => is_string($v) ? mb_strtoupper($v) : $v, $values),
                'stripTags' => array_map(fn($v) => is_string($v) ? strip_tags($v) : $v, $values),
                'slug' => array_map(fn($v) => is_string($v) ? StringHelper::slugify($v) : $v, $values),
                'unique' => array_values(array_unique($values, SORT_REGULAR)),
                'first' => array_slice($values, 0, 1),
                'sort' => self::sorted($values),
                'compact' => array_values(array_filter($values, fn($v) => $v !== null && $v !== '')),
                'date' => array_map(fn($v) => $v instanceof DateTimeInterface ? $v->format($argument ?: 'Y-m-d') : $v, $values),
                default => $values,
            };
        }

        return array_values($values);
    }

    /**
     * @param list<mixed> $values
     * @return list<mixed>
     */
    private static function sorted(array $values): array
    {
        sort($values, SORT_REGULAR);

        return $values;
    }

    /**
     * Last resort for objects that are neither elements nor dates — a Money, a custom field's
     * value model. `__toString` when it has one, otherwise nothing, because guessing at an
     * object's shape produces facet values like `App\Models\Foo`.
     */
    private static function stringify(object $value): ?string
    {
        if (!method_exists($value, '__toString')) {
            return null;
        }

        try {
            $string = (string)$value;
        } catch (Throwable) {
            return null;
        }

        return $string !== '' ? $string : null;
    }

    private static function assetUrl(Asset $asset): ?string
    {
        try {
            return $asset->getUrl();
        } catch (Throwable) {
            // A volume on a filesystem with no public URL throws rather than returning null.
            return null;
        }
    }
}
