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

Median, over the same artifact, for six representative queries. Re-measured for 5.3.0, which
changed how facets are counted.

| | 1,000 | 10,000 | 100,000 |
| --- | ---: | ---: | ---: |
| Unrefined listing (PHP / JS) | 1.1 / 0.3 ms | 1.3 / 1.1 ms | 3.8 / 9.0 ms |
| One refinement | 0.4 / 0.2 ms | 2.5 / 1.3 ms | 14 / 14 ms |
| Two facets and a range | 0.2 / 0.1 ms | 1.2 / 1.1 ms | 13 / 16 ms |
| Text query | 0.3 / 0.1 ms | 2.6 / 1.2 ms | 24 / 16 ms |
| Sorted, page 21 | 1.1 / 0.3 ms | 1.3 / 0.9 ms | 5.7 / 8.5 ms |
| Browser decode, once on load | 7 ms | 30 ms | 212 ms |

**How to read this.** An unrefined or merely sorted listing is cheap: every count is the length of
a postings list and every order is precomputed. The work is in narrowing — a text query, or
refinements that leave a large part of the catalogue — and even that stays in the tens of
milliseconds at 100,000 records.

The `client` transport is comfortable to roughly 50,000 records, where the artifact is a few
hundred kilobytes and decode is under 100 ms. Past that the artifact is megabytes and the browser
is doing too much work on load, so use the `htmx` transport and let the server answer.

### A heavier catalogue

The numbers above have six facets and short titles. `tests/bench/heavy-catalogue.php` builds the
shape reported in [#5](https://github.com/justinholtweb/craft-caffeine/issues/5): 37,272 products
in eight types, 134 facets (sixteen of them per type, carried only by that type) and a sixty-word
description each. Server side, same machine, same day:

| | 5.2.0 | 5.3.0 | 5.3.0, artifact in OPcache |
| --- | ---: | ---: | ---: |
| Decoding the artifact, per request | 2,084 ms | 532 ms | 0 ms |
| Memory that costs the request | 967 MB | 217 MB | 0 MB |
| Unrefined listing | 155 ms | 17 ms | 20 ms |
| One facet value | 40 ms | 11 ms | 11 ms |
| Two facets and a range | 29 ms | 8 ms | 7 ms |
| Text query | 54 ms | 39 ms | 32 ms |
| Sorted, page 21 | 190 ms | 19 ms | 19 ms |

With the `htmx` transport every refinement is a request, so the decode row was being paid on every
click. *Keep artifacts in OPcache* removes it, at the cost of about 180 MB of OPcache memory for
this catalogue; see "Large catalogues" in `docs/TWIG.md`. With `shardPayload` on, the server also
stops reading the payload shard and loads only the page of hits it renders, which takes the
memory row from 217 MB to 179 MB (the decode time barely moves: the payload is the cheap part to
decode).

Builds: an index can eager-load what its mapping reads (`Builder::EVENT_DEFINE_SOURCE_QUERY`, see
`docs/EXTENDING.md`), and `caffeine/index/build --all --workers=4` splits a full build across
processes.

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
| `caffeine/index/build [handle] [--all] [--workers=N]` | Map content into records. `--workers` splits a full build across N processes. |
| `caffeine/index/preview <handle> <id>` | What Caffeine would build for one element. |
| `caffeine/index/prune-orphans` | Clean up after deleted indexes, and remove published files nothing references. |
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
