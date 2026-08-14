<?php

namespace justinholtweb\caffeine\search;

/**
 * Great-circle distance, per QUERY_SPEC §10. Twin of runtime/src/geo.js.
 *
 * The fifth piece of logic that exists in both languages, and the one most exposed to floating
 * point. `sin`, `cos` and `sqrt` are not guaranteed to agree to the last bit between PHP and a
 * JavaScript engine, and a record sitting exactly on the radius could then be inside it on the
 * server and outside it in the browser — the page rearranging itself under the visitor, from a
 * difference of one ulp.
 *
 * So distance is **rounded to whole metres** and compared as an integer. That is far finer than
 * any radius anyone filters by, and it makes the comparison deterministic rather than
 * approximately deterministic.
 */
class Geo
{
    /** IUGG mean Earth radius, in metres. */
    public const EARTH_RADIUS = 6371008.8;

    /**
     * @return int Metres, rounded.
     */
    public static function distance(float $lat1, float $lng1, float $lat2, float $lng2): int
    {
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $deltaPhi = deg2rad($lat2 - $lat1);
        $deltaLambda = deg2rad($lng2 - $lng1);

        $a = sin($deltaPhi / 2) ** 2
            + cos($phi1) * cos($phi2) * sin($deltaLambda / 2) ** 2;

        // atan2 rather than asin: asin loses precision for antipodal points, where the argument
        // approaches 1 and its derivative goes to infinity.
        return (int)round(self::EARTH_RADIUS * 2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a))));
    }

    /**
     * Whether a coordinate pair looks usable.
     *
     * A missing address maps to null, but a half-filled one maps to `[0, 0]` — a real point in
     * the Gulf of Guinea that would quietly match a radius search from anywhere in west Africa.
     *
     * @param mixed $point
     */
    public static function isValid(mixed $point): bool
    {
        if (!is_array($point) || count($point) !== 2) {
            return false;
        }

        [$lat, $lng] = array_values($point);

        return is_numeric($lat) && is_numeric($lng)
            && abs((float)$lat) <= 90 && abs((float)$lng) <= 180
            && ((float)$lat !== 0.0 || (float)$lng !== 0.0);
    }
}
