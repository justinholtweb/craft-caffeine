<?php

namespace justinholtweb\caffeine\models;

/**
 * One element, mapped into the shape the artifact wants.
 */
class MappedRecord
{
    public function __construct(
        public int $elementId,
        public int $siteId,
        public string $objectId,
        /**
         * Facet values, keyed by attribute key. Each is a list, because one element can sit in
         * several buckets of the same facet — a product with three colours is three values on
         * `colour`, not a comma-joined string that nothing can refine on.
         *
         * @var array<string, list<string|float|bool>>
         */
        public array $facets = [],
        /**
         * Sortable values, keyed by attribute key. Scalar, and null when the element has no
         * value — the sort spec says where nulls go.
         *
         * @var array<string, string|float|null>
         */
        public array $sortable = [],
        /**
         * Card data, keyed by attribute key. JSON-safe and rendered without touching the
         * database.
         *
         * @var array<string, mixed>
         */
        public array $payload = [],
        /**
         * Searchable text, keyed by attribute key, before tokenisation.
         *
         * @var array<string, string>
         */
        public array $searchable = [],
        /**
         * Coordinates, keyed by attribute key: `[lat, lng]`, or absent when the element has
         * none.
         *
         * Kept apart from `facets` because a coordinate is not a bucket. Interning it would
         * produce one facet value per record and a postings list with one id in each, which is
         * all cost and no use — a geo facet is filtered by distance, never by equality.
         *
         * @var array<string, array{0: float, 1: float}>
         */
        public array $geo = [],
        /**
         * Every element read to build this record, including the element itself. This is what
         * makes denormalised values safe: change any of them and the record is marked dirty.
         *
         * @var list<int>
         */
        public array $dependencies = [],
    ) {
    }

    /**
     * The stored form. Deliberately not the artifact form — this is the intermediate the build
     * reads to assemble postings lists, and keeping it verbose makes an index inspectable in
     * the CP when something looks wrong.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'facets' => $this->facets,
            'sortable' => $this->sortable,
            'payload' => $this->payload,
            'searchable' => $this->searchable,
            'geo' => $this->geo,
        ];
    }
}
