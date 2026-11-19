/**
 * Rebuilds an artifact from the wire format. Twin of src/search/ArtifactDecoder.php.
 *
 * This runs once, when the runtime loads the artifact, and produces exactly the structure
 * engine.js queries — the same structure the PHP engine holds after compiling. The engines were
 * never taught about the wire format, which is why Phase 3 changed how an artifact is stored
 * without changing a line of how it is queried.
 *
 * Decoding is not pure overhead. `JSON.parse` on a nested array of a million ids allocates a
 * million boxed numbers and the surrounding array objects; walking a varint blob allocates the
 * lists once, from a payload that arrived several times smaller.
 */

import * as varint from './varint.js'

/** Bumped when the layout changes incompatibly. Must match ArtifactEncoder::FORMAT. */
export const FORMAT = 1

/** Weights ship as integers. Must match ArtifactEncoder::WEIGHT_SCALE. */
export const WEIGHT_SCALE = 1000

/**
 * @param {object} index The index document. May already carry the payload.
 * @param {object} [payload] The payload document, when it was sharded out.
 * @param {number} [version] From the manifest — shard documents deliberately do not carry it, so
 *                           that byte-identical rebuilds hash to the same filename.
 * @returns {object} The artifact shape engine.js reads.
 */
export function decodeArtifact(index, payload, version = 0) {
  if (index.format !== FORMAT) {
    throw new Error(`Unsupported artifact format ${index.format}; this build reads format ${FORMAT}.`)
  }

  const source = payload || index
  const recordCount = index.nbRecords || 0
  const facets = {}

  for (const [key, facet] of Object.entries(index.facets || {})) {
    facets[key] = {
      type: facet.type,
      operator: facet.operator,
      sort: facet.sort ?? null,
      valueOrder: facet.valueOrder || [],
      maxValues: facet.maxValues || 0,
      values: facet.values || [],
      postings: decodeLists(facet.postings || '', facet.postingLengths || ''),
      records: decodeLists(facet.records || '', facet.recordLengths || ''),
    }
  }

  const sortings = {}

  for (const [name, order] of Object.entries(index.sortings || {})) {
    sortings[name] = varint.decode(order || '')
  }

  return {
    index: index.index,
    version,
    nbRecords: recordCount,
    objectIds: source.objectIds || [],
    payloads: source.payloads || [],
    facets,
    sortings,
    tokens: index.tokens || [],
    tokenPostings: decodeTokenPostings(
      index.tokenIds || '',
      index.tokenWeights || '',
      index.tokenLengths || '',
    ),
    sortableValues: index.sortableValues || {},
    stopwords: index.stopwords || [],
    geo: index.geo || {},
  }
}

/** Whether a document carries its own payload rather than pointing at a separate shard. */
export function hasPayload(document) {
  return document != null && document.objectIds !== undefined
}

/**
 * Splits one varint blob into the lists its lengths blob describes.
 *
 * Deltas restart at zero for each inner list, so a list can be reconstructed without replaying
 * every list before it — the same rule the PHP encoder writes by.
 */
function decodeLists(flat, lengths, delta = true) {
  const values = varint.decode(flat)
  const counts = varint.decode(lengths)
  const lists = []
  let offset = 0

  for (const count of counts) {
    const list = new Array(count)
    let running = 0

    for (let i = 0; i < count; i++) {
      if (offset >= values.length) {
        throw new Error('Artifact postings are shorter than their lengths claim.')
      }

      if (delta) {
        running += values[offset]
        list[i] = running
      } else {
        list[i] = values[offset]
      }

      offset++
    }

    lists.push(list)
  }

  return lists
}

function decodeTokenPostings(ids, weights, lengths) {
  const idValues = varint.decode(ids)
  const weightValues = varint.decode(weights)
  const counts = varint.decode(lengths)

  const postings = []
  let offset = 0

  for (const count of counts) {
    const list = new Array(count)
    let running = 0

    for (let i = 0; i < count; i++) {
      running += idValues[offset] || 0
      list[i] = [running, weightValues[offset] / WEIGHT_SCALE]
      offset++
    }

    postings.push(list)
  }

  return postings
}
