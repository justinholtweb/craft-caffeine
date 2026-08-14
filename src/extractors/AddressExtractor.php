<?php

namespace justinholtweb\caffeine\extractors;

use Throwable;

/**
 * An address or coordinate pair — Google Maps' `Address`, Craft's own `Address` element data,
 * or anything else carrying a latitude and a longitude.
 *
 * The parts are what make a geo facet possible at all: `location.lat` and `location.lng` are
 * ordinary numeric attributes once they are reachable, which is how a radius filter gets built
 * without the engine learning what an address is.
 */
class AddressExtractor implements ValueExtractorInterface
{
    private const PARTS = [
        'lat', 'lng', 'street1', 'street2', 'city', 'state', 'zip', 'country', 'countryCode', 'county',
    ];

    public static function supports(object $value): bool
    {
        return self::read($value, 'lat') !== null && self::read($value, 'lng') !== null;
    }

    public function extract(object $value): ?ExtractedValue
    {
        $parts = [];

        foreach (self::PARTS as $name) {
            $part = self::read($value, $name);

            if ($part !== null && $part !== '') {
                $parts[$name] = in_array($name, ['lat', 'lng'], true) ? (float)$part : $part;
            }
        }

        if ($parts === []) {
            return null;
        }

        $formatted = self::stringify($value);

        if ($formatted !== null) {
            $parts['formatted'] = $formatted;
        }

        // The formatted address is the primary — it is the only part that reads as a value on
        // its own. A bare latitude as a facet value would be meaningless.
        return new ExtractedValue($formatted ?? ($parts['city'] ?? null), $parts);
    }

    private static function read(object $value, string $name): mixed
    {
        try {
            if (property_exists($value, $name) || isset($value->$name)) {
                return $value->$name;
            }

            $getter = 'get' . ucfirst($name);

            if (method_exists($value, $getter)) {
                return $value->$getter();
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    private static function stringify(object $value): ?string
    {
        if (!method_exists($value, '__toString')) {
            return null;
        }

        try {
            $string = trim((string)$value);
        } catch (Throwable) {
            return null;
        }

        return $string !== '' ? $string : null;
    }
}
