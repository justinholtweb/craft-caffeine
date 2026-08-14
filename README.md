# Caffeine

**Instant faceted search and filtering for Craft CMS.**

Model an index in the control panel. Caffeine keeps a self-contained search artifact up to date
and served, and refinements resolve against it — never against the database.

```twig
{% caffeine 'products' as search %}
  {{ search.searchBox() }}
  {{ search.refinementList('brand') }}
  {{ search.rangeInput('price') }}

  {% caffeineresults %}
    {% for hit in search.hits %}
      {% include '_cards/product' with { hit: hit } only %}
    {% endfor %}
    {{ search.pagination() }}
  {% endcaffeineresults %}
{% endcaffeine %}
```

---

## The problem

A faceted listing needs a count next to every value — "Acme (12)", "Globex (4)" — and those
counts depend on every *other* refinement. Done with element queries, that is one query per facet
value per interaction, and it is why filtered listings built on Sprig feel slow at exactly the
moment the visitor is exploring fastest.

Caffeine moves the work to index time. A background build maps your content into records, compiles
them into one artifact, and publishes it. From then on a refinement is set arithmetic over
postings lists — on the server for the first paint, in the browser for everything after.

## What makes it work

**The full-text index is built in PHP and ships inside the artifact.** Tokenising happens once, on
the server, so the browser needs no search library at all — just a binary search over a sorted
token array. This is the load-bearing idea.

**One spec, two engines, one fixture suite.** `docs/QUERY_SPEC.md` is authoritative;
`src/search/Engine.php` and `src/web/assets/runtime/src/engine.js` both implement it, and
`tests/Conformance/` runs against both. The server renders the first paint and the browser takes
over — any disagreement would show up as the page rearranging itself under the visitor, so the
five pieces of logic that exist in both languages are pinned against each other by fixtures: the
tokeniser, the varint codec, the facet-value projection, the URL codec and the haversine.

**Everything works without JavaScript.** Every facet is a real `<a href>`, every widget a real
form. The runtime intercepts controls that already work, which is why it is a few kilobytes and
why turning it off degrades to page loads rather than to nothing.

**It survives a full-page cache.** Cached HTML embeds a *stable pointer*, never a versioned URL.
The payload it names is content-addressed and immutable, so it can be cached forever; the pointer
is a few hundred bytes and is the only thing that must not be. A page cached months ago still
finds today's index.

---

## Measured

A synthetic catalogue: 200 brands, 12 colours, three tags of fifty per record, a numeric price, a
boolean, a four-level hierarchy, near-unique titles, and a four-field payload. Reproduce with
`tests/bench/artifact-size.php` and `tests/bench/query-latency.php`; hardware is a 2023 Apple
Silicon laptop running PHP 8.2 and Node 24 in Docker, so treat these as shape, not as guarantees.

### Build and size

| Records | Build | Artifact (gzipped) | vs plain JSON |
| ---: | ---: | ---: | ---: |
| 1,000 | 13 ms | 56.9 KB | −14% |
| 10,000 | 183 ms | 535.4 KB | −27% |
| 100,000 | 2.9 s | 5.21 MB | −31% |

Integer lists are stored as base64 delta varints rather than JSON arrays — gzip cannot recover
that difference on its own, because it cannot see that `[1,2,3]` is three small numbers rather
than seven characters.

### Query latency

Median, over the same artifact, for six representative queries.

| | 1,000 | 10,000 | 100,000 |
| --- | ---: | ---: | ---: |
| Unrefined listing (PHP / JS) | 1.4 / 0.7 ms | 8.1 / 3.5 ms | 128 / 33 ms |
| One refinement | 0.4 / 0.2 ms | 2.3 / 1.8 ms | 38 / 18 ms |
| Two facets and a range | 0.1 / 0.1 ms | 0.8 / 1.1 ms | 8 / 14 ms |
| Text query | 0.2 / 0.1 ms | 1.4 / 1.1 ms | 18 / 14 ms |
| Sorted, page 21 | 1.3 / 0.5 ms | 9.6 / 3.7 ms | 133 / 40 ms |
| Browser decode, once on load | 5 ms | 23 ms | 165 ms |

**How to read this.** Narrowing is nearly free — the more refined the query, the less work either
engine does. The expensive case is the *unrefined* listing, because it touches every record.

Which is what sets the transport budget: the `client` transport is comfortable to roughly 50,000
records, where the artifact is a few hundred kilobytes and decode is under 100 ms. Past that the
artifact is megabytes and the browser is doing too much work on load, so use the `htmx` transport
and let the server answer. At 100,000 records a server-side query is 8–130 ms — fine behind a page
cache, and the honest ceiling for this design without the memory-mapped endpoint transport.

---

## Installation

```sh
composer require justinholtweb/craft-caffeine
php craft plugin/install caffeine
```

Requires Craft 5.3+ and PHP 8.2+.

### What it can index

Entries, categories, tags, assets, users and Commerce products, plus anything a plugin registers.
Field types that would otherwise index as an opaque object — links from Hyper, FreeLink or Craft's
own Link field, Google Maps addresses, Money, colours, dropdowns — are read by **value
extractors** that match on shape rather than class name, so a link field is a link field whatever
produced it. Facets can be strings, hierarchies, numbers, booleans, dates or **coordinates**, the
last filtered by distance from a point. Indexes can declare **stopwords** and **synonyms**.

Then define an index in **Caffeine → Indexes**, and:

```sh
php craft caffeine/index/build --all      # map content into records
php craft caffeine/artifact/publish       # compile and publish the artifact
```

After that it maintains itself: element saves mark the affected records stale and a debounced
queue job rebuilds and republishes. A `resave/entries` over 5,000 entries collapses to one job.

### Console commands

| | |
| --- | --- |
| `caffeine/index/status` | Record counts, and what is stale. |
| `caffeine/index/build [handle] [--all]` | Map content into records. |
| `caffeine/index/preview <handle> <id>` | What Caffeine would build for one element. |
| `caffeine/artifact/publish [handle] [--force]` | Compile and publish. |
| `caffeine/artifact/status` | What is live, and whether it is behind the CMS. |
| `caffeine/artifact/verify <handle>` | Check the published artifact against a fresh compile. |
| `caffeine/artifact/versions <handle>` | Recent versions. |
| `caffeine/artifact/prune [handle]` | Retire old versions. |

---

## Transports

Set per index.

| | |
| --- | --- |
| **`htmx`** (default) | A refinement fetches a fragment; hit markup stays in Twig. HTMX itself is optional — the bundled runtime does its own fetch and swap. |
| **`client`** | The browser fetches the artifact once and answers everything locally. Zero round-trips. |
| **`algolia-json`** | Serves the Algolia response shape, so InstantSearch, React/Vue InstantSearch and Autocomplete.js work unmodified. |

The wire format is the Algolia search response throughout, which also means a site can graduate to
Typesense, Meilisearch or Algolia later without rewriting its front end.

---

## Documentation

| | |
| --- | --- |
| [`docs/TWIG.md`](docs/TWIG.md) | Tags, widgets, URLs, transports, cached pages. |
| [`docs/EXTENDING.md`](docs/EXTENDING.md) | Add a field type, an element type, or your own markup. |
| [`docs/MIGRATING-FROM-SPRIG.md`](docs/MIGRATING-FROM-SPRIG.md) | A straight translation, with the trade stated plainly. |
| [`docs/CACHING.md`](docs/CACHING.md) | Serving artifacts, and the Blitz recipe. |
| [`docs/QUERY_SPEC.md`](docs/QUERY_SPEC.md) | What a query means. Authoritative for both engines. |
| [`docs/ARTIFACT.md`](docs/ARTIFACT.md) | The wire format and how publishing works. |

## Editions

**Lite** is free: one index, entries only. **Pro** is $149, $119 to renew: unlimited indexes, every
element type, all three transports.

## License

This plugin is released under the [Craft License](https://craftcms.github.io/license/).
