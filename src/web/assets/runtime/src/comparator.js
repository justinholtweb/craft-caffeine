/**
 * Value comparison, per QUERY_SPEC §8.
 *
 * The twin of src/search/Comparator.php. Every ordering decision in the engine routes through
 * here, so there is exactly one place per language where "which of these comes first" is
 * answered.
 */

/** Numbers before strings before booleans. */
function typeRank(value) {
  if (typeof value === 'number') return 0
  if (typeof value === 'boolean') return 2
  return 1
}

/**
 * Returns <0, 0 or >0.
 *
 * Deliberately not locale-aware. `localeCompare` and PHP's `Collator` order accented and cased
 * characters differently, and differently again by ICU version — so a browser and a server
 * sorting the same facet would disagree, intermittently, on some machines only. Code-unit order
 * is ugly for humans and identical everywhere; a widget that wants locale order can sort the
 * buckets it was handed.
 */
export function compare(a, b) {
  const rankA = typeRank(a)
  const rankB = typeRank(b)

  if (rankA !== rankB) return rankA < rankB ? -1 : 1

  if (rankA === 0) return compareNumbers(a, b)
  if (rankA === 2) return (a ? 1 : 0) - (b ? 1 : 0)

  return compareStrings(String(a), String(b))
}

/**
 * PHP's `strcmp` compares bytes; JavaScript's `<` compares UTF-16 code units. They agree for
 * everything in the Basic Multilingual Plane, and disagree for astral characters — emoji, rare
 * CJK — because UTF-16 surrogates sort below U+E000 while their UTF-8 bytes sort above it.
 * Comparing by code *point* matches PHP's byte order for all of Unicode.
 */
function compareStrings(a, b) {
  if (a === b) return 0

  const ai = a[Symbol.iterator]()
  const bi = b[Symbol.iterator]()

  for (;;) {
    const an = ai.next()
    const bn = bi.next()

    if (an.done && bn.done) return 0
    if (an.done) return -1
    if (bn.done) return 1

    const ac = an.value.codePointAt(0)
    const bc = bn.value.codePointAt(0)

    if (ac !== bc) return ac < bc ? -1 : 1
  }
}

function compareNumbers(a, b) {
  // NaN would make the comparison non-transitive and corrupt the sort, so it sorts last
  // consistently rather than comparing equal to everything.
  if (Number.isNaN(a)) return Number.isNaN(b) ? 0 : 1
  if (Number.isNaN(b)) return -1

  return a < b ? -1 : a > b ? 1 : 0
}
