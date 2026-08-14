# Caffeine — build plan

**Caffeine** is a Craft CMS 5 plugin that gives a site instant faceted search and filtering
without running an element query per interaction. You model an index in the control panel —
which sections, which fields, which of them are facets — and Caffeine keeps a self-contained
JSON search artifact permanently up to date and publicly served. Twig tags render the facet
UI and the results; refinements resolve against the artifact in single-digit milliseconds,
in PHP or in the browser, on pages that are fully static-cached.

It is the replacement for the Sprig-based filtering pattern, where every checkbox click
re-runs a Craft element query with joins across `elements`, `elements_sites`, `content`,
`relations` and `structureelements`, and gets slower as the catalogue grows.

- Package: `justinholtweb/craft-caffeine`
- Namespace: `justinholtweb\caffeine`
- Handle: `caffeine`
- Requires: PHP `^8.2`, `craftcms/cms ^5.3.0`, `ext-json`
- Optional: `ext-zlib` / `ext-brotli` for precompressed artifacts, `ext-gmp` for bitmap sets

## The problem, stated precisely

A filtered listing has three costs, and only one of them is the search:

1. **Resolving the candidate set.** Craft does this with SQL. With relation-based facets it
   is a join per facet, and `COUNT`s for facet tallies mean N+1 more queries — one per facet
   value, to show "Brand (12)". This is the cost that makes Sprig-driven filtering feel slow,
   and it is paid again on every single interaction.
2. **Rendering the cards.** Cheap once the elements are eager-loaded, and Twig-authored,
   which is why designers want to keep writing it in Twig.
3. **Getting the page to the browser.** Wants to be a static cache hit.

Caffeine attacks (1) by precomputing it. Facet counts, sort orders and the full-text index
are all derived once, at index time, in a background job. At request time there are no joins
and no `COUNT`s — just set intersections over integer postings lists. It leaves (2) in Twig
and it is designed so that (3) stays a cache hit.

## Product decisions

| Decision | Choice | Why |
| --- | --- | --- |
| Wire format | The Algolia search-response shape (`hits`, `nbHits`, `facets`, `page`, `nbPages`, `processingTimeMS`…) | It is the de-facto standard for faceted search. Adopting it means InstantSearch.js, React/Vue InstantSearch and Autocomplete.js work against Caffeine unmodified, and a site can later graduate to Typesense, Meilisearch or Algolia without rewriting its front end. Verified against the `algoliasearch-helper` result reference. |
| Bundled JS | Caffeine's own small runtime, **not** InstantSearch | The Twig-first DX the plugin is for does not need 200 KB of widget framework. InstantSearch stays a supported, documented option because of the wire format above — not a dependency. |
| Query engine | Written twice, in PHP and JS, against one written spec and one shared fixture suite | Server-rendered first paint and client-side refinement must agree exactly, or the page visibly changes under the user. Two third-party engines with different tie-breaking guarantee that mismatch. |
| Full-text | Inverted index **built in PHP at index time and shipped inside the artifact** | Tokenising and stemming happen once, in one language. The browser never needs a search library — it does a binary search over a sorted token array. This is the single most load-bearing idea in the design. |
| Transports | Three, chosen per index: `client`, `algolia-json`, `htmx` | `htmx` is the fragment-swap default and keeps hit markup in Twig, where the designer wants it — its endpoint reads the artifact, not the database. `client` is zero round-trips. `algolia-json` is for bringing your own InstantSearch. HTMX itself stays optional: the runtime does its own fetch-and-swap and only emits `hx-*` when the site actually uses HTMX. |
| Index definitions | Project config | An index is schema. It deploys with the code, like sections and fields. Records and artifacts are not — they live in the database and on a filesystem. |
| Artifact addressing | Stable pointer, immutable payload | Cached HTML must never contain a URL that a rebuild invalidates. See "Cached pages" below. |
| Element types | Entries first, then a source registry | Mirrors Archive's collector registry, so the family stays consistent and third parties can add Commerce products or Formie submissions without a PR. |
| Licence / price | Paid. Free **Lite** (one index, entries only) + **Pro $149 / $119 renewal** | This displaces both Sprig-plus-hand-rolled-filtering and, for many sites, a paid Algolia plan. Priced against Smoke and Rabbits at $129, a little above because the surface is larger. |
| CP vocabulary | "Indexes", plainly | Easier to document, and the family has otherwise stayed literal. The coffee metaphor lives in the marketing copy, not the UI. |
| Brand | Coffee brown **#604330** on white; a mug with steam | `src/icon.svg` is the full-colour plugin icon and `src/icon-mask.svg` its monochrome twin for the CP nav, drawn from the same paths so the two cannot drift. #604330 is the accent the marketing site will be built on, the way icecube set the pattern for the family. |

## Architecture

```
Definition (project config)
  └── IndexDefinition        sources, sites, attribute map, facet types, sortings, transports

Build side (background, never in a page request)
  ├── SourceRegistry         element type → Source                      (extensible via event)
  │     └── Source           element → raw record array
  ├── AttributeMapper        raw record → typed record (searchable / facet / sortable / payload)
  │     └── ValueExtractor    field value → scalar, array, or ref       (extensible via event)
  ├── RecordStore            caffeine_records  + caffeine_deps  (reverse dependency map)
  ├── Tokenizer              searchable text → token ids  (PHP only, by design)
  └── ArtifactBuilder        records → dictionary + postings + payload → publish → manifest

Query side (one spec, two implementations)
  ├── QuerySpec              docs/QUERY_SPEC.md — the contract both engines satisfy
  ├── Engine (PHP)           artifact + state → Algolia-shaped result
  ├── engine.js              artifact + state → Algolia-shaped result
  └── conformance fixtures   tests/conformance/*.json — run by PHPUnit *and* node

Delivery
  ├── {% caffeine %} Twig    server-renders first paint from Engine (PHP)
  ├── FragmentController     refinement → re-rendered Twig fragment      (default transport)
  ├── SearchController       Algolia-shaped JSON                          (BYO InstantSearch)
  └── caffeine.js            adopts server markup, swaps fragments or runs engine.js locally
```

Everything crosses `SourceRegistry`, `ValueExtractor` and `QuerySpec`, so a third party can add
an element type, a field type, or an entire front end without touching the plugin.

## The index definition, modelled in the CP

An index is defined on one CP screen and stored in project config:

- **Sources** — sections and entry types, plus (later) categories, tags, assets, users, Commerce
  products. Per-site or shared.
- **Attribute map** — a row per index key. Each row picks a source (element attribute, custom
  field, or a nested path into Matrix) and assigns roles, which are not mutually exclusive:

  | Role | Effect |
  | --- | --- |
  | `searchable` | Text is tokenised into the artifact's inverted index, with a weight. |
  | `facet` | Becomes a refinable facet. Typed: `string`, `hierarchical`, `numeric`, `boolean`, `date`. |
  | `sortable` | Gets a precomputed sort order in the artifact. |
  | `payload` | Carried on the hit so Twig can render a card without touching the database. |

- **Facet options** — display name, conjunctive (AND) vs disjunctive (OR), value ordering
  (count / alpha / manual), `maxValuesPerFacet`, and for `numeric`, bucket boundaries.
- **Sortings** — named replicas (`price_asc`, `title_asc`, `postDate_desc`), each an
  ordering over the record set, precomputed.
- **Live preview** — the screen shows the JSON record Caffeine would produce for a real entry
  you pick, updating as you change the map. This is what makes "model the JSON from the CP"
  actually usable rather than a guessing game; nobody should have to rebuild an index to find
  out that a Matrix path was wrong.

## Auto-updating: how the artifact stays true

Element saves are caught by `Elements::EVENT_AFTER_SAVE_ELEMENT`,
`EVENT_AFTER_DELETE_ELEMENT`, `EVENT_AFTER_RESTORE_ELEMENT`,
`EVENT_AFTER_UPDATE_SLUG_AND_URI` and `Structures::EVENT_AFTER_MOVE_ELEMENT` — all confirmed
present in Craft 5.9. Each marks the affected rows dirty; it never rebuilds inline.

Two hard parts, and their answers:

**Related content going stale.** If a record denormalises a category's title into a facet
label, renaming the category leaves every record that references it wrong. `caffeine_deps`
records, for each index record, every element that was read to build it. Saving any element
looks up its dependents and marks those dirty too. This is the same shape as Craft's own
`templatecacheelements` table, for the same reason.

**Resaves stampeding the queue.** A `resave/entries` across 5,000 entries must not enqueue
5,000 jobs or republish 5,000 artifacts. Craft 5 ships exactly the right primitive:
`BulkOpEvent::deferredOn()` defers a handler until the bulk operation completes and only
fires if the event occurred during it. Caffeine registers its dirty-marking through that, so
a resave collapses to one deferred pass. On top of it, a debounce window (default 30s,
configurable, or `0` for immediate) coalesces ordinary editorial saves, and publishing is
mutex-guarded so two jobs cannot interleave writes.

Failure behaviour is chosen, not incidental: a build that throws leaves the previously
published artifact live and untouched, and surfaces the failure in the CP. A stale index is
recoverable; a missing one takes the page down.

## Cached pages: the part most designs get wrong

Three separate problems hide under "does it work with Blitz".

**The URL in the HTML goes stale.** Cached HTML cannot contain
`/caffeine/i/products-v42.json`, because v43 exists ten minutes later and the cached page
still asks for v42. So: the HTML embeds a **stable pointer** (`/caffeine/i/products.json`),
a few hundred bytes, `ETag`-validated and short-cached, which names the current immutable,
far-future-cached payload. Payloads are content-addressed and pruned after N versions, so a
client mid-flight never 404s. The page also carries the version it was rendered against, so
the runtime can notice it is stale and refetch rather than render against a dead index.

**The first paint depends on the query string.** If the static cache key ignores the query
string, a request for `?brand=acme` gets HTML rendered for no refinements. Caffeine's answer
is to make the cached page the canonical unrefined state, hydrate refinements from the URL
before paint via the artifact the runtime already has, and emit `rel="canonical"` at the
unrefined URL so the combinatorial explosion of filter permutations does not get indexed.
Sites that would rather server-render refined states include the query string in the cache
key; the fragment endpoint is cheap enough to leave uncached. Both paths are documented, with
a Blitz recipe, rather than left as an exercise.

**No JavaScript at all.** Facet controls render as real `<a href>` links carrying the
refinement in the query string. With no JS, they are ordinary page loads that server-render
correctly. With the runtime, they are intercepted. HTMX, if the site uses it, is supported by
emitting `hx-get`/`hx-target`/`hx-push-url` on the same elements — Caffeine never depends on
it, because the runtime does its own fetch-and-swap in a few kilobytes.

## The Twig API

```twig
{% caffeine 'products' as search %}

  {{ search.searchBox({ placeholder: 'Search products' }) }}
  {{ search.refinementList('brand', { limit: 10, searchable: true, showMore: true }) }}
  {{ search.hierarchicalMenu('categories') }}
  {{ search.rangeInput('price') }}
  {{ search.currentRefinements() }}
  {{ search.sortBy(['relevance', 'price_asc', 'price_desc']) }}
  {{ search.stats() }}

  {% caffeineresults %}
    {% for hit in search.hits %}
      {% include '_cards/product' with { hit: hit } only %}
    {% endfor %}
    {{ search.pagination() }}
  {% endcaffeineresults %}

{% endcaffeine %}
```

`{% caffeine %}` resolves state from the request, runs the PHP engine once, and exposes
`search`. `{% caffeineresults %}` marks the swap target — the fragment endpoint re-renders
exactly that block, which is why hit markup stays in Twig and works identically in all three
transports. Every widget is a thin wrapper over `search.facet('brand')`, so anyone who wants
their own markup ignores the widgets and reads the state and buckets directly:

```twig
{% for bucket in search.facet('brand').buckets %}
  <a href="{{ search.toggleUrl('brand', bucket.value) }}"
     class="{{ bucket.isRefined ? 'is-on' }}">{{ bucket.label }} ({{ bucket.count }})</a>
{% endfor %}
```

## Artifact format

Pinned down in `docs/ARTIFACT.md`, which is authoritative. In outline:

```
current.json    stable pointer — mutable, uncached, names the shards by content hash
index shard     facets, postings, token index, sortings, sortable values
payload shard   objectIDs and per-record card data
```

Facet values and tokens are interned to integers, and integer lists are stored as base64-wrapped
delta varints rather than JSON arrays. Precompressed `.gz`/`.br` sidecars ship alongside so nginx
or a CDN serves them without recompressing.

**Measured, now that Phase 3 has numbers rather than a budget:** the encoding is 14% smaller than
plain JSON at 1,000 records, 27% at 10,000 and 31% at 100,000, all gzipped, and compiles 100,000
records in about 3 seconds. Client transport stays comfortable to roughly 50k records; beyond
that, endpoint transport.

The sketch here used to claim the payload was "nearly all of the bytes", so that a facet-count
request could be answered from a small fraction of the artifact. Measurement says otherwise — the
payload compresses about twice as well as the index data, so gzipped the index shard is about 70%
of the total. Sharding still earns its place, but not by the margin this document assumed.

## Phases

### Phase 1 — foundation *(done)*
Repo scaffold, `Plugin`, `Settings`, Lite/Pro editions, install migration (`caffeine_records`,
`caffeine_deps`, `caffeine_artifacts`), permissions, CP nav. `IndexDefinition` in project
config, entry `Source` behind an extensible registry, `Mapper` with a dotted path language,
`Records`, `Builder`, and a `caffeine/index` console controller (`status`, `build`, `preview`,
`touch`).

Verified against the plugin-testing harness with two real indexes: one exercising string,
boolean and date facets with sortings and payload, one descending a relation field into a
related entry's title. Facets came out correctly typed, weights summed across attributes as
specified, and the dependency map did its job — marking a related element dirty propagated to
the record that referenced it, and an element nothing depended on moved nothing.

One bug worth recording, because it would have quietly broken every relation-backed facet:
`ValueHelper::flatten()` originally treated anything `Traversable` as a container, and
`yii\base\Model` implements `IteratorAggregate`, so Craft elements were being exploded into
their attribute values. Path descent then found no elements to descend through and every
relation facet came out empty — while the dependency map still looked correct, because the
elements genuinely had been read. Only arrays are containers now; lazy values are resolved
upstream in `Mapper::materialize()`, which is also where dependencies are recorded.

### Phase 2 — the query spec and two engines *(done)*
Written in that order, and the order earned its keep. `docs/QUERY_SPEC.md` came first, then
`search/Engine.php`, then `runtime/src/engine.js`, then the fixtures — and both engines passed
every case on their first run against expectations computed from the spec by hand rather than
from either implementation's output.

Three decisions in the spec turned out to carry most of the weight:

- **Disjunctive facets are counted with their own refinements excluded** (§3.3). It is the rule
  that makes "Globex (12)" keep showing a useful number after the visitor ticks "Acme", and the
  one most implementations get wrong. Conjunctive facets are the opposite and count against
  everything.
- **Hierarchical facets are expanded to their ancestors at build time** (§4), so by query time
  they are ordinary string facets and neither engine knows what a path is.
- **Comparison is by code unit, never locale collation** (§8). `Collator` and `localeCompare`
  disagree with each other and vary by ICU version, so a server and a browser would sort the
  same facet differently — intermittently, on some machines only.

The conformance suite is the executable form of the spec: the PHP pass compiles each fixture
into a real artifact and the JavaScript pass consumes that same artifact, mirroring production,
where compilation only ever happens in PHP. `composer conformance` runs both. A separate
tokenisation fixture pins §7 across 21 inputs, because query tokenisation is the one piece of
logic that genuinely exists in both languages and can drift in silence.

**79 PHP tests / 136 assertions and 43 JavaScript checks pass, with identical results.**

### Phase 3 — artifact build and publish *(done)*
`Varint`, `ArtifactEncoder`/`ArtifactDecoder` and their JavaScript twins, `Publisher` over a
two-implementation store (local `@webroot`, or any Craft filesystem for S3/CDN), the `Artifacts`
service, and a `caffeine/artifact` console controller. Format documented in `docs/ARTIFACT.md`.

The decision that shaped the phase was to leave both engines alone. `Compiler` still produces the
shape they query; encoding is a separate layer either side of it, and the browser decodes once on
load into exactly the structure the PHP engine holds. Phase 3 changed how an artifact is stored
without changing a line of how it is queried.

That only works if `decode(encode(a))` is *exactly* `a`, which is now asserted against every
fixture — and the conformance suite runs every case twice, once on the compiled artifact and once
on one that has been through the wire format. Two things fell out of taking that literally:

- **Weights are quantised in the compiler, not the encoder** (3 dp). Rounding once at compile
  time makes the quantised value the only value either engine ever sees.
- **`JSON_PRESERVE_ZERO_FRACTION` is mandatory.** Without it PHP writes `float(1775403180.0)` as
  `1775403180` and reads it back as an *int*, so a numeric sortable value changes type in transit.
  JavaScript cannot tell the two apart, so this was invisible in the browser and surfaced only
  when `caffeine/artifact/verify` compared a published artifact against a fresh compile — which is
  why that command exists.

Publishing rests on two properties held apart: payloads are **immutable and content-addressed**,
so their URLs can be cached forever; the pointer is **stable and mutable**, so cached HTML that
embeds it still finds today's index months later. Shards are written before the pointer that
names them, so an interrupted publish leaves collectable orphans rather than a live pointer to a
missing file. Content addressing also means versions routinely share shards, so pruning is
per-file against everything still retained rather than per-version.

An identical rebuild writes nothing, spends no version and leaves the pointer's timestamp alone.

Verified in the plugin-testing harness end to end: published, fetched over HTTP, decoded by
`decode.js` and queried by the JavaScript engine to the same hits and facet counts the PHP engine
gives. `tests/integration/publish-checks.php` covers sharded publishing, orphan pruning,
shared-shard retention and idempotence.

One performance bug worth recording, found only because the size benchmark went to 100,000
records: `Compiler` iterated `$sortableValues` with `foreach` while assigning into it, so PHP
snapshotted the whole nested array once per record and the build went quadratic. It cost 60
seconds at 100k and was invisible at 10k. Hoisting the keys out of the loop took it to 2.9
seconds — the same output, 20× faster.

### Phase 4 — Twig and transports *(done)*
`UrlState` and `FacetValue` with their JavaScript twins, `Search` and `SearchContext`, the two
Twig tags, eight widgets, `FragmentController`, `SearchController`, and `caffeine.js`. Documented
in `docs/TWIG.md`.

The ordering rule that shaped everything: **the server renders controls that already work, and
the runtime only intercepts them.** Every facet is a real `<a href>`, every widget a real form.
Turning JavaScript off degrades to page loads, not to nothing — and it is why the runtime is a
few kilobytes and needs no framework.

Two decisions were forced by building it:

- **The URL codec is the fourth cross-language pair**, after the tokeniser, the varint codec and
  the value projection. The server renders the hrefs and the browser parses them, so a single
  character of disagreement is a link that works without JavaScript and breaks with it.
  `urlstate.json` pins 33 cases, including escaping, unicode and RFC 3986 encoding.
- **Swapping only the results block is not enough.** Refining changes the facet counts beside the
  results, and leaving "Acme (12)" next to three results is the exact thing that makes
  hand-rolled filtering feel broken. So the capture mechanism behind `{% caffeineresults %}` was
  generalised: every state-dependent widget records itself as a named region, and the fragment
  endpoint returns the lot as JSON. Plain HTML is still served to anything asking for it, which
  is what the HTMX path uses.

Verified in the harness with JavaScript on and off: refining, clearing, text search, sorting and
paging all server-render correctly as plain page loads; and in Chrome, a facet click swaps
results *and* sibling facet counts *and* stats *and* chips with no reload, back and forward
restore both, typing keeps focus and caret, and a simulated full-page-cache hit — unrefined HTML
served at `?choice=red` — detects the mismatch and refines before paint.

Bugs worth recording, all found by running it rather than reading it:

- **Twig resolves `search.currentRefinements` to a method of exactly that name before it tries
  `getCurrentRefinements()`.** The widget's own template read the value, so it re-entered itself
  until the stack overflowed — a SIGSEGV and a 502, not an exception. The accessor is
  `getRefinements()` now.
- **`token` is reserved by Craft** for preview and share tokens; the request is rejected before
  any controller runs. Ours is `caffeineToken`.
- **devMode turns on `strict_variables`**, so a bare `options.label` throws where `options.label ??
  null` does not.
- **Changing a Twig `Node::compile()` does not invalidate compiled templates** — they are cached
  against the *source* template's mtime. `clear-caches/compiled-templates` after every node edit.
- **`Craft::$app->getRequest()` is a console request outside the web**, with no `getQueryParams()`.
  Anything rendering a template from a command, a job, or the CP has to guard for it.

### Phase 5 — auto-update and scale *(done)*
`AutoUpdate`, `UpdateJob`, orphan garbage collection, and the benchmarks. Numbers published in
the README.

The rule the whole phase follows: **mark, never build.** An element save records that some rows
are stale and schedules work; it never maps a record and never publishes. Doing either inline
would put an artifact build inside the request that saved an entry — the exact cost the plugin
exists to remove.

Marking is per element and cheap. *Scheduling* is what gets coalesced, and it needed two
mechanisms rather than one: `BulkOpEvent::defer()` (the method is `defer()`, not `deferredOn()`)
suppresses scheduling for the duration of a bulk operation and fires once at the end, and outside
one a per-index debounce window means an editor fixing six typos gets one rebuild rather than six.
Publishing is mutex-guarded, and a job that loses the lock requeues rather than dropping its work.

Two gaps the design had, found by building it:

- **A newly created element has no row to mark.** The handlers mark dirty and never build, so
  nothing would ever notice it. `touchElement()` writes a keys-only stub with no content, and
  `stream()` skips rows with no content — so a publish landing between the stub and the build
  cannot serve a blank hit.
- **Records were only ever cleaned up when an index was deleted *through the service*.** An index
  whose uid changed — a restored project config, a re-run seeding script — orphaned its records
  and left its artifacts being served out of the web root with nothing pointing at them. The
  harness had three such sets. Now collected on `Gc::EVENT_RUN`.

**Measured** (`tests/bench/`, synthetic catalogue, 2023 Apple Silicon in Docker):

| Records | Build | Artifact gz | PHP unrefined | JS unrefined | Browser decode |
| ---: | ---: | ---: | ---: | ---: | ---: |
| 1,000 | 13 ms | 56.9 KB | 1.4 ms | 0.7 ms | 5 ms |
| 10,000 | 183 ms | 535.4 KB | 8.1 ms | 3.5 ms | 23 ms |
| 100,000 | 2.9 s | 5.21 MB | 128 ms | 33 ms | 165 ms |

Narrowing is nearly free — the more refined the query, the less work either engine does. The
expensive case is the *unrefined* listing, which touches every record, and it is what sets the
transport budget: `client` is comfortable to roughly 50k records, and past that `htmx` and a
server-side answer. The 100k row is the honest ceiling for this design without the memory-mapped
endpoint transport.

### Phase 6 — the CP *(done)*
`IndexesController`, `SettingsController`, and five templates: the index list with health, the
definition builder, the record preview, the query playground and the settings screen. The console
commands this phase listed already exist from Phases 1 and 3, better organised than planned —
`caffeine/index/*` for records and `caffeine/artifact/*` for artifacts, rather than four verbs at
the top level.

Two decisions worth recording:

- **The form parser lives on the model, not the controller.** `IndexDefinition::fromForm()` is
  testable without a request, and it starts from the *existing* definition rather than a blank
  one — the screen renders the common per-attribute settings and not the rest, and a form that
  rebuilt each attribute from scratch would silently reset value ordering, numeric buckets,
  transforms and hierarchy separators on the first save. Project config and the CP would then be
  permanently at odds, with the CP always winning.
- **The playground queries the published artifact, not a fresh compile.** The question it answers
  is "what are visitors getting"; a playground that quietly recompiled would answer a different
  one.

Four bugs, all found by driving the screens rather than reading them:

- **A nested `<form>` in the sidebar made Save delete the index.** `{% block details %}` renders
  inside Craft's full-page form, and a nested form is invalid HTML — the parser drops the inner
  tag and keeps its children, so the delete action's hidden input joined the page form. With two
  `action` inputs Craft took the last one. The delete posts from JavaScript now.
- **Yii skips inline validators on empty values**, so `validateSources` and `validateAttributes` —
  the rules that exist precisely to reject "nothing configured" — never ran. An index with no
  attributes saved happily and published nothing. Fixed with `skipOnEmpty => false`.
- **Nothing stopped two indexes sharing a handle.** `all()` is keyed by handle, so one silently
  shadowed the other while both published to the same directory, each overwriting the other's
  pointer. `Indexes::save()` refuses it now.
- **Deleting an index left its published directory behind**, empty and permanent. `LocalStore`
  removes a directory once its last file goes.

Verified in Chrome as an admin, through an impersonation URL rather than a password: every screen
renders, a save round-trips to a byte-identical definition (records stay clean, so project config
records no change at all), preview and playground return real data, rebuild queues the same job
the element events do, publish cuts a version, settings persist, and both validation rules bite.

### Phase 7 — coverage and polish *(done)*
Five more sources, the extractor registry, stopwords, synonyms, geo facets, and the last three
documents.

**Sources.** Categories, tags, assets, users and Commerce products, all through the existing
registry. One shared bug across three of them: `live` is an entry concept, and asking a category,
asset or user query for it returns *nothing at all* rather than erroring — an empty index with no
explanation. `BaseSource::statusFor()` now maps the three choices onto whatever the element type
actually has, and `coversStatus()` answers the same question per element for the removal pass.

**Extractors.** The registry matches on **shape, not class name**, deliberately: half the field
types worth handling belong to plugins Caffeine cannot depend on, and a link field is a link field
whether it came from Hyper, FreeLink, Craft's own Link field or something written last week. Six
built-ins, checked after anything a plugin registers.

`ExtractedValue` carries a **primary** and named **parts**, and that split is what makes it work:
a facet wants one scalar (a link's URL, a price's amount) while `venue.city` wants a named part. A
plain associative array could not serve both — `flatten()` would explode it and a facet would list
the label and the URL as two separate options.

Verified against the four real field types in the harness — FreeLink, Hyper, Google Maps and a
Dropdown — which caught a specificity bug: `OptionExtractor` matched on "has `value` and `label`",
which a FreeLink link model also has, so links were read as dropdown options and `link.url`
resolved to nothing while `link.value` quietly worked. It requires `selected` now.

**Stopwords and synonyms.** Stopwords are removed from documents *and* queries, and the symmetry
is the requirement rather than a nicety: matching is conjunctive, so removing "the" from documents
alone would make a search for `the saw` match nothing. The list therefore ships **inside the
artifact** — the browser cannot read project config, and a list that differed between the engines
would make the same search return different results on each. A query that is entirely stopwords is
an empty query, not an empty result.

Synonyms expand at **index time only**. Neither engine learns what a synonym is, and there is no
second map to keep in step across two languages.

**Geo facets.** A fifth cross-language pair, and the one most exposed to floating point. Distance
is haversine on the IUGG mean radius, **rounded to whole metres and compared as an integer** —
`sin`/`cos`/`sqrt` are not guaranteed to agree to the last bit between PHP and a JavaScript
engine, and a record sitting exactly on the radius could otherwise be inside it on the server and
outside it in the browser. The fixtures pin that: one case at exactly 160,139 m and one at
160,138 m, and both engines agree on which side each record falls.

Geo never enters the interning-and-postings machinery — one facet value per record with a postings
list of one would be all cost and no use — so it lives beside the records, is filtered by radius,
and produces no buckets. `sortBy: distance` is the only sorting that cannot be precomputed,
because the point it measures from is the visitor's. A radius of zero filters nothing and exists
so a listing can be ordered by distance without being narrowed.

**Docs.** `EXTENDING.md`, `MIGRATING-FROM-SPRIG.md` and `CACHING.md` (the Blitz recipe), with
`README.md`, `TWIG.md` and `QUERY_SPEC.md` updated. The Sprig guide states the trade plainly
rather than selling past it: Caffeine serves what was published, not what is in the database this
millisecond, and for anything where a visitor must see their own write immediately, Sprig is still
the better tool.

## Decisions locked, 2026-08-01

All four open questions are settled and the table above reflects them:

1. **Wire format** — Algolia response shape, Caffeine's own runtime bundled, InstantSearch
   supported but not a dependency.
2. **Full-text** — inverted index built in PHP at index time and shipped inside the artifact.
3. **Engine parity** — spec-first, two implementations, one shared conformance fixture suite.
   Confirmed as the approach; Phase 2 does not start with code.
4. **Transports** — all three built as per-index choices: `client`, `algolia-json`, `htmx`.
5. **Editions** — free Lite, Pro at $149 / $119 renewal.
6. **CP vocabulary** — "Indexes".

## Lite / Pro boundary *(enforced 2026-08-14)*

Every edition check goes through `Plugin::isPro()`, as in RedPen, and `models/Edition.php` is the
single description of what each edition allows — pure and static, so the boundary can be read in
one file and tested without an application.

An audit found only two of the seven rows below were actually enforced: the index cap and the
source list. Facet types, transports, sorting counts, word lists and the CP tools were all
documented as Pro and freely available on Lite.

Two mechanisms now enforce it, doing deliberately different jobs:

- **`Indexes::save()` refuses** a configuration Lite cannot run, reporting every problem at once.
  An operator who configured a numeric facet is told it needs Pro, rather than being given a
  string facet and left wondering why the ranges do nothing.
- **`Indexes::forEdition()` downgrades** on the way into the build and the query — the two funnels
  every definition passes through. This is for the lapsed licence: an exception on a listing page
  is an outage, so the index keeps working with the Lite feature set instead. The *stored*
  definition is untouched and the CP keeps showing it, so renewing restores exactly what was
  there.

A facet of an unsupported type is dropped rather than reinterpreted: treating a geo facet as a
string facet would give one bucket per record — visibly broken, and harder to diagnose than a
facet that is simply absent.

`tests/integration/edition-checks.php` switches the harness to Lite for real and back again.

| Capability | Lite | Pro |
| --- | --- | --- |
| Indexes | 1 | Unlimited |
| Sources | Entries | All element types + Commerce |
| Facet types | string, boolean | + hierarchical, numeric, date, geo |
| Transports | `htmx` | All three |
| Sortings | Relevance + one | Unlimited replicas |
| Synonyms, stopwords, custom ranking | — | Yes |
| Query playground, artifact inspector | — | Yes |

The tools row is checked on the server as well as hidden in the template: a hidden button is a
courtesy, not a boundary, and those two actions run a mapper and a query engine on demand.
