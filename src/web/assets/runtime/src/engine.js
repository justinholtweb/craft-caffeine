/**
 * The JavaScript query engine.
 *
 * Implements docs/QUERY_SPEC.md. Its twin is src/search/Engine.php, and tests/Conformance/*.json
 * is run against both. When changing anything here, change the spec first and the fixtures
 * second — behaviour that exists in only one engine shows up as the page rearranging itself
 * under the visitor the moment they touch a control.
 *
 * Structural note: the PHP engine uses `id => true` maps for record sets; this one uses `Set`.
 * Same semantics, each idiomatic for its language.
 */

import { compare } from './comparator.js'
import { toKey as facetKeyString } from './facet-value.js'
import * as geo from './geo.js'
import { tokenize } from './tokenizer.js'

/** The one sorting that cannot be precomputed: the point it measures from is the visitor's. */
export const SORT_DISTANCE = 'distance'

export class Engine {
  constructor(artifact) {
    this.artifact = artifact
    this.recordCount = artifact.objectIds.length
  }

  /**
   * @returns {object} The Algolia-shaped response of QUERY_SPEC §9.
   */
  search(state, defaultHitsPerPage = 24) {
    const startedAt = now()
    const hitsPerPage = Math.max(1, state.hitsPerPage || defaultHitsPerPage)

    // §3.1
    const { candidates, scores } = this.match(state.query || '')

    // §3.2 — kept per facet, because counting rebuilds the intersection leaving one out.
    const filters = this.buildFilters(state)
    const result = this.intersectAll(candidates, filters, null)

    // §3.3
    const facetKeys = state.facets || Object.keys(this.artifact.facets)
    const { facets, caffeineFacets, stats } = this.countFacets(state, facetKeys, candidates, filters, result)

    // §3.4
    const ordered = this.sort(
      result,
      scores,
      state.sortBy || 'relevance',
      (state.query || '') !== '',
      (state.sortBy || '') === SORT_DISTANCE ? this.distances(state) : null,
    )

    // §3.5
    const nbHits = ordered.length
    const nbPages = Math.ceil(nbHits / hitsPerPage)
    const page = nbPages > 0 ? Math.min(state.page || 0, nbPages - 1) : 0
    const slice = ordered.slice(page * hitsPerPage, (page + 1) * hitsPerPage)

    return {
      hits: slice.map((id) => this.hit(id)),
      nbHits,
      page,
      nbPages,
      hitsPerPage,
      query: state.query || '',
      params: '',
      processingTimeMS: Math.round(now() - startedAt),
      exhaustiveNbHits: true,
      exhaustiveFacetsCount: true,
      facets,
      facets_stats: stats,
      caffeineFacets,
    }
  }

  /** §3.1 — the records matching the text query, and their scores. */
  match(query) {
    const tokens = this.queryTokens(query)

    if (tokens.length === 0) {
      const all = new Set()
      for (let i = 0; i < this.recordCount; i++) all.add(i)
      return { candidates: all, scores: new Map() }
    }

    let matched = null
    const scores = new Map()
    const last = tokens.length - 1

    for (let i = 0; i < tokens.length; i++) {
      // Only the final token is prefix-matched: the visitor is still typing it.
      const postings = i === last ? this.prefixPostings(tokens[i]) : this.exactPostings(tokens[i])

      if (postings.size === 0) {
        // Conjunctive matching — one token with no matches means no results at all.
        return { candidates: new Set(), scores: new Map() }
      }

      const current = new Set()

      for (const [id, weight] of postings) {
        current.add(id)
        scores.set(id, (scores.get(id) || 0) + weight)
      }

      matched = matched === null ? current : intersect(matched, current)

      if (matched.size === 0) return { candidates: new Set(), scores: new Map() }
    }

    // Scores accumulated for records that failed a later token have to go, or a record that
    // matched two of three tokens would carry a score it never earned.
    for (const id of scores.keys()) {
      if (!matched.has(id)) scores.delete(id)
    }

    return { candidates: matched, scores }
  }

  /**
   * §7 — the query's tokens, with the index's stopwords removed.
   *
   * Dropped here as well as at index time, and that symmetry is the point. Matching is
   * conjunctive, so a token with no postings empties the result: if "the" were removed from
   * documents but left in the query, searching "the saw" would find nothing. A query that is
   * entirely stopwords is treated as no query rather than as no matches.
   */
  queryTokens(query) {
    const tokens = tokenize(query)
    const stopwords = this.artifact.stopwords || []

    if (stopwords.length === 0) return tokens

    const set = new Set(stopwords)

    return tokens.filter((token) => !set.has(token))
  }

  /** @returns {Map<number, number>} */
  exactPostings(token) {
    const index = this.tokenIndex(token)
    const weights = new Map()

    if (index === null) return weights

    for (const [id, weight] of this.artifact.tokenPostings[index] || []) {
      weights.set(id, weight)
    }

    return weights
  }

  /**
   * Every record carrying a token starting with this prefix, taking the highest weight where
   * several match (QUERY_SPEC §3.1).
   *
   * @returns {Map<number, number>}
   */
  prefixPostings(prefix) {
    const weights = new Map()
    const tokens = this.artifact.tokens

    // Binary search for the first token at or after the prefix, then walk while it holds. This
    // is the reason the artifact ships its token list sorted, and the reason the browser needs
    // no search library.
    let low = 0
    let high = tokens.length

    while (low < high) {
      const mid = (low + high) >> 1
      if (compare(tokens[mid], prefix) < 0) low = mid + 1
      else high = mid
    }

    for (let i = low; i < tokens.length; i++) {
      if (!tokens[i].startsWith(prefix)) break

      for (const [id, weight] of this.artifact.tokenPostings[i] || []) {
        const existing = weights.get(id)
        if (existing === undefined || weight > existing) weights.set(id, weight)
      }
    }

    return weights
  }

  tokenIndex(token) {
    const tokens = this.artifact.tokens
    let low = 0
    let high = tokens.length - 1

    while (low <= high) {
      const mid = (low + high) >> 1
      const cmp = compare(tokens[mid], token)

      if (cmp === 0) return mid
      if (cmp < 0) low = mid + 1
      else high = mid - 1
    }

    return null
  }

  /** §3.2 — one predicate set per refined facet, kept separate for counting. */
  buildFilters(state) {
    const filters = new Map()

    for (const [key, values] of Object.entries(state.refinements || {})) {
      if (!values || values.length === 0 || !this.artifact.facets[key]) continue

      const facet = this.artifact.facets[key]
      const sets = values.map((value) => {
        const index = this.valueIndex(key, value)
        return index === null ? new Set() : new Set(facet.postings[index] || [])
      })

      filters.set(key, facet.operator === 'and' ? intersectAllSets(sets) : unionSets(sets))
    }

    for (const [key, range] of Object.entries(state.ranges || {})) {
      if (!this.artifact.facets[key]) continue
      filters.set(key, this.rangeSet(key, range))
    }

    // §10 — a geo facet filters by distance rather than equality, so it has no postings to
    // intersect and the predicate is built by walking the coordinates. A radius of zero filters
    // nothing: it only turns on the distance sorting.
    for (const [key, around] of Object.entries(state.around || {})) {
      const points = (this.artifact.geo || {})[key]
      const radius = Number(around.radius || 0)

      if (!points || radius <= 0) continue

      const set = new Set()

      for (let id = 0; id < points.length; id++) {
        const point = points[id]

        if (!geo.isValid(point)) continue
        if (geo.distance(around.lat, around.lng, point[0], point[1]) <= radius) set.add(id)
      }

      filters.set(key, set)
    }

    return filters
  }

  /**
   * Records with at least one value inside an inclusive range.
   *
   * Scanning the value dictionary rather than the records: it holds only distinct values, so it
   * is invariably the smaller of the two.
   */
  rangeSet(key, range) {
    const facet = this.artifact.facets[key]
    const set = new Set()
    const hasMin = range.min !== undefined && range.min !== null && range.min !== ''
    const hasMax = range.max !== undefined && range.max !== null && range.max !== ''
    const min = hasMin ? Number(range.min) : null
    const max = hasMax ? Number(range.max) : null

    facet.values.forEach((value, index) => {
      if (typeof value !== 'number') return
      if (hasMin && value < min) return
      if (hasMax && value > max) return

      for (const id of facet.postings[index] || []) set.add(id)
    })

    return set
  }

  valueIndex(key, value) {
    const values = this.artifact.facets[key].values

    for (let i = 0; i < values.length; i++) {
      // Strict, so the string "0" cannot match the boolean false and tick the wrong checkbox.
      if (values[i] === value) return i
    }

    // A refinement can name a value that no longer exists — a bookmarked URL after the content
    // changed. Not an error: it matches nothing.
    return null
  }

  /**
   * §3.3 — facet counts, with the disjunctive/conjunctive asymmetry that makes each kind of
   * facet behave the way a visitor expects.
   */
  countFacets(state, facetKeys, candidates, filters, result) {
    const facets = {}
    const caffeineFacets = {}
    const stats = {}

    for (const key of facetKeys) {
      const facet = this.artifact.facets[key]
      if (!facet) continue

      const disjunctive = facet.operator !== 'and'

      // The whole point of §3.3: a disjunctive facet is counted with its own refinements left
      // out, so "Globex (12)" still shows what the visitor would get by adding it. A
      // conjunctive facet counts against everything, because each tick is meant to narrow.
      const base = disjunctive ? this.intersectAll(candidates, filters, key) : result

      const counts = new Map()

      // Walking the base set and reading each record's values, rather than walking every value
      // and intersecting its postings: the base set is nearly always the smaller.
      for (const id of base) {
        for (const valueIndex of facet.records[id] || []) {
          counts.set(valueIndex, (counts.get(valueIndex) || 0) + 1)
        }
      }

      const buckets = this.buildBuckets(state, key, facet, counts)
      const flat = {}

      for (const bucket of buckets) flat[facetKeyString(bucket.value)] = bucket.count

      if (Object.keys(flat).length > 0) facets[key] = flat

      caffeineFacets[key] = {
        operator: disjunctive ? 'or' : 'and',
        type: facet.type,
        buckets,
      }

      if (facet.type === 'numeric' || facet.type === 'date') {
        const stat = facetStats(facet.values, counts)
        if (stat !== null) stats[key] = stat
      }
    }

    return { facets, caffeineFacets, stats }
  }

  /** §3.3 — which values appear, and in what order. */
  buildBuckets(state, key, facet, counts) {
    const refinements = (state.refinements || {})[key] || []
    const isRefined = (value) => refinements.some((r) => r === value)

    let buckets = []

    facet.values.forEach((value, index) => {
      const count = counts.get(index) || 0
      const refined = isRefined(value)

      // A refined value stays visible at zero — otherwise the control the visitor used to
      // refine disappears and they have no way to undo it.
      if (count === 0 && !refined) return

      buckets.push({ value, count, isRefined: refined })
    })

    const sort = facet.sort || 'count'
    const order = facet.valueOrder || []

    buckets.sort((a, b) => {
      if (sort === 'alpha') return compare(a.value, b.value)

      if (sort === 'manual') {
        const ai = order.indexOf(a.value)
        const bi = order.indexOf(b.value)

        if (ai !== -1 || bi !== -1) {
          // Listed values first, in the order given; unlisted fall through to count.
          if (ai === -1) return 1
          if (bi === -1) return -1
          return ai - bi
        }
      }

      if (a.count !== b.count) return b.count - a.count
      return compare(a.value, b.value)
    })

    const limit = facet.maxValues || 20

    if (buckets.length <= limit) return buckets

    // Truncation must never drop a refined value, or the visitor loses the control they used.
    let kept = 0
    buckets = buckets.filter((bucket) => {
      if (bucket.isRefined) return true
      if (kept >= limit) return false
      kept++
      return true
    })

    return buckets
  }

  /** §3.4 — ordering. */
  /**
   * §10 — distance from each `around` point to every record with coordinates.
   *
   * Computed once per query rather than inside the comparator: a sort does O(n log n)
   * comparisons and a haversine is not cheap enough to run that many times.
   */
  distances(state) {
    const distances = new Map()

    for (const [key, around] of Object.entries(state.around || {})) {
      const points = (this.artifact.geo || {})[key]

      if (!points) continue

      for (let id = 0; id < points.length; id++) {
        const point = points[id]

        if (!geo.isValid(point)) continue

        const metres = geo.distance(around.lat, around.lng, point[0], point[1])
        const seen = distances.get(id)

        // With two geo facets in play, nearest means nearest to either.
        if (seen === undefined || metres < seen) distances.set(id, metres)
      }
    }

    return distances
  }

  sort(result, scores, sortBy, hasQuery, distances = null) {
    // §10 — nearest first. Never precomputed like the other sortings, because the point it is
    // measured from is chosen by the visitor, not by the index.
    if (sortBy === SORT_DISTANCE && distances && distances.size > 0) {
      const ids = [...result]

      ids.sort((a, b) => {
        const da = distances.has(a) ? distances.get(a) : Number.MAX_SAFE_INTEGER
        const db = distances.has(b) ? distances.get(b) : Number.MAX_SAFE_INTEGER

        return da - db || compare(this.artifact.objectIds[a], this.artifact.objectIds[b])
      })

      return ids
    }

    const sorting = this.artifact.sortings[sortBy]

    // With no text query every score is 0, so the score tie-break is a no-op and the
    // precomputed order is already exactly right — filter it and take. This is the common case
    // for a filtered listing, and it avoids sorting entirely.
    if (!hasQuery && sorting) {
      const ordered = []
      for (const id of sorting) if (result.has(id)) ordered.push(id)
      return ordered
    }

    const ids = [...result]
    const objectIds = this.artifact.objectIds
    const score = (id) => scores.get(id) || 0

    if (sortBy === 'relevance' || !sorting) {
      ids.sort((a, b) => {
        if (score(a) !== score(b)) return score(b) - score(a)
        return compare(objectIds[a], objectIds[b])
      })

      return ids
    }

    // A named sorting with a query active: the precomputed order cannot be used directly,
    // because text score sits between the sort key and the tie-break. Position in that order is
    // still the cheapest way to recover the sort key's ordering — including its direction and
    // its nulls-last handling — without re-reading values.
    const position = new Map()
    sorting.forEach((id, rank) => position.set(id, rank))

    const values = (this.artifact.sortableValues || {})[sortBy] || null
    const rank = (id) => (position.has(id) ? position.get(id) : Number.MAX_SAFE_INTEGER)

    ids.sort((a, b) => {
      if (values) {
        const av = values[a] === undefined ? null : values[a]
        const bv = values[b] === undefined ? null : values[b]
        const same = av === null || bv === null ? av === bv : compare(av, bv) === 0

        if (!same) return rank(a) - rank(b)
      } else if (rank(a) !== rank(b)) {
        return rank(a) - rank(b)
      }

      if (score(a) !== score(b)) return score(b) - score(a)
      return compare(objectIds[a], objectIds[b])
    })

    return ids
  }

  intersectAll(candidates, filters, except) {
    let set = candidates

    for (const [key, filter] of filters) {
      if (key === except) continue
      set = intersect(set, filter)
      if (set.size === 0) return set
    }

    return set
  }

  hit(id) {
    return { ...this.artifact.payloads[id], objectID: this.artifact.objectIds[id] }
  }
}

/**
 * §5 — stats over every value carried by records in the base set, counting a record once per
 * value it holds.
 */
function facetStats(values, counts) {
  let min = null
  let max = null
  let sum = 0
  let n = 0

  for (const [index, count] of counts) {
    const value = values[index]
    if (typeof value !== 'number') continue

    min = min === null ? value : Math.min(min, value)
    max = max === null ? value : Math.max(max, value)
    sum += value * count
    n += count
  }

  if (n === 0 || min === null) return null

  return { min, max, avg: sum / n, sum }
}

/**
 * The Algolia `facets` map is keyed by string, so booleans and numbers become keys. The real
 * typed value travels alongside in `caffeineFacets`.
 */
function intersect(a, b) {
  // Iterating the smaller set is the difference between O(min) and O(max) on a query where one
  // facet narrows hard and another barely narrows at all.
  const [small, large] = a.size <= b.size ? [a, b] : [b, a]
  const out = new Set()

  for (const value of small) if (large.has(value)) out.add(value)

  return out
}

function unionSets(sets) {
  const out = new Set()
  for (const set of sets) for (const value of set) out.add(value)
  return out
}

function intersectAllSets(sets) {
  if (sets.length === 0) return new Set()

  let result = sets[0]
  for (let i = 1; i < sets.length; i++) result = intersect(result, sets[i])

  return result
}

function now() {
  return typeof performance !== 'undefined' ? performance.now() : Date.now()
}
