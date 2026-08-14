# Migrating from Sprig

The pattern this replaces: a Sprig component that runs an element query per interaction, plus a
hand-rolled loop that counts facet values by running another query per value.

It is not a bad pattern — it is the only one Craft offers out of the box — but it has a shape
that gets worse exactly when a visitor is exploring fastest. Every refinement is a round trip,
and every count in the sidebar is its own query.

This guide is a straight translation. Nothing here is subtle; the work is in the index
definition, and the template usually gets shorter.

---

## 1. What changes conceptually

| Sprig | Caffeine |
| --- | --- |
| An element query per interaction | One artifact, compiled at index time |
| Facet counts by counting queries | Counts computed from postings lists |
| `sprig.pageUrl`, `s-val:` params | Real `<a href>`s carrying the state |
| Component re-renders server-side | Server renders first paint, browser refines |
| Fresh data on every request | Data as of the last publish |

That last row is the real trade, and it should be a deliberate one. Caffeine serves what was
published, not what is in the database this millisecond. Saves mark records stale and a debounced
job republishes — 30 seconds by default. For a product listing or an article archive that is
invisible. For anything where a visitor must see their own write immediately, it is wrong, and
Sprig is still the better tool.

---

## 2. A worked example

### Before

```twig
{# _components/products.twig #}
{% set query = craft.entries.section('products') %}

{% if brand %}{% do query.brand(brand) %}{% endif %}
{% if maxPrice %}{% do query.price("< #{maxPrice}") %}{% endif %}
{% if search %}{% do query.search(search) %}{% endif %}

{% set products = query.paginate(page, 24) %}

<input type="search" sprig s-val:search value="{{ search }}">

<ul>
  {% for option in craft.entries.section('products').groupBy('brand').all() %}
    {# One count query per brand. This is the line that hurts. #}
    {% set count = craft.entries.section('products').brand(option.brand).count() %}
    <li>
      <a sprig s-val:brand="{{ option.brand }}">{{ option.brand }} ({{ count }})</a>
    </li>
  {% endfor %}
</ul>

{% for product in products %}
  {% include '_cards/product' with { entry: product } %}
{% endfor %}
```

### After

```twig
{% caffeine 'products' as search %}

  {{ search.searchBox() }}
  {{ search.refinementList('brand') }}
  {{ search.rangeInput('price') }}

  {% caffeineresults %}
    {{ search.stats() }}

    {% for hit in search.hits %}
      {% include '_cards/product' with { hit: hit } only %}
    {% endfor %}

    {{ search.pagination() }}
  {% endcaffeineresults %}

{% endcaffeine %}
```

The counts come for free, because they fall out of the same intersection that produced the
results. So does the URL, the back button and the no-JavaScript behaviour.

---

## 3. Rewriting the card

This is the only part that takes real thought. A Sprig component hands your card an **element**;
Caffeine hands it a **hit**, which is a plain array of whatever the index marked `payload`.

```twig
{# before #}
<h3>{{ entry.title }}</h3>
<img src="{{ entry.image.one().url }}">
<p>{{ entry.summary }}</p>
<a href="{{ entry.url }}">More</a>

{# after — every one of these is a key you added as payload #}
<h3>{{ hit.title }}</h3>
<img src="{{ hit.image.url }}">
<p>{{ hit.summary }}</p>
<a href="{{ hit.url }}">More</a>
```

The index definition is where you decide what a card needs:

| Card needs | Attribute | Roles |
| --- | --- | --- |
| `entry.title` | `title` from attribute `title` | searchable, sortable, payload |
| `entry.url` | `url` from attribute `url` | payload |
| `entry.image.one().url` | `image` from field `image` | payload |
| `entry.summary` | `summary` from field `summary` | searchable, payload |

An asset in the payload arrives as `{ id, title, url, alt, width, height }`, and a related entry
as `{ id, title, slug, url }` — enough to render a card without touching the database, which is
the point.

**If your card genuinely needs the element** — a complicated Matrix render, an eager-loaded
relation three levels down — load it: `craft.entries.id(hit.objectID|split('-')|first).one()`.
That reintroduces a query per hit, so it is worth restructuring the payload instead. But it works,
and it is a reasonable first step when porting.

---

## 4. Translating the query API

| Sprig / element query | Index definition |
| --- | --- |
| `.section('products')` | a source with container `products` |
| `.type('physical')` | that source's types |
| `.brand('acme')` | attribute `brand`, role `facet` |
| `.price('< 50')` | attribute `price`, role `facet`, type `numeric` → `rangeInput` |
| `.search('saw')` | attribute with role `searchable` |
| `.orderBy('price asc')` | a sorting named `price_asc` |
| `.relatedTo(category)` | attribute with path `category.title`, role `facet` |
| `.postDate('> 2024')` | attribute `postDate`, role `facet`, type `date` |

Relations are worth a note. Where Sprig does `.relatedTo(x)`, Caffeine **denormalises**: the
category's title is copied into the record at build time. That is what makes the count instant,
and it is why `caffeine_deps` exists — renaming the category marks every record that copied it
as stale, so the artifact never serves a label that no longer exists.

---

## 5. Keeping Sprig for part of the page

Nothing stops you. Caffeine is a listing; Sprig is a component framework. A page can have a
Caffeine listing and a Sprig add-to-cart button, and they will not interact — Caffeine only
intercepts links pointing back at its own path.

---

## 6. A migration order that works

1. Define the index in the control panel. Use the **record preview** on the edit screen to check
   one real element maps the way you expect — it is much faster than rebuilding and reading the
   artifact.
2. `php craft caffeine/index/build --all` and `php craft caffeine/artifact/publish`.
3. Use the **query playground** to confirm the facets count the way the old page did.
4. Port the template, keeping the old one alongside until the numbers match.
5. Delete the Sprig component.

Step 3 is the one people skip and regret. Facet counts are where a definition's mistakes show up,
and comparing them against the page you already trust is the cheapest correctness check you will
get.
