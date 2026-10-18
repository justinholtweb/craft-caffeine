# Changelog

All notable changes to Caffeine are documented here.

Caffeine follows [semantic versioning](https://semver.org). The major version tracks Craft, so
5.x supports Craft 5 — the same convention the rest of the family uses.

## 5.1.0 — 2026-10-18

The same code as 5.0.1, under the version it should have had. 5.0.1 added features, which calls
for a minor release, not a patch. Nothing changed between them, so upgrading from 5.0.1 is a
no-op.

## 5.0.1 — 2026-10-15

### Added

- A `filters` option on `{% caffeine %}` for refinements that always apply and stay out of the
  URL, so a category or brand page can share the shop's listing template. Fixed buckets come back
  with `fixed: true`. ([#2](https://github.com/justinholtweb/craft-caffeine/issues/2))
- `caffeine/index/prune-orphans`, which runs the same cleanup as Craft's garbage collection on
  demand. ([#3](https://github.com/justinholtweb/craft-caffeine/issues/3))

### Changed

- Garbage collection now also deletes content-addressed files in an index's publish directory
  that no ledger row lists and the live pointer does not name, such as files left behind by a
  database restore, plus temporary files from interrupted writes that are over an hour old.
- Publishing, pruning and the sweep take a per-index lock, so two publishes of the same index no
  longer race each other.

### Fixed

- Clicking a facet link in Chrome or Edge, which focus links on click, no longer leaves its
  region unchanged. Only a region with a text field being typed into is skipped, and focus goes
  back to the same control after the swap. Back and Forward are fixed by the same change.
  ([#1](https://github.com/justinholtweb/craft-caffeine/issues/1))
- A page passing a refinement through `params` that wasn't in its URL no longer re-fetches its
  results on every load; use `filters` for this instead.
  ([#2](https://github.com/justinholtweb/craft-caffeine/issues/2))
- Deleting an index through project config now removes its records, ledger rows and published
  files, and changing one marks its records dirty. Both handlers read the index uid from the
  wrong key and returned without doing anything.
  ([#3](https://github.com/justinholtweb/craft-caffeine/issues/3))

## 5.0.0 — 2026-08-14

Initial release.

### Indexes

- Model an index in the control panel: sources, attributes, facets, sortings, transport and
  paging. Definitions live in **project config**, so an index deploys with the code the way
  sections and fields do. Everything derived from one — records, artifacts, dependency edges — is
  environment-local.
- Sources for **entries, categories, tags, assets, users and Commerce products**, plus a registry
  so a plugin can add its own element type.
- **Value extractors** read field types that would otherwise index as an opaque object: links
  (Hyper, FreeLink, Craft's Link field), Google Maps addresses, Money, colours and dropdowns. They
  match on shape rather than class name, so a link field works whatever produced it, and a plugin
  can register its own.
- Facets can be **strings, hierarchies, numbers, booleans, dates or coordinates**. Geo facets are
  filtered by distance from a point.
- **Stopwords and synonyms** per index.
- Attribute values can be reached by dotted path, including into related elements and into an
  extractor's named parts — `venue.city`, `link.text`.

### Querying

- Two query engines, PHP and JavaScript, implementing one written specification
  (`docs/QUERY_SPEC.md`) and checked against one fixture suite. The server renders the first paint
  and the browser takes over refinement.
- Facet counting follows the disjunctive/conjunctive asymmetry that makes counts behave the way a
  visitor expects: a disjunctive facet is counted with its own refinements excluded, so
  "Globex (12)" keeps showing a useful number after "Acme" is ticked.
- Full-text search over an inverted index **built in PHP at index time and shipped inside the
  artifact**, so the browser needs no search library — just a binary search over a sorted token
  array.
- Numeric and date ranges, hierarchical refinement, precomputed sortings, and distance sorting.

### The front end

- `{% caffeine %}` and `{% caffeineresults %}` Twig tags, and eight widgets — search box,
  refinement list, hierarchical menu, range input, current refinements, sort, stats, pagination.
- **Everything works without JavaScript.** Every facet is a real `<a href>` and every widget a
  real form; the runtime intercepts controls that already work.
- Three transports per index: `htmx` (fragment swap, the default), `client` (the browser answers
  locally, no round trips) and `algolia-json` (the Algolia response shape, for InstantSearch and
  friends).
- Widget markup is overridable by dropping a template of the same name under `_caffeine/`.
- Designed for a full-page cache: cached HTML embeds a **stable pointer**, never a versioned URL,
  and the runtime refines a cached canonical page before paint.

### Publishing and updates

- Artifacts are **content-addressed and immutable**, published behind a small mutable pointer, so
  payloads can be cached forever and the pointer can be cached not at all.
- Publishes to the local web root or to any Craft filesystem (S3, a CDN volume). `.gz` and `.br`
  sidecars are written alongside.
- An identical rebuild writes nothing, spends no version and leaves the pointer's timestamp alone.
- Element saves mark the affected records stale and a **debounced** queue job rebuilds and
  republishes. A `resave` across thousands of entries collapses to one job.
- Denormalised values stay honest: `caffeine_deps` records every element read to build a record,
  so renaming a category marks every record that copied its title.
- A failed build leaves the previously published artifact live and untouched.

### The control panel

- Index list with health — record count, what is stale, live version, artifact size, last build.
- A definition builder, a **record preview** that shows what the mapper produces for one real
  element, and a **query playground** that runs a state against the published artifact.
- Manual rebuild and republish, and a settings screen.

### Console

`caffeine/index/status`, `build`, `preview`, `touch`; `caffeine/artifact/publish`, `status`,
`versions`, `verify`, `prune`.

### Editions

- **Lite** (free): one index, entries only, string and boolean facets, the fragment transport,
  relevance plus one named sorting.
- **Pro**: unlimited indexes, every element type, every facet type including geo, all three
  transports, unlimited sortings, stopwords and synonyms, and the control panel's preview and
  query playground.

A site whose Pro licence lapses is **downgraded rather than broken**: the stored definition is
left intact and the index keeps working with the Lite feature set, so renewing restores exactly
what was there.

### Requirements

Craft 5.3+ and PHP 8.2+. No runtime dependencies beyond Craft itself; the browser runtime ships
as ES modules with no build step.
