<?php

namespace justinholtweb\caffeine\search;

/**
 * What the visitor has asked for.
 *
 * Deliberately plain data, and deliberately the same shape as the JSON the runtime posts and
 * the query string it encodes, so state survives a round trip through a URL, a fragment
 * request and back into the engine without transformation losing anything.
 */
class QueryState
{
    public function __construct(
        public string $query = '',
        /** @var array<string, list<string|bool|float>> facet key → refined values */
        public array $refinements = [],
        /** @var array<string, array{min?: float, max?: float}> facet key → range */
        public array $ranges = [],
        /**
         * Geo facet key → the point and radius to filter by.
         *
         * A radius of zero filters nothing and only enables the distance sorting, which is what
         * "order by nearest, show everything" needs.
         *
         * @var array<string, array{lat: float, lng: float, radius: float}>
         */
        public array $around = [],
        public string $sortBy = 'relevance',
        public int $page = 0,
        public ?int $hitsPerPage = null,
        /** @var list<string>|null Facets to count. Null means all of them. */
        public ?array $facets = null,
    ) {
    }

    public function isRefined(string $facet, string|bool|float $value): bool
    {
        foreach ($this->refinements[$facet] ?? [] as $refined) {
            // Loose comparison would make the string "0" match the boolean false, which shows
            // up as the wrong checkbox appearing ticked on a boolean facet.
            if ($refined === $value) {
                return true;
            }
        }

        return false;
    }

    public function hasRefinements(): bool
    {
        foreach ($this->refinements as $values) {
            if ($values !== []) {
                return true;
            }
        }

        if ($this->ranges !== []) {
            return true;
        }

        foreach ($this->around as $spec) {
            if (($spec['radius'] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Facet keys carrying an active refinement or range.
     *
     * @return list<string>
     */
    public function refinedFacets(): array
    {
        $keys = [];

        foreach ($this->refinements as $key => $values) {
            if ($values !== []) {
                $keys[$key] = true;
            }
        }

        foreach ($this->ranges as $key => $range) {
            if (isset($range['min']) || isset($range['max'])) {
                $keys[$key] = true;
            }
        }

        foreach ($this->around as $key => $spec) {
            if (($spec['radius'] ?? 0) > 0) {
                $keys[$key] = true;
            }
        }

        return array_keys($keys);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $refinements = [];

        foreach ((array)($data['refinements'] ?? []) as $key => $values) {
            $refinements[(string)$key] = array_values((array)$values);
        }

        $ranges = [];

        foreach ((array)($data['ranges'] ?? []) as $key => $range) {
            $range = (array)$range;
            $parsed = [];

            if (isset($range['min']) && $range['min'] !== '') {
                $parsed['min'] = (float)$range['min'];
            }

            if (isset($range['max']) && $range['max'] !== '') {
                $parsed['max'] = (float)$range['max'];
            }

            if ($parsed !== []) {
                $ranges[(string)$key] = $parsed;
            }
        }

        $around = [];

        foreach ((array)($data['around'] ?? []) as $key => $spec) {
            $spec = (array)$spec;

            if (!isset($spec['lat'], $spec['lng']) || !is_numeric($spec['lat']) || !is_numeric($spec['lng'])) {
                continue;
            }

            $around[(string)$key] = [
                'lat' => (float)$spec['lat'],
                'lng' => (float)$spec['lng'],
                'radius' => max(0.0, (float)($spec['radius'] ?? 0)),
            ];
        }

        return new self(
            query: (string)($data['query'] ?? ''),
            refinements: $refinements,
            ranges: $ranges,
            around: $around,
            sortBy: (string)($data['sortBy'] ?? 'relevance'),
            page: max(0, (int)($data['page'] ?? 0)),
            hitsPerPage: isset($data['hitsPerPage']) ? (int)$data['hitsPerPage'] : null,
            facets: isset($data['facets']) ? array_values((array)$data['facets']) : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'query' => $this->query,
            'refinements' => array_filter($this->refinements, fn(array $v) => $v !== []),
            'ranges' => $this->ranges,
            'around' => $this->around,
            'sortBy' => $this->sortBy,
            'page' => $this->page,
            'hitsPerPage' => $this->hitsPerPage,
            'facets' => $this->facets,
        ], fn($v) => $v !== null && $v !== '' && $v !== []);
    }
}
