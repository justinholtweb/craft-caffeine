<?php

namespace justinholtweb\caffeine\search;

use justinholtweb\caffeine\models\AttributeDefinition;

/**
 * The query string a search state travels in.
 *
 * This is the fourth piece of logic that genuinely exists in both languages, and the one with
 * the most direct consequences for a visitor. The server renders every facet control as a real
 * `<a href>` carrying the state it would produce; the runtime parses that same href when it
 * intercepts the click, and pushes it back with `history.pushState`. If the two disagree about
 * a single character, the link works without JavaScript and breaks with it — or worse, the
 * back button lands on a state the page cannot reproduce.
 *
 * Twin of runtime/src/url.js; pinned by tests/Conformance/urlstate.json.
 *
 * The shape is chosen to be legible in a browser bar, because these URLs get shared and
 * indexed:
 *
 *     ?q=cordless&brand=Acme,Globex&price=10..50&sort=price_asc&page=2
 *
 * Values are comma-separated rather than repeated `brand[]=` parameters. It reads better, and
 * PHP will not parse repeated bare parameters into an array anyway. A literal comma or
 * backslash inside a value is backslash-escaped, so "Smith, John" survives as `Smith\,John`.
 */
class UrlState
{
    public const PARAM_QUERY = 'q';
    public const PARAM_SORT = 'sort';
    public const PARAM_PAGE = 'page';
    public const PARAM_PER_PAGE = 'perPage';

    /** Separates the two halves of a range: `price=10..50`, `price=10..`, `price=..50`. */
    public const RANGE_SEPARATOR = '..';

    /**
     * Suffixes a plain HTML form can post a range through.
     *
     * `price=10..50` is what every link and every canonical URL carries, but a form with two
     * number inputs cannot produce it — a form posts one value per field. Rather than give up
     * the no-JavaScript story for range widgets, `price_min` / `price_max` are accepted on the
     * way *in* only. Nothing ever encodes them, so the moment the visitor clicks anything the
     * URL is back to its canonical form.
     */
    public const SUFFIX_MIN = '_min';
    public const SUFFIX_MAX = '_max';

    /**
     * Encodes a state as query-string parameters.
     *
     * Anything at its default is omitted, so the unrefined state is a bare URL with no query
     * string at all — which is what `rel="canonical"` points at and what a full-page cache
     * stores.
     *
     * @param array{prefix?: string, defaultSort?: string} $options
     * @return array<string, string>
     */
    public static function encode(QueryState $state, array $options = []): array
    {
        $prefix = (string)($options['prefix'] ?? '');
        $defaultSort = (string)($options['defaultSort'] ?? 'relevance');
        $params = [];

        if ($state->query !== '') {
            $params[$prefix . self::PARAM_QUERY] = $state->query;
        }

        // Refinements and ranges share a facet's parameter. They cannot collide: a range is
        // only ever read back out of a numeric or date facet, and no number contains "..".
        $tokens = [];

        foreach ($state->refinements as $key => $values) {
            foreach ($values as $value) {
                $tokens[$key][] = self::escape(FacetValue::toKey($value));
            }
        }

        foreach ($state->around as $key => $spec) {
            // Three numbers under the facet's own parameter: `near=35.2271,-80.8431,8000`. It
            // cannot collide with refinement values because a geo facet has none — it is filtered
            // by distance, never by equality.
            $tokens[$key][] = implode(',', [
                FacetValue::toKey((float)$spec['lat']),
                FacetValue::toKey((float)$spec['lng']),
                FacetValue::toKey((float)($spec['radius'] ?? 0)),
            ]);
        }

        foreach ($state->ranges as $key => $range) {
            $min = $range['min'] ?? null;
            $max = $range['max'] ?? null;

            if ($min === null && $max === null) {
                continue;
            }

            $tokens[$key][] = ($min === null ? '' : FacetValue::toKey((float)$min))
                . self::RANGE_SEPARATOR
                . ($max === null ? '' : FacetValue::toKey((float)$max));
        }

        foreach ($tokens as $key => $values) {
            if ($values !== []) {
                $params[$prefix . $key] = implode(',', $values);
            }
        }

        if ($state->sortBy !== '' && $state->sortBy !== $defaultSort) {
            $params[$prefix . self::PARAM_SORT] = $state->sortBy;
        }

        if ($state->page > 0) {
            // One-based in the URL. `page=2` is the second page, which is what a visitor reading
            // their own address bar expects; zero-based is an implementation detail of the engine.
            $params[$prefix . self::PARAM_PAGE] = (string)($state->page + 1);
        }

        if ($state->hitsPerPage !== null) {
            $params[$prefix . self::PARAM_PER_PAGE] = (string)$state->hitsPerPage;
        }

        return $params;
    }

    /**
     * Parses query-string parameters back into a state.
     *
     * @param array<string, mixed> $params Usually `$request->getQueryParams()`.
     * @param array<string, array{type: string, values: list<mixed>}> $facets The artifact's facets.
     * @param array{prefix?: string, defaultSort?: string} $options
     */
    public static function parse(array $params, array $facets, array $options = []): QueryState
    {
        $prefix = (string)($options['prefix'] ?? '');
        $defaultSort = (string)($options['defaultSort'] ?? 'relevance');

        $refinements = [];
        $ranges = [];
        $around = [];

        foreach ($facets as $key => $facet) {
            $raw = $params[$prefix . $key] ?? null;

            if (($facet['type'] ?? null) === AttributeDefinition::FACET_GEO) {
                if (is_string($raw) && $raw !== '') {
                    $parts = array_map('trim', explode(',', $raw));

                    if (count($parts) >= 2 && is_numeric($parts[0]) && is_numeric($parts[1])) {
                        $around[$key] = [
                            'lat' => (float)$parts[0],
                            'lng' => (float)$parts[1],
                            'radius' => isset($parts[2]) && is_numeric($parts[2]) ? max(0.0, (float)$parts[2]) : 0.0,
                        ];
                    }
                }

                continue;
            }

            $rangeable = in_array($facet['type'] ?? 'string', [
                AttributeDefinition::FACET_NUMERIC,
                AttributeDefinition::FACET_DATE,
            ], true);

            if ($rangeable && (!is_string($raw) || $raw === '')) {
                $bounds = self::formBounds($params, $prefix . $key);

                if ($bounds !== []) {
                    $ranges[$key] = $bounds;
                }
            }

            if (!is_string($raw) || $raw === '') {
                continue;
            }

            foreach (self::split($raw) as $token) {
                if ($rangeable && str_contains($token, self::RANGE_SEPARATOR)) {
                    [$min, $max] = explode(self::RANGE_SEPARATOR, $token, 2);
                    $range = [];

                    if ($min !== '') {
                        $range['min'] = (float)$min;
                    }

                    if ($max !== '') {
                        $range['max'] = (float)$max;
                    }

                    if ($range !== []) {
                        $ranges[$key] = $range;
                    }

                    continue;
                }

                // Resolved against the stored values rather than coerced by type: the engine
                // compares strictly, and a numeric facet may hold int(10) where the URL says 10.
                $refinements[$key][] = FacetValue::fromKey($token, $facet['values'] ?? []);
            }
        }

        $page = (int)($params[$prefix . self::PARAM_PAGE] ?? 1);
        $perPage = $params[$prefix . self::PARAM_PER_PAGE] ?? null;

        return new QueryState(
            query: trim((string)($params[$prefix . self::PARAM_QUERY] ?? '')),
            refinements: $refinements,
            ranges: $ranges,
            around: $around,
            sortBy: (string)($params[$prefix . self::PARAM_SORT] ?? $defaultSort),
            page: max(0, $page - 1),
            hitsPerPage: is_numeric($perPage) ? max(1, (int)$perPage) : null,
        );
    }

    /**
     * Reads a range posted by a plain form as `<facet>_min` / `<facet>_max`.
     *
     * @param array<string, mixed> $params
     * @return array{min?: float, max?: float}
     */
    private static function formBounds(array $params, string $name): array
    {
        $bounds = [];

        foreach ([self::SUFFIX_MIN => 'min', self::SUFFIX_MAX => 'max'] as $suffix => $bound) {
            $value = $params[$name . $suffix] ?? null;

            if (is_string($value) && $value !== '' && is_numeric($value)) {
                $bounds[$bound] = (float)$value;
            }
        }

        return $bounds;
    }

    /**
     * Builds a URL from a path and a state.
     */
    public static function url(string $path, QueryState $state, array $options = []): string
    {
        $params = self::encode($state, $options);

        if ($params === []) {
            return $path;
        }

        // RFC 3986 encoding, so a space becomes %20 rather than +. Both are legal and both
        // decode the same, but only one matches what the runtime produces — and the runtime
        // compares hrefs.
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        // Commas restored. They are sub-delimiters, legal unencoded in a query string, and the
        // whole point of choosing them as the separator was a URL a person can read. Escaped
        // commas inside a value keep their backslash and still survive the round trip, because
        // `split()` honours the escape rather than the encoding.
        return $path . '?' . str_replace('%2C', ',', $query);
    }

    /**
     * Splits a comma-separated value list, honouring backslash escapes.
     *
     * @return list<string>
     */
    public static function split(string $value): array
    {
        $tokens = [];
        $current = '';
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];

            if ($char === '\\' && $i + 1 < $length) {
                $current .= $value[++$i];
                continue;
            }

            if ($char === ',') {
                $tokens[] = $current;
                $current = '';
                continue;
            }

            $current .= $char;
        }

        $tokens[] = $current;

        return array_values(array_filter($tokens, fn(string $token) => $token !== ''));
    }

    public static function escape(string $value): string
    {
        return str_replace(['\\', ','], ['\\\\', '\\,'], $value);
    }
}
