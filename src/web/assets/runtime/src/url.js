/**
 * The query string a search state travels in. Twin of src/search/UrlState.php.
 *
 * The server renders every facet control as a real `<a href>` carrying the state it would
 * produce; this parses that same href when the click is intercepted, and pushes it back with
 * `history.pushState`. A single character of disagreement between the two and the link works
 * without JavaScript but breaks with it — or the back button lands on a state the page cannot
 * reproduce. tests/Conformance/urlstate.json pins them together.
 */

import { toKey, fromKey } from './facet-value.js'

export const PARAM_QUERY = 'q'
export const PARAM_SORT = 'sort'
export const PARAM_PAGE = 'page'
export const PARAM_PER_PAGE = 'perPage'
export const RANGE_SEPARATOR = '..'

/**
 * Suffixes a plain HTML form can post a range through. Accepted on the way in only — nothing
 * ever encodes them, so the URL returns to its canonical `price=10..50` on the next click.
 */
export const SUFFIX_MIN = '_min'
export const SUFFIX_MAX = '_max'

const RANGEABLE = new Set(['numeric', 'date'])

/**
 * @returns {Record<string, string>} Query-string parameters, unencoded.
 */
export function encode(state, options = {}) {
  const prefix = options.prefix || ''
  const defaultSort = options.defaultSort || 'relevance'
  const params = {}

  if (state.query) params[prefix + PARAM_QUERY] = state.query

  // Refinements and ranges share a facet's parameter. They cannot collide: a range is only ever
  // read back out of a numeric or date facet, and no number contains "..".
  const tokens = {}

  for (const [key, values] of Object.entries(state.refinements || {})) {
    for (const value of values) {
      ;(tokens[key] = tokens[key] || []).push(escape(toKey(value)))
    }
  }

  for (const [key, spec] of Object.entries(state.around || {})) {
    // Three numbers under the facet's own parameter: `near=35.2271,-80.8431,8000`. No collision
    // with refinement values, because a geo facet has none.
    ;(tokens[key] = tokens[key] || []).push(
      [toKey(Number(spec.lat)), toKey(Number(spec.lng)), toKey(Number(spec.radius || 0))].join(','),
    )
  }

  for (const [key, range] of Object.entries(state.ranges || {})) {
    const min = range.min ?? null
    const max = range.max ?? null

    if (min === null && max === null) continue

    ;(tokens[key] = tokens[key] || []).push(
      (min === null ? '' : toKey(Number(min))) + RANGE_SEPARATOR + (max === null ? '' : toKey(Number(max))),
    )
  }

  for (const [key, values] of Object.entries(tokens)) {
    if (values.length > 0) params[prefix + key] = values.join(',')
  }

  if (state.sortBy && state.sortBy !== defaultSort) params[prefix + PARAM_SORT] = state.sortBy

  // One-based in the URL: `page=2` is the second page, which is what a visitor reading their own
  // address bar expects. Zero-based is an implementation detail of the engine.
  if (state.page > 0) params[prefix + PARAM_PAGE] = String(state.page + 1)

  if (state.hitsPerPage != null) params[prefix + PARAM_PER_PAGE] = String(state.hitsPerPage)

  return params
}

/**
 * @param {Record<string, string>|URLSearchParams|string} input
 * @param {Record<string, {type: string, values: unknown[]}>} facets
 */
export function parse(input, facets, options = {}) {
  const prefix = options.prefix || ''
  const defaultSort = options.defaultSort || 'relevance'
  const params = toParams(input)

  const refinements = {}
  const ranges = {}
  const around = {}

  for (const [key, facet] of Object.entries(facets || {})) {
    const raw = params[prefix + key]

    if ((facet.type || '') === 'geo') {
      if (typeof raw === 'string' && raw !== '') {
        const parts = raw.split(',').map((part) => part.trim())

        if (parts.length >= 2 && Number.isFinite(Number(parts[0])) && Number.isFinite(Number(parts[1]))) {
          around[key] = {
            lat: Number(parts[0]),
            lng: Number(parts[1]),
            radius: parts[2] !== undefined && Number.isFinite(Number(parts[2])) ? Math.max(0, Number(parts[2])) : 0,
          }
        }
      }

      continue
    }

    const rangeable = RANGEABLE.has(facet.type || 'string')

    if (rangeable && (typeof raw !== 'string' || raw === '')) {
      const bounds = formBounds(params, prefix + key)
      if (Object.keys(bounds).length > 0) ranges[key] = bounds
    }

    if (typeof raw !== 'string' || raw === '') continue

    for (const token of split(raw)) {
      if (rangeable && token.includes(RANGE_SEPARATOR)) {
        const at = token.indexOf(RANGE_SEPARATOR)
        const min = token.slice(0, at)
        const max = token.slice(at + RANGE_SEPARATOR.length)
        const range = {}

        if (min !== '') range.min = Number(min)
        if (max !== '') range.max = Number(max)
        if (Object.keys(range).length > 0) ranges[key] = range

        continue
      }

      // Resolved against the stored values rather than coerced by type: the engine compares
      // strictly, and a numeric facet may hold 10 where the URL says "10".
      ;(refinements[key] = refinements[key] || []).push(fromKey(token, facet.values || []))
    }
  }

  const page = parseInt(params[prefix + PARAM_PAGE] ?? '1', 10)
  const perPage = params[prefix + PARAM_PER_PAGE]

  return {
    query: (params[prefix + PARAM_QUERY] ?? '').trim(),
    refinements,
    ranges,
    around,
    sortBy: params[prefix + PARAM_SORT] ?? defaultSort,
    page: Math.max(0, (Number.isFinite(page) ? page : 1) - 1),
    hitsPerPage: perPage != null && perPage !== '' && Number.isFinite(Number(perPage))
      ? Math.max(1, parseInt(perPage, 10))
      : null,
    facets: null,
  }
}

/** Reads a range posted by a plain form as `<facet>_min` / `<facet>_max`. */
function formBounds(params, name) {
  const bounds = {}

  for (const [suffix, bound] of [[SUFFIX_MIN, 'min'], [SUFFIX_MAX, 'max']]) {
    const value = params[name + suffix]

    if (typeof value === 'string' && value !== '' && Number.isFinite(Number(value))) {
      bounds[bound] = Number(value)
    }
  }

  return bounds
}

export function url(path, state, options = {}) {
  const params = encode(state, options)
  const keys = Object.keys(params)

  if (keys.length === 0) return path

  const query = keys.map((key) => `${rawurlencode(key)}=${rawurlencode(params[key])}`).join('&')

  // Commas restored — sub-delimiters, legal unencoded, and the whole point of choosing them as
  // the separator was a URL a person can read. Escaped commas inside a value keep their
  // backslash and still round-trip, because `split()` honours the escape, not the encoding.
  return `${path}?${query.replaceAll('%2C', ',')}`
}

/** Splits a comma-separated value list, honouring backslash escapes. */
export function split(value) {
  const tokens = []
  let current = ''

  for (let i = 0; i < value.length; i++) {
    const char = value[i]

    if (char === '\\' && i + 1 < value.length) {
      current += value[++i]
      continue
    }

    if (char === ',') {
      tokens.push(current)
      current = ''
      continue
    }

    current += char
  }

  tokens.push(current)

  return tokens.filter((token) => token !== '')
}

export function escape(value) {
  return String(value).replaceAll('\\', '\\\\').replaceAll(',', '\\,')
}

/**
 * PHP's `rawurlencode`, which is not quite `encodeURIComponent`: the two disagree on
 * `!'()*`, which `encodeURIComponent` leaves alone. Hrefs are compared, so they have to match.
 */
function rawurlencode(value) {
  return encodeURIComponent(value).replace(
    /[!'()*]/g,
    (char) => '%' + char.charCodeAt(0).toString(16).toUpperCase(),
  )
}

function toParams(input) {
  if (typeof input === 'string') {
    return toParams(new URLSearchParams(input.startsWith('?') ? input.slice(1) : input))
  }

  if (input instanceof URLSearchParams) {
    const out = {}
    for (const [key, value] of input.entries()) out[key] = value
    return out
  }

  return input || {}
}
