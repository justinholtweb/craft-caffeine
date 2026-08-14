/**
 * Base64-wrapped LEB128 varints, per docs/ARTIFACT.md §3.
 *
 * Twin of src/search/Varint.php. Read the two side by side; a disagreement here does not
 * rearrange the page like an engine disagreement would, it corrupts the index outright.
 * tests/Conformance/varint.json runs the same vectors through both.
 *
 * Arithmetic is deliberately done with `%` and `Math.floor` rather than `&` and `>>>`:
 * JavaScript's bitwise operators coerce to 32 bits, so an id past 2^31 would silently wrap
 * where PHP's would not.
 */

/** JavaScript's Number.MAX_SAFE_INTEGER. The PHP side refuses anything larger too. */
export const MAX_VALUE = 9007199254740991

/**
 * @param {Iterable<number>} values Non-negative, any order.
 * @returns {string} base64
 */
export function encode(values) {
  const bytes = []

  for (const value of values) encodeOne(value, bytes)

  return toBase64(bytes)
}

/**
 * @param {string} encoded base64
 * @returns {number[]}
 */
export function decode(encoded) {
  if (encoded === '') return []

  const bytes = fromBase64(encoded)
  const values = []
  let current = 0
  let scale = 1

  for (let i = 0; i < bytes.length; i++) {
    const byte = bytes[i]
    current += (byte & 0x7f) * scale

    if ((byte & 0x80) === 0) {
      values.push(current)
      current = 0
      scale = 1
      continue
    }

    scale *= 128

    if (scale > 72057594037927936) {
      throw new Error('Varint payload contains an out-of-range value.')
    }
  }

  if (scale !== 1) throw new Error('Varint payload ends mid-value.')

  return values
}

/**
 * @param {number[]} values Ascending. Equal neighbours allowed; descending is not.
 * @returns {string} base64
 */
export function encodeDelta(values) {
  const bytes = []
  let previous = 0

  for (const value of values) {
    const delta = value - previous

    if (delta < 0) throw new Error('Delta-encoded lists must be ascending.')

    encodeOne(delta, bytes)
    previous = value
  }

  return toBase64(bytes)
}

/**
 * @param {string} encoded base64
 * @returns {number[]}
 */
export function decodeDelta(encoded) {
  const values = decode(encoded)
  let running = 0

  for (let i = 0; i < values.length; i++) {
    running += values[i]
    values[i] = running
  }

  return values
}

function encodeOne(value, bytes) {
  if (!Number.isInteger(value) || value < 0) {
    throw new Error(`Varints are unsigned integers; got ${value}.`)
  }

  if (value > MAX_VALUE) throw new Error('Varint value exceeds the safe integer range.')

  let remaining = value

  do {
    let byte = remaining % 128
    remaining = Math.floor(remaining / 128)

    if (remaining !== 0) byte |= 0x80

    bytes.push(byte)
  } while (remaining !== 0)
}

/**
 * `btoa`/`atob` rather than Buffer or TextEncoder: they exist unchanged in every browser and in
 * Node since 16, so the runtime ships without a shim and the test runner needs no polyfill.
 * Chunked because `String.fromCharCode(...bytes)` blows the argument limit on a large index.
 */
function toBase64(bytes) {
  let binary = ''

  for (let i = 0; i < bytes.length; i += 8192) {
    binary += String.fromCharCode.apply(null, bytes.slice(i, i + 8192))
  }

  return btoa(binary)
}

function fromBase64(encoded) {
  const binary = atob(encoded)
  const bytes = new Uint8Array(binary.length)

  for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i)

  return bytes
}
