/**
 * Tokenisation, per QUERY_SPEC §7.
 *
 * The twin of src/search/Tokenizer.php. Documents are tokenised only in PHP, at build time —
 * this file exists for the *query*, which is a handful of words rather than a corpus. Keeping
 * it tiny is what makes it possible to hold in step with the PHP side by eye, and the
 * conformance fixtures check that it is.
 */

const MIN_LENGTH = 2
const MAX_LENGTH = 64

/**
 * Lowercases and strips diacritics, so "Café" and "cafe" are the same token.
 *
 * NFD decomposition followed by removing the combining-marks block is exactly what the PHP side
 * does with `Normalizer::FORM_D` and `\p{Mn}`, which is why this is the rule rather than a
 * transliteration table: both languages have it built in and they agree.
 */
export function normalize(text) {
  return String(text)
    .replace(/<[^>]*>/g, '')
    .replace(/&(#\d+|#x[0-9a-f]+|[a-z]+);/gi, decodeEntity)
    .toLowerCase()
    .normalize('NFD')
    .replace(/\p{Mn}+/gu, '')
    .trim()
}

const NAMED_ENTITIES = {
  amp: '&',
  lt: '<',
  gt: '>',
  quot: '"',
  apos: "'",
  nbsp: ' ',
  eacute: 'é',
  egrave: 'è',
  agrave: 'à',
  ccedil: 'ç',
  uuml: 'ü',
  ouml: 'ö',
  auml: 'ä',
  ntilde: 'ñ',
  hellip: '…',
  mdash: '—',
  ndash: '–',
  rsquo: '’',
  lsquo: '‘',
  ldquo: '“',
  rdquo: '”',
}

/**
 * Only the entities that survive tokenisation matter here.
 *
 * A full entity table would be several kilobytes for no gain: an entity that decodes to
 * punctuation is a token separator either way, and one that decodes to a letter needs to be in
 * this list only if it can appear in a *query*. Document text is decoded server-side by PHP's
 * full table before it is ever tokenised.
 */
function decodeEntity(match, body) {
  if (body[0] === '#') {
    const code = body[1] === 'x' || body[1] === 'X'
      ? parseInt(body.slice(2), 16)
      : parseInt(body.slice(1), 10)

    return Number.isFinite(code) ? String.fromCodePoint(code) : match
  }

  const named = NAMED_ENTITIES[body.toLowerCase()]

  return named === undefined ? match : named
}

/**
 * Splits text into normalised tokens.
 *
 * @returns {string[]}
 */
export function tokenize(text) {
  const normalized = normalize(text)

  if (normalized === '') return []

  return normalized
    .split(/[^\p{L}\p{N}]+/u)
    .filter((token) => {
      // Counted in code points, not `.length`. JavaScript's `.length` is UTF-16 code units, so
      // an astral character counts as two where PHP's `mb_strlen` counts one — and the two
      // engines would then disagree about which tokens survive the length filter.
      const length = [...token].length

      return length >= MIN_LENGTH && length <= MAX_LENGTH
    })
}
