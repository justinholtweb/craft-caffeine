/**
 * Great-circle distance, per docs/QUERY_SPEC.md §10. Twin of src/search/Geo.php.
 *
 * Distance is rounded to whole metres and compared as an integer, deliberately. `Math.sin`,
 * `Math.cos` and `Math.sqrt` are not guaranteed to agree to the last bit with PHP's, and a record
 * sitting exactly on the radius could otherwise be inside it on the server and outside it in the
 * browser — the page rearranging itself under the visitor over one ulp.
 */

/** IUGG mean Earth radius, in metres. */
export const EARTH_RADIUS = 6371008.8

/** @returns {number} Metres, rounded. */
export function distance(lat1, lng1, lat2, lng2) {
  const phi1 = toRadians(lat1)
  const phi2 = toRadians(lat2)
  const deltaPhi = toRadians(lat2 - lat1)
  const deltaLambda = toRadians(lng2 - lng1)

  const a =
    Math.sin(deltaPhi / 2) ** 2 +
    Math.cos(phi1) * Math.cos(phi2) * Math.sin(deltaLambda / 2) ** 2

  // atan2 rather than asin: asin loses precision for antipodal points.
  return Math.round(EARTH_RADIUS * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(Math.max(0, 1 - a))))
}

/**
 * Whether a coordinate pair looks usable.
 *
 * A missing address decodes to null, but a half-filled one decodes to [0, 0] — a real point in
 * the Gulf of Guinea that would quietly match a radius search from anywhere in west Africa.
 */
export function isValid(point) {
  if (!Array.isArray(point) || point.length !== 2) return false

  const [lat, lng] = point

  return (
    Number.isFinite(lat) &&
    Number.isFinite(lng) &&
    Math.abs(lat) <= 90 &&
    Math.abs(lng) <= 180 &&
    (lat !== 0 || lng !== 0)
  )
}

function toRadians(degrees) {
  return (degrees * Math.PI) / 180
}
