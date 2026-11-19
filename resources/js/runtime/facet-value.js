/**
 * The canonical string projection of a facet value, per docs/ARTIFACT.md §10.
 *
 * Twin of src/search/FacetValue.php. Three places need a facet value as a string — the Algolia
 * `facets` map, the query string a facet link carries, and the `data-` attributes this runtime
 * reads back — and all three have to produce the same characters on both sides, or a link the
 * server rendered would not match the value the browser looks up.
 *
 * Numbers are formatted rather than cast: PHP's `(string)` follows its `precision` ini setting
 * and uses exponent notation for large values, which `String()` does not. Fixed-point formatting
 * is the one rule both languages implement identically.
 */

/** Decimal places kept for non-integral values. Beyond this the two languages can disagree. */
export const PRECISION = 6

export function toKey(value) {
  if (typeof value === 'boolean') return value ? 'true' : 'false'

  if (typeof value === 'number') {
    if (!Number.isFinite(value)) return String(value)
    if (Number.isInteger(value)) return value.toFixed(0)

    return trimZeros(value.toFixed(PRECISION))
  }

  return String(value)
}

/**
 * Resolves a projected key back to the value the artifact actually stores.
 *
 * Matched against the stored values rather than coerced by facet type: the engine compares
 * strictly, and a numeric facet may hold 10 where the URL says "10".
 *
 * Returns the key itself when nothing matches — a refinement naming a value that has since
 * disappeared matches nothing, which is not an error.
 */
export function fromKey(key, values) {
  for (const value of values || []) {
    if (toKey(value) === key) return value
  }

  return key
}

function trimZeros(formatted) {
  return formatted.replace(/0+$/, '').replace(/\.$/, '')
}
