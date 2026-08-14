<?php

namespace justinholtweb\caffeine\search;

/**
 * Value comparison, per QUERY_SPEC §8.
 *
 * Every ordering decision in the engine routes through here so there is exactly one place where
 * "which of these two values comes first" is answered, and one place for the JS twin to match.
 */
class Comparator
{
    /**
     * Returns <0, 0 or >0.
     *
     * Deliberately **not** locale-aware. `Collator` in PHP and `localeCompare` in JavaScript
     * order accented and cased characters differently, and differently again depending on the
     * ICU version present — so a server and a browser sorting the same facet would disagree,
     * intermittently, on some machines only. Code-unit order is ugly for humans and identical
     * everywhere, and a widget that wants locale order can sort the buckets it was handed.
     */
    public static function compare(mixed $a, mixed $b): int
    {
        $rankA = self::typeRank($a);
        $rankB = self::typeRank($b);

        if ($rankA !== $rankB) {
            return $rankA <=> $rankB;
        }

        return match ($rankA) {
            0 => self::compareNumbers((float)$a, (float)$b),
            1 => strcmp((string)$a, (string)$b),
            default => ((int)(bool)$a) <=> ((int)(bool)$b),
        };
    }

    /**
     * Numbers before strings before booleans.
     */
    private static function typeRank(mixed $value): int
    {
        if (is_int($value) || is_float($value)) {
            return 0;
        }

        if (is_bool($value)) {
            return 2;
        }

        return 1;
    }

    private static function compareNumbers(float $a, float $b): int
    {
        // <=> on floats returns 0 for equal, which is what we want, but NAN would make the
        // comparison non-transitive and corrupt the sort. Treated as equal-to-everything is
        // still wrong, so NAN sorts last consistently instead.
        if (is_nan($a)) {
            return is_nan($b) ? 0 : 1;
        }

        if (is_nan($b)) {
            return -1;
        }

        return $a <=> $b;
    }

    /**
     * Sorts a list in place using `compare`, with a caller-supplied key extractor.
     *
     * @template T
     * @param list<T> $values
     * @param callable(T): mixed $key
     * @return list<T>
     */
    public static function sortBy(array $values, callable $key): array
    {
        usort($values, fn($a, $b) => self::compare($key($a), $key($b)));

        return $values;
    }
}
