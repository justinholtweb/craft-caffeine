# The Twig API

Everything Caffeine puts in a template, and what it emits.

The shape of it: `{% caffeine %}` runs one search and exposes it; `{% caffeineresults %}` marks
the part that changes. Every control it renders is a real link or a real form that works with
JavaScript switched off, and the runtime intercepts them so the page updates without a reload.
That order matters — every behaviour in the runtime is an optimisation of something that already
works, which is why it fits in a few kilobytes and why turning it off degrades to page loads
rather than to nothing.

---

## 1. The tags

```twig
{% caffeine 'products' as search %}

  {{ search.searchBox({ placeholder: 'Search products' }) }}
  {{ search.currentRefinements() }}
  {{ search.refinementList('brand', { limit: 10 }) }}
  {{ search.hierarchicalMenu('category') }}
  {{ search.rangeInput('price') }}
  {{ search.sortBy(['relevance', 'price_asc', 'price_desc']) }}

  {% caffeineresults %}
    {{ search.stats() }}

    {% for hit in search.hits %}
      {% include '_cards/product' with { hit: hit } only %}
    {% endfor %}

    {{ search.pagination() }}
  {% endcaffeineresults %}

{% endcaffeine %}
```

`{% caffeine %}` resolves the state from the query string, runs the PHP engine once, and wraps
its body in an element carrying the configuration the runtime reads back. `as search` is
optional and defaults to `search`.

Options go in a `with` hash:

```twig
{% caffeine 'products' with { prefix: 'p_', tag: 'section', class: 'listing' } as products %}
```

| Option | Default | What it does |
| --- | --- | --- |
| `prefix` | `''` | Namespaces every query parameter, so two indexes on one page do not collide. |
| `path` | current URL | What the facet links point back at. |
| `tag` | `div` | Wrapper element. `false` omits it — and with it, the runtime. |
| `class`, `id` | — | Put on the wrapper. |
| `runtime` | `true` | `false` leaves the JavaScript off entirely; the links still work. |
| `htmx` | `false` | Emit HTMX attributes instead of loading the runtime. See §5. |
| `with` | `{}` | Extra variables the fragment endpoint should pass back into the template. |

`{% caffeineresults %}` marks the region a refinement replaces. It has to be inside
`{% caffeine %}`, and there should be one per index.

There is also a function, for when a search belongs in a `{% set %}` rather than wrapped around
half a template:

```twig
{% set search = caffeine('products') %}
```

It returns the same object, but nothing is wrapped and no runtime is registered — so the page is
server-rendered only, and every control is an ordinary link.

---

## 2. What `search` gives you

### Results

| | |
| --- | --- |
| `search.hits` | The page of results. Each is the record's payload plus `objectID`. |
| `search.nbHits` | Total matching records. |
| `search.page` / `search.nbPages` | Zero-based. |
| `search.hitsPerPage` | |
| `search.from` / `search.to` | One-based positions, for "showing 25–48 of 137". |
| `search.query` | |
| `search.isEmpty` | |
| `search.processingTimeMS` | |

### Facets

`search.facets` is every facet the index defines; `search.facet('brand')` is one:

```twig
{% for bucket in search.facet('brand').buckets %}
  <a href="{{ bucket.url }}" class="{{ bucket.isRefined ? 'is-on' }}">
    {{ bucket.label }} ({{ bucket.count }})
  </a>
{% endfor %}
```

| Facet | |
| --- | --- |
| `key`, `label`, `type`, `operator` | |
| `isRefined` | Whether anything in it is refined. |
| `buckets` | In the order QUERY_SPEC §3.3.1 defines. |
| `stats` | `min`, `max`, `avg`, `sum` for numeric and date facets. |

| Bucket | |
| --- | --- |
| `value` | The real, typed value — a boolean facet gives `true`, not `"true"`. |
| `key` | Its string projection, as it appears in a URL. |
| `label` | Display text. Dates are formatted; booleans become Yes/No; a hierarchical path is trimmed to its leaf. |
| `count`, `isRefined` | |
| `url` | The URL that toggles it. |

`search.refinements` is every active refinement flattened, each with the URL that removes it,
and `search.hasRefinements` says whether there are any.

### URLs

Each returns the URL of the state that would result — never one that needs JavaScript to mean
anything. All of them reset to the first page, because a visitor who narrows from 200 results to
3 while on page 7 should not be shown nothing.

```twig
{{ search.toggleUrl('brand', 'Acme') }}
{{ search.rangeUrl('price', 10, 50) }}   {# omit both to clear it #}
{{ search.geoUrl('near', 35.2271, -80.8431, 8000) }}  {# metres; omit all to clear #}
{{ search.sortUrl('price_asc') }}
{{ search.pageUrl(2) }}
{{ search.queryUrl('cordless') }}
{{ search.clearUrl('brand') }}           {# omit the facet to clear everything #}
{{ search.canonicalUrl }}                {# the unrefined URL — see §6 #}
```

---

## 3. Widgets

Every widget is a thin wrapper over the methods above. They exist so a listing can be built in
five lines, not so anyone is obliged to use them.

| Widget | |
| --- | --- |
| `searchBox({ placeholder, label })` | A GET form. Carries the active refinements as hidden fields, so typing does not clear them. |
| `refinementList(facet, { limit, label })` | Values past `limit` go inside a `<details>`, which opens without JavaScript. |
| `hierarchicalMenu(facet, { maxDepth, label })` | Children shown under an open branch. |
| `rangeInput(facet, { step, label })` | Two number inputs. See §4. |
| — (geo) | Geo facets have no widget: they need a point from the visitor, which only your page knows how to collect. Build the control yourself and link to `search.geoUrl()`. |
| `currentRefinements({ clearAll, clearLabel })` | Removable chips. |
| `sortBy(names, { label })` | A select plus a submit button the runtime hides. |
| `stats({ timing, emptyLabel })` | |
| `pagination({ window, previousLabel, nextLabel })` | |

**Overriding the markup.** Put a template of the same name under `_caffeine/` in your own
templates directory — `_caffeine/refinement-list.twig` — and Caffeine renders yours instead. It
receives the same variables: `search`, `options`, and the widget's own (`facet`, `tree`, `range`,
`sortings`, `pages`).

Facet links carry `rel="nofollow"`. A faceted listing generates a combinatorial explosion of
URLs, and inviting a crawler into all of them is how a site ends up with 40,000 near-duplicate
pages.

---

## 4. The URL

State travels in a query string built to be read:

```
?q=cordless&brand=Acme,Globex&price=10..50&sort=price_asc&page=2
```

- Values are comma-separated. A literal comma or backslash in a value is backslash-escaped.
- Ranges use `..`, open at either end: `price=10..`, `price=..50`.
- `page` is one-based.
- Anything at its default is omitted, so the unrefined state is a bare URL.

`src/search/UrlState.php` and `runtime/src/url.js` implement this, and
`tests/Conformance/urlstate.json` pins them against each other. The server renders the hrefs and
the browser parses them, so a single character of disagreement would mean a link that works
without JavaScript and breaks with it.

**One exception, on the way in only.** A plain HTML form posts one value per field and cannot
produce `price=10..50`, so `price_min` / `price_max` are also accepted when parsing. Nothing ever
encodes them, so the next click restores the canonical URL. This is what lets the range widget
work without JavaScript.

---

## 4a. Geo facets

An attribute whose facet type is `geo` holds a coordinate pair — point its path at an address
field and the extractor supplies `lat`/`lng` — and is filtered by distance rather than by value.

```twig
{# A few fixed radii around a point the page already knows. #}
{% for km in [5, 10, 25] %}
  <a href="{{ search.geoUrl('near', shop.lat, shop.lng, km * 1000) }}">Within {{ km }} km</a>
{% endfor %}

{# Nearest first, without narrowing: a radius of zero filters nothing. #}
<a href="{{ search.geoUrl('near', lat, lng, 0) }}&sort=distance">Nearest first</a>
```

Three things to know:

- **Radius is in metres**, and the URL carries `near=35.2271,-80.8431,8000`.
- **A radius of zero filters nothing.** It exists so a listing can be ordered by distance without
  also being narrowed — sorting needs a point even when filtering does not.
- **Geo facets have no buckets**, so no `refinementList`. They appear in `currentRefinements` as a
  removable "Within 8 km" chip, and they constrain every other facet's counts exactly as any other
  refinement does.

`sortBy: "distance"` is the only sorting not precomputed into the artifact, because the point it
measures from is the visitor's. Records with no coordinates sort last and never match a radius.

---

## 5. Transports

Set per index in the control panel.

**`htmx`** (default) — a refinement fetches `/caffeine/fragment`, which re-renders the *same
template* with the same element in context and returns the regions that changed. Hit markup stays
in Twig; the runtime does its own fetch and swap, so HTMX is not a dependency.

Passing `{ htmx: true }` to the tag instead emits `hx-boost` on the wrapper and leaves the
runtime off, for sites already running HTMX. That fetches the page and selects the results block
out of it, so facet counts elsewhere on the page keep their previous numbers — put the widgets
inside `{% caffeineresults %}` if that matters, or use the bundled runtime, which swaps every
region.

**`client`** — the browser fetches the artifact once and answers every refinement locally, with
no request at all. Hits are rendered from a template on the page:

```twig
<template data-caffeine-hit>
  <li><a href="{{ '{{ url }}' }}">{{ '{{ title }}' }}</a></li>
</template>
```

`{{ field }}` is interpolated with escaping, and dotted paths work. Facet counts are patched in
place on the server-rendered controls — sound rather than merely convenient, because a cached
page is rendered unrefined and an unrefined result contains every value a facet has, so refining
can only ever reduce a count. The exception is a facet truncated by `maxValuesPerFacet`.

**`algolia-json`** — exposes `/caffeine/search/<handle>` returning the Algolia response shape, for
InstantSearch, React/Vue InstantSearch, Autocomplete.js, or your own front end. Only indexes set
to this transport answer; the endpoint 403s otherwise, so an index is never published as JSON by
accident.

---

## 6. Cached pages

Three separate problems, answered separately.

**The URL in the HTML goes stale.** The page embeds the *stable pointer* — `current.json` — and
never a versioned URL. It is a few hundred bytes and must be served uncached; everything it names
is immutable and cached forever. See ARTIFACT.md §1.

**The first paint depends on the query string.** If the static cache key ignores the query string,
a request for `?brand=Acme` gets HTML rendered for no refinements. The runtime notices that the
URL and the rendered state disagree and refines before paint, setting `data-caffeine-hydrating`
on the wrapper while it does — hook a style onto that if you want to hide the transition:

```css
[data-caffeine-hydrating] [data-caffeine-results] { opacity: .4 }
```

Emit `rel="canonical"` at `search.canonicalUrl` so the permutations do not get indexed. Sites that
would rather server-render refined states should include the query string in the cache key
instead; the fragment endpoint is cheap enough to leave uncached either way.

**No JavaScript at all.** Every control is a real `<a href>` or a real form. With the runtime
absent they are ordinary page loads that server-render correctly.

---

## 7. Events

The wrapper dispatches, bubbling:

| Event | When |
| --- | --- |
| `caffeine:ready` | The instance is wired. `detail.caffeine` is it. |
| `caffeine:render` | A refinement has been applied and swapped in. `detail.url`. |

And carries `data-caffeine-busy` while a request is in flight, and `data-caffeine-hydrating`
during the cached-page catch-up described above.
