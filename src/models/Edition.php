<?php

namespace justinholtweb\caffeine\models;

/**
 * What each edition allows.
 *
 * Pure and static, taking `$isPro` rather than reaching for the plugin, so the boundary can be
 * tested without an application — and so this file can be read as the answer to "what exactly
 * does Pro buy" without chasing calls through three services.
 *
 * Two things enforce it, and they do different jobs:
 *
 * - `Indexes::save()` **refuses** a configuration Lite cannot run, so an operator is told rather
 *   than silently given something other than what they configured.
 * - `Indexes::forEdition()` **downgrades** a definition on the way to the build and the query, so
 *   a site whose licence lapsed keeps working with the Lite feature set instead of breaking. The
 *   stored definition is left untouched, and the control panel keeps showing it, so upgrading
 *   restores exactly what was there before.
 */
class Edition
{
    /** Facet types Lite can build. The two that need no extra query machinery. */
    public const LITE_FACET_TYPES = [
        AttributeDefinition::FACET_STRING,
        AttributeDefinition::FACET_BOOLEAN,
    ];

    /** Lite serves fragments. The client and JSON transports are Pro. */
    public const LITE_TRANSPORTS = [
        IndexDefinition::TRANSPORT_HTMX,
    ];

    /** Named sortings besides relevance, which every index always has. */
    public const LITE_MAX_SORTINGS = 1;

    public const LITE_MAX_INDEXES = 1;

    /**
     * @return list<string>
     */
    public static function facetTypes(bool $isPro): array
    {
        return $isPro ? AttributeDefinition::FACET_TYPES : self::LITE_FACET_TYPES;
    }

    /**
     * @return list<string>
     */
    public static function transports(bool $isPro): array
    {
        return $isPro ? IndexDefinition::TRANSPORTS : self::LITE_TRANSPORTS;
    }

    /** Null means unlimited. */
    public static function maxSortings(bool $isPro): ?int
    {
        return $isPro ? null : self::LITE_MAX_SORTINGS;
    }

    /** Null means unlimited. */
    public static function maxIndexes(bool $isPro): ?int
    {
        return $isPro ? null : self::LITE_MAX_INDEXES;
    }

    /** Stopwords and synonyms. */
    public static function allowsWordLists(bool $isPro): bool
    {
        return $isPro;
    }

    /** The record preview and the query playground. */
    public static function allowsTools(bool $isPro): bool
    {
        return $isPro;
    }

    /**
     * Everything wrong with a definition on this edition, as human-readable sentences.
     *
     * Returned rather than thrown so the control panel can show all of them at once — an
     * operator fixing one problem per save is a bad afternoon.
     *
     * @return list<string>
     */
    public static function problems(IndexDefinition $index, bool $isPro): array
    {
        if ($isPro) {
            return [];
        }

        $problems = [];
        $facetTypes = self::facetTypes(false);

        foreach ($index->facets() as $facet) {
            if (!in_array($facet->facetType, $facetTypes, true)) {
                $problems[] = sprintf(
                    'The “%s” facet is a %s facet, which needs Pro. Lite supports %s facets.',
                    $facet->label ?: $facet->key,
                    $facet->facetType,
                    implode(' and ', $facetTypes),
                );
            }
        }

        if (!in_array($index->transport, self::transports(false), true)) {
            $problems[] = sprintf(
                'The “%s” transport needs Pro. Lite uses the fragment transport.',
                $index->transport,
            );
        }

        $named = count(array_filter($index->sortings, fn(SortingDefinition $s) => !$s->isRelevance()));

        if ($named > self::LITE_MAX_SORTINGS) {
            $problems[] = sprintf(
                'Lite allows relevance plus %d named sorting; this index has %d.',
                self::LITE_MAX_SORTINGS,
                $named,
            );
        }

        if (($index->stopwords !== [] || $index->synonyms !== []) && !self::allowsWordLists(false)) {
            $problems[] = 'Stopwords and synonyms need Pro.';
        }

        return $problems;
    }
}
