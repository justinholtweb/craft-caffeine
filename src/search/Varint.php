<?php

namespace justinholtweb\caffeine\search;

use InvalidArgumentException;

/**
 * Base64-wrapped LEB128 varints, per ARTIFACT.md §3.
 *
 * The artifact's bulk is integer lists — postings, permutations, value indexes — and JSON spends
 * roughly five bytes on an id that a varint spends one or two on. Encoding them shrinks the
 * artifact by more than gzip alone does, because gzip cannot see that `[1,2,3]` is three small
 * numbers rather than seven characters.
 *
 * Like `Tokenizer`, this is static and dependency-free, and it is the third piece of logic that
 * genuinely exists in both languages — its twin is runtime/src/varint.js. A disagreement here
 * would not rearrange the page, it would corrupt the index outright, so tests/Conformance/
 * varint.json pins the two against the same vectors.
 *
 * Two codecs, deliberately:
 *
 * - `encode()` / `decode()` take any non-negative ints. Used for permutations (a sorting is an
 *   arbitrary order, so its deltas would go negative), lengths, and quantised weights.
 * - `encodeDelta()` / `decodeDelta()` take an ascending list and store the gaps. Used for
 *   postings, where the gaps are far smaller than the ids and usually fit in one byte.
 *
 * Values are capped at 2^53 - 1: JavaScript has no integers beyond that, and a codec whose two
 * halves disagree at the top of their range is worse than one that refuses the value.
 */
class Varint
{
    /** JavaScript's `Number.MAX_SAFE_INTEGER`. Beyond it the two implementations diverge. */
    public const MAX_VALUE = 9007199254740991;

    /**
     * @param iterable<int> $values Non-negative, any order.
     */
    public static function encode(iterable $values): string
    {
        $out = '';

        foreach ($values as $value) {
            $out .= self::encodeOne((int)$value);
        }

        return base64_encode($out);
    }

    /**
     * @return list<int>
     */
    public static function decode(string $encoded): array
    {
        if ($encoded === '') {
            return [];
        }

        $bytes = base64_decode($encoded, true);

        if ($bytes === false) {
            throw new InvalidArgumentException('Varint payload is not valid base64.');
        }

        $values = [];
        $current = 0;
        $shift = 0;
        $length = strlen($bytes);

        for ($i = 0; $i < $length; $i++) {
            $byte = ord($bytes[$i]);
            // Multiplication rather than a shift, so PHP and JavaScript overflow at the same
            // place — JavaScript's `<<` is 32-bit and would silently wrap where PHP would not.
            $current += ($byte & 0x7F) * (2 ** $shift);

            if (($byte & 0x80) === 0) {
                $values[] = (int)$current;
                $current = 0;
                $shift = 0;
                continue;
            }

            $shift += 7;

            if ($shift > 56) {
                throw new InvalidArgumentException('Varint payload contains an out-of-range value.');
            }
        }

        if ($shift !== 0) {
            throw new InvalidArgumentException('Varint payload ends mid-value.');
        }

        return $values;
    }

    /**
     * @param list<int> $values Ascending. Equal neighbours are allowed; descending is not.
     */
    public static function encodeDelta(array $values): string
    {
        $out = '';
        $previous = 0;

        foreach ($values as $value) {
            $value = (int)$value;
            $delta = $value - $previous;

            if ($delta < 0) {
                throw new InvalidArgumentException('Delta-encoded lists must be ascending.');
            }

            $out .= self::encodeOne($delta);
            $previous = $value;
        }

        return base64_encode($out);
    }

    /**
     * @return list<int>
     */
    public static function decodeDelta(string $encoded): array
    {
        $values = [];
        $running = 0;

        foreach (self::decode($encoded) as $delta) {
            $running += $delta;
            $values[] = $running;
        }

        return $values;
    }

    private static function encodeOne(int $value): string
    {
        if ($value < 0) {
            throw new InvalidArgumentException('Varints are unsigned; got ' . $value . '.');
        }

        if ($value > self::MAX_VALUE) {
            throw new InvalidArgumentException('Varint value exceeds the safe integer range.');
        }

        $out = '';

        do {
            $byte = $value & 0x7F;
            $value = intdiv($value, 128);

            if ($value !== 0) {
                $byte |= 0x80;
            }

            $out .= chr($byte);
        } while ($value !== 0);

        return $out;
    }
}
