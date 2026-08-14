<?php

namespace justinholtweb\caffeine\services;

use craft\base\Component;
use justinholtweb\caffeine\models\IndexDefinition;
use justinholtweb\caffeine\models\MappedRecord;
use justinholtweb\caffeine\search\Tokenizer as Rules;

/**
 * Turns searchable text into weighted tokens.
 *
 * This runs in PHP and only in PHP, at index time. It is the decision the whole design leans
 * on: because tokenising happens once, on the server, the artifact carries a finished inverted
 * index and the browser needs no search library at all — just a binary search over a sorted
 * token array.
 *
 * The one piece that must exist in both languages is the *query* tokeniser, which is the same
 * normalisation applied to a handful of words rather than a whole corpus. It is small enough
 * to keep in step by hand, and the conformance fixtures check that it is.
 */
class Tokenizer extends Component
{
    /** Single characters are noise in a facet UI and blow up the token dictionary. */
    public const MIN_LENGTH = Rules::MIN_LENGTH;

    /** Guards against a corrupt field turning into one enormous token. */
    public const MAX_LENGTH = Rules::MAX_LENGTH;

    /**
     * Tokenises every searchable attribute on a record, accumulating per-token weight.
     *
     * Weight is summed rather than maxed: a term appearing in both the title and the body is a
     * better match than one appearing in the title alone, and summing is the cheapest rule
     * that both engines can agree on exactly.
     *
     * @return array<string, float> token => weight
     */
    public function tokenizeRecord(IndexDefinition $index, MappedRecord $record): array
    {
        $weights = [];
        $stopwords = self::stopwords($index);
        $synonyms = self::synonyms($index);

        foreach ($index->searchableAttributes() as $attribute) {
            $text = $record->searchable[$attribute->key] ?? '';

            if ($text === '') {
                continue;
            }

            foreach ($this->tokenize($text) as $token) {
                if (isset($stopwords[$token])) {
                    continue;
                }

                $weights[$token] = ($weights[$token] ?? 0.0) + $attribute->searchWeight;

                // Synonyms are expanded here, at index time, so a record containing "sofa" is
                // findable by "couch" without either engine knowing what a synonym is. Expanding
                // at query time instead would mean shipping the map and implementing the lookup
                // twice, in two languages, for no behavioural gain.
                foreach ($synonyms[$token] ?? [] as $synonym) {
                    if (isset($stopwords[$synonym])) {
                        continue;
                    }

                    $weights[$synonym] = ($weights[$synonym] ?? 0.0) + $attribute->searchWeight;
                }
            }
        }

        // Sorted so two builds of identical content produce byte-identical output, which is
        // what lets the publisher skip republishing when nothing really changed.
        ksort($weights);

        return $weights;
    }

    /**
     * The index's stopwords, normalised and keyed for lookup.
     *
     * Run through the same tokeniser as the content, so "The" in the settings matches "the" in a
     * document, and a multi-word entry contributes each of its words.
     *
     * @return array<string, true>
     */
    public static function stopwords(IndexDefinition $index): array
    {
        $words = [];

        foreach ($index->stopwords as $entry) {
            foreach (Rules::tokenize((string)$entry) as $token) {
                $words[$token] = true;
            }
        }

        return $words;
    }

    /**
     * The index's synonym groups, as token → every other token in its group.
     *
     * Groups are symmetric: `sofa, couch` means each finds the other. A word appearing in two
     * groups gets the union, which is usually what someone writing two overlapping groups meant.
     *
     * @return array<string, list<string>>
     */
    public static function synonyms(IndexDefinition $index): array
    {
        $map = [];

        foreach ($index->synonyms as $group) {
            $tokens = [];

            foreach (explode(',', (string)$group) as $word) {
                foreach (Rules::tokenize($word) as $token) {
                    $tokens[$token] = true;
                }
            }

            $tokens = array_keys($tokens);

            if (count($tokens) < 2) {
                continue;
            }

            foreach ($tokens as $token) {
                foreach ($tokens as $other) {
                    if ($other !== $token) {
                        $map[$token][$other] = true;
                    }
                }
            }
        }

        return array_map(fn(array $others) => array_keys($others), $map);
    }

    /**
     * Splits text into normalised tokens.
     *
     * Delegates to `search\Tokenizer`, which is where the rules actually live — the query
     * engines call it directly, and a second copy of the rules here would be a second thing to
     * keep in step with the JavaScript twin.
     *
     * @return list<string>
     */
    public function tokenize(string $text): array
    {
        return Rules::tokenize($text);
    }

    public function normalize(string $text): string
    {
        return Rules::normalize($text);
    }

    /**
     * Tokenises a search query. Same rules as document text, minus the weighting.
     *
     * @return list<string>
     */
    public function tokenizeQuery(string $query): array
    {
        return Rules::tokenize($query);
    }
}
