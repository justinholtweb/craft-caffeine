<?php

namespace justinholtweb\caffeine\search;

use Normalizer;

/**
 * Tokenisation, per QUERY_SPEC §7.
 *
 * Static and dependency-free — no Craft, no Yii, no plugin instance. This is the one piece of
 * logic that genuinely runs in both languages at query time, so it is kept somewhere it can be
 * read side by side with its JavaScript twin and tested without an application.
 *
 * `services\Tokenizer` wraps this with the index-aware weighting used at build time.
 */
class Tokenizer
{
    public const MIN_LENGTH = 2;
    public const MAX_LENGTH = 64;

    /**
     * @return list<string>
     */
    public static function tokenize(string $text): array
    {
        $normalized = self::normalize($text);

        if ($normalized === '') {
            return [];
        }

        $parts = preg_split('/[^\p{L}\p{N}]+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = [];

        foreach ($parts as $part) {
            $length = mb_strlen($part);

            if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
                continue;
            }

            $tokens[] = $part;
        }

        return $tokens;
    }

    /**
     * Lowercases and strips diacritics, so "Café" and "cafe" are the same token.
     *
     * Decomposing to NFD and dropping the combining marks handles every accented Latin
     * character without a transliteration table, and leaves scripts that have no case or
     * accents — CJK, Arabic — untouched rather than mangled. JavaScript's
     * `String.normalize('NFD')` does exactly the same thing, which is why this is the rule.
     */
    public static function normalize(string $text): string
    {
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = mb_strtolower($text, 'UTF-8');

        if (class_exists(Normalizer::class)) {
            $decomposed = Normalizer::normalize($text, Normalizer::FORM_D);

            if ($decomposed !== false) {
                $text = preg_replace('/\p{Mn}+/u', '', $decomposed) ?? $text;
            }
        }

        return trim($text);
    }
}
