# Caffeine query specification

This document defines exactly what a Caffeine query returns. It exists because Caffeine ships
**two** query engines — one in PHP, one in JavaScript — and they must agree on every result,
down to the order of tied records.

They must agree because the same page uses both. The server renders the first paint (so the
page can be statically cached, indexed, and usable without JavaScript); the browser then takes
over refinement without a round trip. If the two engines disagree about anything — a tie-break,
a facet count, where nulls sort — the page visibly changes under the visitor the moment they
touch a control, and it does so silently.

Anything not written down here is a bug in this document, not a licence for either engine to
choose. `tests/conformance/*.json` is the executable form of this spec: every fixture is run by
both engines and both must produce identical output.

---

## 1. The data model

An index is a list of **records**. Record order in the artifact is fixed at build time
(ascending `elementId`, then `siteId`) and is referred to below as **index order**. A record's
position in that list is its **internal id**, an integer from `0`.

Each record has:

| Part | Shape |
| --- | --- |
| `objectID` | String, unique within the index. `<elementId>-<siteId>`. |
| facets | `key → list of values`. Values are strings, numbers or booleans, deduplicated and sorted at build time. |
| sortable | `key → single value or null`. |
| payload | `key → arbitrary JSON`. Never involved in matching. |
| tokens | `token → weight`, a float rounded to 3 decimal places at build time (see below). |

Token weights are **quantised to 3 decimal places** when the artifact is compiled. The wire
format stores each weight as an integer varint of `weight × 1000`, and the server renders the
first paint from a freshly compiled artifact while the browser queries a decoded one — so a
weight the codec could not round-trip exactly would be a place the two engines disagree about
relevance order. Rounding once, at compile time, makes the quantised value the only value either
engine ever sees. Three places is finer than any meaningful `searchWeight` distinction.

Facet values are **interned**: the artifact stores a dictionary of distinct values per facet and
records reference them by integer. Postings lists map a facet value to the sorted list of
internal ids that carry it. None of this changes the semantics below — it is a storage detail —
but both engines operate on postings lists rather than scanning records, and the spec is written
so that is always possible.

---

## 2. Query state

```
query          string, possibly empty
refinements    facetKey → list of values      (string/boolean facets)
ranges         facetKey → {min?, max?}        (numeric and date facets)
sortBy         sorting name, default "relevance"
page           integer, 0-based, default 0
hitsPerPage    integer, default from the index definition
facets         list of facet keys to compute counts for; default all
```

`page` is **0-based**, matching Algolia. This is a trap for the unwary and is stated here rather
than discovered.

---

## 3. Evaluation order

A query is evaluated in exactly this order:

1. **Text matching** → candidate set `T`
2. **Filtering** → result set `R`
3. **Facet counting** (computed from sets derived in step 2, *not* from `R` alone)
4. **Sorting**
5. **Pagination**

Each step is defined below.

### 3.1 Text matching → `T`

If `query` is empty after tokenisation, `T` is every record, and every record's **text score**
is `0`.

Otherwise the query is tokenised with the same rules as document text (§7). Let the resulting
tokens be `q₁ … qₙ`.

- Tokens `q₁ … qₙ₋₁` must match a document token **exactly**.
- The final token `qₙ` matches a document token **exactly or as a prefix**.

The last token is prefix-matched because the visitor is still typing it. This is what makes
search-as-you-type feel instant, and it is the reason the artifact stores its token list sorted:
a prefix match is a binary search for the lower bound followed by a walk while the prefix holds.

A record is in `T` only if **every** query token matches. Matching is conjunctive; Caffeine
never silently drops query words to find more results.

A record's **text score** is the sum, over `q₁ … qₙ`, of the weight of the matched document
token. Where a prefix matches several document tokens, the **highest** weight among them is
used.

### 3.2 Filtering → `R`

For each facet `f` with refinements, define the predicate `filter_f`:

- **Disjunctive** (`facetOperator: or`) — the record has **at least one** of the refined values.
- **Conjunctive** (`facetOperator: and`) — the record has **every** refined value.
- **Range** (numeric/date) — the record has at least one value `v` with `min ≤ v ≤ max`.
  Bounds are inclusive; an omitted bound is unbounded on that side.

A record with no values at all for `f` never satisfies `filter_f`. In particular it is **not**
matched by a range with both bounds omitted.

```
R = T ∩ (⋂ over all refined facets f: filter_f)
```

### 3.3 Facet counting

This is the step most implementations get wrong, and the reason this document exists.

For each facet `f` for which counts are requested:

- **Disjunctive facets** are counted against everything *except their own* refinements:

  ```
  base_f = T ∩ (⋂ over refined facets g ≠ f: filter_g)
  ```

- **Conjunctive facets** are counted against the full result set:

  ```
  base_f = R
  ```

Then `count(f, v)` is the number of records in `base_f` carrying value `v`.

The asymmetry is not an inconsistency; it is what makes each kind of facet behave the way a
visitor expects. Having ticked "Acme" in a disjunctive brand facet, the visitor still needs to
see "Globex (12)" — the count of what they would get *by adding it*. Excluding a facet's own
refinements from its own counts is what produces that number. A conjunctive facet is the
opposite: each tick narrows, so its counts must reflect every tick already made, including its
own.

**Which values appear:**

1. Every currently refined value of `f` appears, **even when its count is `0`** — otherwise the
   control the visitor used to refine vanishes and they cannot undo it.
2. Remaining values with a count `> 0` are added, ordered by §3.3.1, up to `maxValuesPerFacet`.
3. Values with count `0` that are not refined are omitted.

#### 3.3.1 Facet value order

By `facetSort`:

- `count` — descending count, then ascending value (§8) as tie-break.
- `alpha` — ascending value (§8).
- `manual` — the order in `facetValueOrder`; values not listed follow, ordered by `count`.

Refined values are **not** floated to the top. They sort with everything else; a widget may
display them separately, but the data does not reorder itself under the visitor.

### 3.4 Sorting

`sortBy` names a sorting from the index definition. An unknown name falls back to `relevance`
rather than erroring — a stale bookmarked URL should still return results.

Records are ordered by these keys, in order, until one differs:

1. **The sorting's own key.**
   - `relevance`: text score, **descending**.
   - any other: the record's `sortable[attribute]`, ascending or descending per the definition.
2. **Text score, descending.** (No effect when the sorting *is* relevance.)
3. **`objectID`, ascending**, compared by §8.

Key 3 is a total order — `objectID` is unique — so the sort is fully determined. Neither engine
may rely on the stability of its language's sort routine, because PHP's `usort` and JavaScript's
`Array.prototype.sort` do not make the same guarantees.

**Nulls sort last**, in both directions. A record with no value for the sort key is not "less
than" everything; it is unrankable, and burying it is the useful behaviour. Two nulls fall
through to keys 2 and 3.

**Mixed types**: numbers sort before strings. This is arbitrary, but it must be written down,
because a sortable attribute can legitimately hold both when content is inconsistent.

### 3.5 Pagination

```
nbHits      = |R|
nbPages     = ceil(nbHits / hitsPerPage)
page        = clamp(requested page, 0, max(nbPages - 1, 0))
hits        = R[page * hitsPerPage : (page + 1) * hitsPerPage]
```

An out-of-range page is clamped, not an error. `nbPages` is `0` when there are no hits, and
`page` is then `0`.

---

## 4. Hierarchical facets

A hierarchical facet holds path values like `Home > Tools > Saws`.

**Expansion happens at build time, not query time.** A record whose value is
`Home > Tools > Saws` is indexed as carrying all three of:

```
Home
Home > Tools
Home > Tools > Saws
```

Consequently a hierarchical facet is, at query time, an ordinary conjunctive string facet, and
needs no special handling in either engine. Refining on `Home > Tools` matches every descendant
because every descendant carries that exact value.

A widget reconstructs the tree by splitting on the separator and grouping by depth; it does not
need the engine's help. The `level` of a value is its number of separators, and is derivable
from the value alone.

---

## 5. Numeric facets and buckets

Refinement on a numeric facet is always by **range** (§3.2), never by exact value. `numericBuckets`
affects only how a widget *groups values for display*: given boundaries `[0, 25, 50]`, a value
`v` falls in the bucket `[bₖ, bₖ₊₁)` where `bₖ` is the greatest boundary `≤ v`. Values below the
first boundary fall in `(-∞, b₀)`; values at or above the last fall in `[bₙ, ∞)`.

Bucketing never changes `nbHits`, and buckets are not what gets sent back as a refinement — the
range is.

Facet **stats** (`min`, `max`, `avg`, `sum`) are computed over every value carried by records in
`base_f`, counting a record once per value it holds. A record with three values contributes
three times to `avg`. This matches Algolia, and matters for multi-valued numeric facets.

---

## 6. Boolean facets

Values are the JSON literals `true` and `false`, not the strings `"true"` and `"false"`. In URL
state they are encoded as `true` / `false` and parsed back to booleans. A record with no value
is absent from both buckets rather than counted as `false`.

---

## 7. Tokenisation

Both engines must produce identical tokens for the same input. Document tokenisation happens
only in PHP, at build time; query tokenisation happens in both.

1. Strip HTML tags.
2. Decode HTML entities.
3. Lowercase (Unicode-aware).
4. Decompose to NFD and remove combining marks (`\p{Mn}`), so `Café` → `cafe`.
5. Split on any run of characters that are neither letters (`\p{L}`) nor numbers (`\p{N}`).
6. Drop tokens shorter than 2 or longer than 64 characters.

Step 4 is why the engines agree on accented text without shipping a transliteration table, and
why scripts with no case or accents pass through untouched.

### 7.1 Stopwords

An index may declare stopwords. They are removed **from documents at build time and from queries
at query time**, and the symmetry is the whole requirement: matching is conjunctive (§3.1), so a
token with no postings empties the result. Removing "the" from documents alone would make a
search for `the saw` match nothing at all.

The list is normalised by the rules above and **ships inside the artifact**, because the browser
cannot read project config and a list that differed between the two engines would make the same
search return different results on the server and in the browser.

A query consisting entirely of stopwords is treated as **an empty query** — every record matches
— rather than as a query that matched nothing.

### 7.2 Synonyms

An index may declare groups of interchangeable words. They are expanded **at build time only**: a
record containing `sofa` is indexed under every word in its group, at the same weight.

Neither engine knows what a synonym is, and nothing about them appears in the artifact beyond the
extra tokens. Expanding at query time instead would mean shipping the map and implementing the
lookup twice, in two languages, for no behavioural difference.

A word that is also a stopword is never indexed, whichever side of the group it appears on.

---

## 8. Value comparison

Wherever this document says values are compared or sorted:

- **Numbers** compare numerically.
- **Strings** compare by **UTF-8 code unit order** — PHP's `strcmp`, JavaScript's `<` on strings.
  Explicitly *not* locale-aware collation: `localeCompare` and `Collator` differ between
  environments, and the server and the browser would disagree.
- **Booleans**: `false` before `true`.
- **Across types**: numbers before strings before booleans.

Locale-correct display order is a presentation concern. A widget that wants it can sort the
buckets it was given; the engine must not, because the two engines would then need identical
locale data.

---

## 9. Response shape

Both engines return the Algolia search-response shape, so that InstantSearch, React/Vue
InstantSearch and Autocomplete.js work against Caffeine unmodified, and so a site can later
move to Typesense, Meilisearch or Algolia without rewriting its front end.

```jsonc
{
  "hits": [ { "objectID": "12-1", /* payload keys */ } ],
  "nbHits": 137,
  "page": 0,
  "nbPages": 6,
  "hitsPerPage": 24,
  "query": "coffee",
  "params": "query=coffee&page=0",
  "processingTimeMS": 1,
  "exhaustiveNbHits": true,
  "exhaustiveFacetsCount": true,
  "facets": {
    "brand": { "Acme": 12, "Globex": 4 }
  },
  "facets_stats": {
    "price": { "min": 5, "max": 250, "avg": 47.5, "sum": 6507 }
  }
}
```

`facets` is an object of `value → count`, in the order defined by §3.3.1. JSON objects are
formally unordered but every implementation preserves insertion order, and both engines rely on
that; a client that needs guaranteed order should read `caffeineFacets` (below).

Caffeine adds one non-Algolia key, namespaced so it cannot collide:

```jsonc
"caffeineFacets": {
  "brand": {
    "operator": "or",
    "buckets": [ { "value": "Acme", "count": 12, "isRefined": true } ]
  }
}
```

This carries what the Algolia shape has nowhere to put — bucket order as an array, refinement
state, and the facet's operator — and is what the Twig widgets and the bundled runtime read.
Clients that only understand Algolia ignore it.

`exhaustiveNbHits` and `exhaustiveFacetsCount` are always `true`. Caffeine's counts are exact:
it holds the whole index and never approximates. They are present only because the Algolia
shape requires them.

---

## 10. Geo facets

A facet of type `geo` holds a coordinate pair rather than a value, and is filtered by distance
rather than by equality. It is never interned and never bucketed: one facet value per record,
each with a postings list of one, would be all cost and no use.

The artifact stores `geo[key]` parallel to the records — `[lat, lng]`, or `null` for a record
with no coordinates.

**Refinement.** `around[key] = { lat, lng, radius }`, with the radius in metres. A record matches
when `distance ≤ radius`. A record with no coordinates never matches any radius. A radius of
**zero filters nothing** — it exists so a listing can be ordered by distance without also being
narrowed.

**Distance** is the haversine great-circle distance on a sphere of radius **6,371,008.8 m**
(the IUGG mean), **rounded to whole metres and compared as an integer**.

The rounding is not cosmetic. `sin`, `cos` and `sqrt` are not guaranteed to agree to the last bit
between PHP and a JavaScript engine, and a record sitting exactly on the radius could then be
inside it on the server and outside it in the browser — the page rearranging itself under the
visitor over one ulp. Whole metres are far finer than any radius anyone filters by, and they make
the comparison deterministic rather than approximately so.

**Sorting.** `sortBy: "distance"` orders by ascending distance, then by objectID. It is the only
sorting that cannot be precomputed into the artifact, because the point it measures from is
chosen by the visitor. Records with no coordinates sort last. With more than one `around` in
play, a record's distance is the smallest of them.

**Counting.** A geo facet produces no buckets. It appears in neither `facets` nor
`caffeineFacets`, and it constrains the counts of every other facet exactly as any other
refinement does.

---

## 11. What Caffeine deliberately does not implement

Named here so their absence is a decision rather than an oversight:

- **Typo tolerance.** Real work, and wrong to fake. A prefix match on the last token covers
  most of what people actually want from it.
- **The Algolia `filters` string DSL** (`brand:Acme AND price > 10`). Structured refinements
  and ranges cover the same ground without shipping a parser to the browser.
- **Synonyms and stopwords.** Phase 7, Pro.
- **Geo search.** Phase 7, Pro.
- **Personalisation, A/B testing, query rules.** Out of scope permanently.
