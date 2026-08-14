# The artifact format

An **artifact** is everything Caffeine needs to answer a query, compiled once on the server and
served as static files. It is what the browser fetches instead of talking to the database, and
what the server reads instead of running an element query.

This document pins the format down. `docs/QUERY_SPEC.md` is authoritative for what a query
*means*; this is authoritative for how the data it reads is *stored*. Two implementations depend
on it agreeing with itself — `src/search/ArtifactEncoder.php` writes it, and both
`src/search/ArtifactDecoder.php` and `src/web/assets/runtime/src/decode.js` read it.

---

## 1. The files

A published index is three kinds of file in one directory:

```
{publishPath}/{indexHandle}/
  current.json                        the pointer     mutable, tiny, never cached
  {checksum}.index.json               the query data  immutable, cache forever
  {checksum}.payload.json             the card data   immutable, cache forever  (when sharded)
  *.gz, *.br                          precompressed sidecars
```

The split between the pointer and the payload is the part that makes Caffeine work behind a
full-page cache, and it is worth being precise about.

**The payload files are immutable and content-addressed.** The filename is a hash of the file's
own bytes, so the contents at a given URL can never change. They can be served with
`Cache-Control: public, max-age=31536000, immutable`, and a browser that has one never asks for
it again.

**The pointer is stable and mutable.** Cached HTML embeds the URL of `current.json` and nothing
else — never a versioned URL. The HTML can be months old and still find today's index, because
the name it embeds never moves. `current.json` is the one file that must be served uncached
(`Cache-Control: no-cache`), and it is small enough — a few hundred bytes — that this costs
nothing.

A versioned URL in the HTML would defeat the whole arrangement: the moment the index rebuilt,
every cached page would point at a file that no longer existed.

---

## 2. `current.json` — the manifest

```json
{
  "format": 1,
  "index": "products",
  "version": 12,
  "generatedAt": "2026-08-13T11:18:09-07:00",
  "nbRecords": 4210,
  "checksum": "d801b9738cd4e55d…",
  "sharded": true,
  "transport": "htmx",
  "hitsPerPage": 24,
  "shards": {
    "index":   { "file": "61e883951a2c606a.index.json",   "bytes": 1145104, "checksum": "61e883951a2c606a", "url": "…" },
    "payload": { "file": "a8fc90e9569f5bf3.payload.json", "bytes": 1272093, "checksum": "a8fc90e9569f5bf3", "url": "…" }
  }
}
```

| Field | Meaning |
| --- | --- |
| `format` | Layout version. A decoder refuses anything it does not recognise rather than guessing. |
| `version` | Monotonic per index. Bookkeeping and cache-busting only — no query reads it. |
| `checksum` | Identity of the whole artifact: the hash of its shards' hashes. Equal checksums mean equal content. |
| `sharded` | Whether `payload` is a separate file or merged into `index`. |
| `shards` | One entry per document. `file` is relative to the manifest's own directory. |

**The version is deliberately not inside the shard documents.** A shard's filename is a hash of
its bytes, so a version number in the content would make two byte-identical rebuilds hash
differently and republish everything for the sake of one integer — losing exactly the property
the publish path is built on. The decoders take the version from the manifest instead.

---

## 3. Integer encoding

Most of an artifact is lists of integers: postings, permutations, value indexes, lengths. JSON
spends about five bytes on an id that a varint spends one or two on, and gzip cannot recover the
difference because it cannot see that `[1,2,3]` is three small numbers rather than seven
characters.

Integer lists are stored as **base64-wrapped unsigned LEB128 varints**. `src/search/Varint.php`
and `src/web/assets/runtime/src/varint.js` implement this, and `tests/Conformance/varint.json`
pins them against the same vectors in both languages.

Each value is emitted seven bits at a time, least-significant group first, with the high bit set
on every byte except the last:

```
        0 → 00                     127 → 7F
      128 → 80 01                16384 → 80 80 01
```

Two codecs are used:

- **Raw** (`encode`/`decode`) for any non-negative list: permutations, lengths, weights.
- **Delta** (`encodeDelta`/`decodeDelta`) for ascending lists: postings, where the gaps are far
  smaller than the ids and usually fit in one byte.

Values are capped at 2⁵³−1. JavaScript has no integers beyond that, and a codec whose two halves
disagree at the top of their range is worse than one that refuses the value. Both
implementations do the arithmetic with `%` and division rather than `&` and `>>`, because
JavaScript's bitwise operators coerce to 32 bits and would silently wrap where PHP would not.

### 3.1 Lists of lists

A facet's postings are one list per value, and a facet with 4,000 values would become 4,000
short base64 strings whose surrounding JSON punctuation costs more than the ids. So nested
structures are flattened into **two** blobs: the concatenated values, and the length of each
inner list.

```
postings        "AAEEpwLEoAQ="   flat, delta-encoded
postingLengths  "AwIF"           3, 2, 5  →  the first three deltas belong to value 0, …
```

Deltas restart at zero for each inner list, so a decoder can reconstruct one list without
replaying every list before it.

---

## 4. The index document

Everything needed to match, filter, count and order — and nothing needed to render a result.

```json
{
  "format": 1,
  "index": "products",
  "nbRecords": 4210,

  "facets": {
    "brand": {
      "type": "string",
      "operator": "or",
      "sort": "count",
      "valueOrder": [],
      "maxValues": 20,
      "values": ["Acme", "Globex"],
      "postings": "…", "postingLengths": "…",
      "records":  "…", "recordLengths":  "…"
    }
  },

  "sortings":       { "relevance": "…", "price_asc": "…" },

  "tokens":         ["acme", "cordless", "drill"],
  "tokenIds":       "…",
  "tokenWeights":   "…",
  "tokenLengths":   "…",

  "sortableValues": { "title": ["Acme Saw", …], "price": [10.0, …] }
}
```

Records are addressed by **internal id** — their position in index order — rather than by element
id. Integer positions keep postings dense and let both engines use array indexing rather than
hash lookups on the hot path.

### 4.1 Facets

| Field | Meaning |
| --- | --- |
| `type` | `string`, `numeric`, `boolean` or `date`. Hierarchical facets do not appear: QUERY_SPEC §4 expands them to their ancestors at compile time, so by now they are ordinary string facets. |
| `operator` | `or` (disjunctive) or `and` (conjunctive). Drives the counting rule in QUERY_SPEC §3.3. |
| `values` | The interning dictionary: distinct values, in first-seen order. Everything else refers to them by position. |
| `postings` | Value index → sorted internal ids carrying it. Answers *which records have this value* — filtering. |
| `records` | Internal id → sorted value indexes it carries. Answers *which values does this record have* — counting. |

Both directions are stored. Deriving either from the other at query time costs more than the
bytes they take up, and the two questions are asked on different paths.

`records` is dense: there is an entry for every record, empty where a record carries no value for
that facet.

### 4.2 Tokens

`tokens` is the distinct token list, **sorted by code unit**, which is what makes a prefix match
a binary search followed by a walk — and the reason the browser needs no search library.

The postings parallel to it are flattened the same way as facet postings, but into three blobs
rather than two, because each entry is a pair:

- `tokenIds` — internal ids, delta-encoded per token
- `tokenWeights` — the matching weights, as `round(weight × 1000)`
- `tokenLengths` — how many postings belong to each token

Weights are quantised to three decimal places when the artifact is compiled, not when it is
encoded. See §7.

### 4.3 Sortings and sortable values

`sortings` maps a sorting name to a complete ordering of every internal id — a permutation, so it
is raw-varint encoded rather than delta-encoded. With no text query a filtered listing simply
walks the permutation and keeps what survived the filter.

`sortableValues` is per-attribute, internal id → value or null. It is needed only when a text
query is active, because then the text score sits between the sort key and the tie-break
(QUERY_SPEC §3.4) and the order has to be recomputed over the matching records.

It is stored as plain JSON. Interning would not help: sortable values are usually titles or
prices, which are nearly all distinct.

---

## 5. The payload document

```json
{
  "format": 1,
  "index": "products",
  "nbRecords": 4210,
  "objectIds": ["101-1", "102-1"],
  "payloads": [{ "title": "Acme Saw", "url": "/products/acme-saw" }]
}
```

Both are parallel to internal id. `payloads` is whatever the index definition marked
`role=payload` — arbitrary JSON, never involved in matching.

When an index has `shardPayload` off, these two keys are merged into the index document and one
file is published. Sharding is a **publishing** decision, not a compilation one: the encoder
always produces both documents and the publisher chooses whether they travel separately.

---

## 6. Publishing

### 6.1 Order

Shards are written first, then the pointer that names them. A publish interrupted halfway leaves
unreferenced shard files — which pruning collects — and never a live pointer to a file that was
never written. The reverse order would take the index down.

### 6.2 Atomicity

The pointer is rewritten on every publish while visitors are reading it, so a torn write is a
hard error on a live page. The local store writes to a temporary file in the same directory and
`rename()`s it, which is atomic within a filesystem on POSIX. Object stores get their atomicity
from the backend: an S3 `PUT` to an existing key is atomic, and readers see either the old object
or the new one.

### 6.3 Doing nothing

Because filenames are content hashes, an identical rebuild lands on filenames that already
exist. The publisher compares the artifact checksum against the live ledger row *before* writing
anything, and when they match it writes nothing at all, spends no version, and leaves the
pointer's timestamp alone — so nothing downstream sees a reason to refetch.

`caffeine/artifact/publish --force` overrides this. The case it exists for is the one the
checksum cannot see: the ledger says an identical artifact is published, but the files are not
actually there — a wiped web root, a new environment restored from a database dump.

### 6.4 Versions and pruning

Superseded versions are kept, because a visitor who loaded the page a moment before a rebuild is
still fetching the previous one, and pruning it out from under them turns a rebuild into a 404.
The `keepVersions` setting governs how many.

Pruning is per-file, not per-version. Content addressing means versions routinely **share**
shards — an edit that changes one product's title leaves the entire facet index at its original
path — so a retired version's files are deleted only after checking that nothing retained still
points at them. This is what the `files` column in `caffeine_artifacts` is for.

### 6.5 Compression

`.gz` sidecars are written alongside every file, and `.br` where the Brotli extension is
available. A web server configured for static precompression serves them without recompressing
on every request. Brotli is a compiled extension most hosts do not have; where it is missing
nginx simply never finds a `.br` and falls back to `.gz`, so its absence costs nothing.

---

## 7. Round-tripping is exact

`decode(encode(a))` equals `a` — value for value, type for type — and the conformance suite
asserts it against every fixture.

This is not fastidiousness. The server renders the first paint from a freshly compiled artifact
while the browser refines against a decoded one, so any value the codec could not reproduce
exactly is a place the two engines quietly disagree, and the visitor sees the page rearrange
itself the moment they touch a control.

Two consequences fall out of it:

- **Token weights are quantised in the compiler, not the encoder.** Rounding to three decimal
  places once, at compile time, makes the quantised value the only value either engine ever sees.
- **`JSON_PRESERVE_ZERO_FRACTION` is mandatory when serialising.** Without it PHP encodes
  `float(1775403180.0)` as `1775403180`, which decodes back as an *int* — so a numeric sortable
  value silently changes type on its way through the store. JavaScript cannot tell the two apart,
  so the damage is invisible in the browser and surfaces only where PHP reads its own output
  back.

`caffeine/artifact/verify <handle>` checks this end to end against real content: it reads the
published artifact back out of the store, decodes it, and compares it to a fresh compile.

---

## 8. Measured size

A synthetic catalogue — 200 brands, 12 colours, 3 tags of 50 per record, a numeric price, a
boolean, a four-way hierarchy, unique-ish titles, and a four-field payload. Generated by
`tests/bench/artifact-size.php`, which is where these numbers come from and how to re-take them.

| Records | Plain JSON | Encoded | Encoded, gzipped | Saving, gzipped | Compile |
| ---: | ---: | ---: | ---: | ---: | ---: |
| 1,000 | 288.5 KB | 227.1 KB | 56.9 KB | −14.2% | 13 ms |
| 10,000 | 2.99 MB | 2.23 MB | 535.4 KB | −27.1% | 183 ms |
| 100,000 | 32.24 MB | 23.31 MB | 5.21 MB | −30.7% | 2,945 ms |

The encoding is *larger* than plain JSON on a handful of records: base64 adds a third, and a
postings list of two ids is cheaper as `[0,1]` than as a blob with a length sidecar. The
crossover is well below any real index, and the saving grows with size as postings lengthen and
delta gaps stay small.

At 100,000 records the index shard breaks down roughly as:

| Component | Share of the index shard |
| --- | ---: |
| facet postings | 22% |
| sortings | 20% |
| sortable values | 19% |
| facet reverse map | 19% |
| token postings | 12% |
| token dictionary | 5% |
| facet values | <1% |

**This corrects an assumption in the original plan sketch**, which expected the payload to be
"nearly all of the bytes" so that a facet-count request could be answered from a small fraction
of the artifact. It is not: the payload compresses about 7.7×, roughly twice as well as the index
data, so once gzipped the index shard is about **70%** of the total rather than a fraction.
Sharding still helps — a facet-only request skips 30% of the bytes and all of the payload
parsing — but it is not the order-of-magnitude difference the sketch implied.

Two components could shrink and deliberately have not:

- **Sortings** could store only one direction per attribute, deriving the other by reversal. They
  are not exact reverses, though — nulls sort last in both directions and the objectID tie-break
  does not invert — so this needs care rather than an afternoon.
- **Sortable values** could be dropped entirely by comparing each record's *rank* in the
  precomputed ordering instead of its value. That changes QUERY_SPEC §3.4's semantics for records
  with equal sort keys, so it is a spec decision, not an optimisation.

---

## 9. Compatibility

`format` is bumped whenever the layout changes in a way an older decoder would misread. Decoders
refuse an unrecognised format outright rather than guessing at it. Because published payloads are
immutable and pruned on a schedule, a deploy that bumps the format needs one republish per index;
the old files age out normally.
