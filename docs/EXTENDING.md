# Extending Caffeine

Three extension points, in the order you are likely to need them: teach it a **field type**, give
it a new **element type**, or replace its **markup**. All three are events or files — none of them
require forking the plugin.

---

## 1. Value extractors — teach it a field type

The problem they solve: a field whose value is an object indexes as whatever `__toString()`
happens to give. Often that is the class name, sometimes an empty string, and the facet built from
it is useless in a way that is hard to diagnose from the outside.

An extractor turns that object into a **primary** — the scalar the value stands for, used by
facets and sorting — and named **parts**, which a dotted path can reach:

```twig
{{ hit.venue.city }}                     {# a part #}
{{ search.refinementList('venue') }}     {# the primary #}
```

### Built-ins

Matched by **shape, not by class name**, deliberately: half the field types worth handling belong
to plugins Caffeine cannot depend on, and a link field is a link field whether it came from Hyper,
FreeLink, Craft's own Link field or something written last week.

| Extractor | Matches | Primary | Parts |
| --- | --- | --- | --- |
| `CollectionExtractor` | `getAll()` or `all()` | the list | — |
| `AddressExtractor` | has `lat` and `lng` | the formatted address | `lat`, `lng`, `street1`, `city`, `state`, `zip`, `country`, `formatted` |
| `MoneyExtractor` | `getAmount()` + `getCurrency()` | the amount in major units | `amount`, `minor`, `currency` |
| `ColorExtractor` | `getHex()` + `getRgb()` | the hex | `hex`, `rgb` |
| `OptionExtractor` | `value` + `label` + `selected` | the stored value | `value`, `label` |
| `LinkExtractor` | `getUrl()` | the URL | `url`, `text`, `type` |

Order matters and is fixed: collections unwrap first so everything after sees a single item, and
`LinkExtractor` runs last because it matches on little more than "has a `getUrl()`", which is true
of more things than are actually links.

Two rows are worth reading twice. `OptionExtractor` requires `selected` as well as `value` and
`label` — without it, a link model carrying `value` and `label` (FreeLink's does) is read as a
dropdown option, and `link.url` resolves to nothing while `link.value` quietly works. And
`MoneyExtractor` divides out the currency's subunit scale, because money is stored in minor units
and a price facet that is wrong by two orders of magnitude still looks plausible.

### Writing one

```php
use justinholtweb\caffeine\extractors\ExtractedValue;
use justinholtweb\caffeine\extractors\ValueExtractorInterface;

class RatingExtractor implements ValueExtractorInterface
{
    public static function supports(object $value): bool
    {
        // Never assume the class exists — this runs on every site, including ones without your
        // plugin. Guard with class_exists(), or duck-type the methods you are going to call.
        return $value instanceof \acme\ratings\models\Rating;
    }

    public function extract(object $value): ?ExtractedValue
    {
        return new ExtractedValue($value->stars, [
            'stars' => $value->stars,
            'count' => $value->reviewCount,
            'label' => $value->label(),
        ]);
    }
}
```

Register it. Yours are checked **before** the built-ins, so this is also how you override the way
one of them reads your field:

```php
use justinholtweb\caffeine\events\RegisterExtractorsEvent;
use justinholtweb\caffeine\services\Extractors;
use yii\base\Event;

Event::on(Extractors::class, Extractors::EVENT_REGISTER_EXTRACTORS, function(RegisterExtractorsEvent $event) {
    $event->extractors[] = RatingExtractor::class;
});
```

Return `null` from `extract()` to decline a value you turn out not to understand — the next
extractor gets a turn. An extractor that throws is skipped and logged rather than failing the
build: one bad value in one record should not stop an index rebuilding.

---

## 2. Sources — teach it an element type

A source supplies the elements an index is built from. Caffeine ships six — entries, categories,
tags, assets, users and Commerce products — and a third-party one is configured in the control
panel exactly like they are.

```php
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use justinholtweb\caffeine\models\SourceDefinition;
use justinholtweb\caffeine\sources\BaseSource;

class SubmissionSource extends BaseSource
{
    public static function handle(): string { return 'submission'; }
    public static function displayName(): string { return 'Form submissions'; }
    public static function elementType(): string { return Submission::class; }

    public static function isAvailable(): bool
    {
        // Unavailable sources are dropped from the registry rather than listed and broken, so
        // the CP never offers one that cannot work on this site.
        return Craft::$app->getPlugins()->isPluginEnabled('formie');
    }

    /** Shown in the CP as the handles a definition can be scoped to. */
    public function containerOptions(): array
    {
        return ['contact' => 'Contact form'];
    }

    public function query(SourceDefinition $definition, int $siteId): ElementQueryInterface
    {
        $query = Submission::find()
            ->siteId($siteId)
            // Ordered by id so a build walks records in a stable order across runs. Anything
            // that changes between builds would reshuffle batches and make an interrupted build
            // hard to resume.
            ->orderBy(['elements.id' => SORT_ASC]);

        if ($definition->containers !== []) {
            $query->form($definition->containers);
        }

        return $this->applyStatus($query, $definition);
    }

    public function covers(SourceDefinition $definition, ElementInterface $element): bool
    {
        return $element instanceof Submission && $this->coversStatus($definition, $element);
    }
}
```

```php
Event::on(Sources::class, Sources::EVENT_REGISTER_SOURCES, function(RegisterSourcesEvent $event) {
    $event->sources[] = SubmissionSource::class;
});
```

Three things are easy to get wrong:

**`covers()` is not optional, and it asks a different question from `query()`.** The build
re-loads changed elements *without* the status filter, precisely so it can notice one that has
just stopped qualifying and remove it. `covers()` is that check, per element. An index whose
source answers it carelessly grows and never shrinks.

**Override `statusFor()` if your element type has no `live` status.** `live` is an entry concept.
Asking a category, asset or user query for it returns nothing at all rather than erroring — an
empty index with no explanation.

**Anything beyond entries is Pro.** The registry drops non-entry sources on Lite, so your source
is available exactly when the licence allows it, with no work on your part.

---

## 3. Markup — replace the widgets

Every widget renders a template. Put one of the same name under `_caffeine/` in your own templates
directory and Caffeine uses yours instead, with the same variables:

```
templates/_caffeine/refinement-list.twig
templates/_caffeine/pagination.twig
```

Each receives `search`, `options`, and its own variables — `facet`, `tree`, `range`, `sortings`,
`pages`. See `docs/TWIG.md` §3 for the full list.

Two rules keep the runtime working:

- **Facet controls must be real `<a href>`s** carrying the URL the state would produce. That is
  what makes the page work without JavaScript, and the runtime intercepts links rather than
  inventing behaviour — a `<button>` does nothing.
- **Keep `data-caffeine-facet` and `data-caffeine-value`** on facet links if you want the `client`
  transport to patch counts in place.

For entirely different markup, ignore the widgets and read the state directly. They are thin
wrappers over `search.facet()` and `search.toggleUrl()`, and nothing depends on you using them.

---

## 4. Events

| Event | Where | Use |
| --- | --- | --- |
| `Sources::EVENT_REGISTER_SOURCES` | `services\Sources` | Add an element type. |
| `Extractors::EVENT_REGISTER_EXTRACTORS` | `services\Extractors` | Add or override a field-type reader. |

---

## 5. If you are changing the query engine

Don't, without reading `docs/QUERY_SPEC.md` first — and then change the spec before the code.

There are **two** engines, `src/search/Engine.php` and `src/web/assets/runtime/src/engine.js`, and
they must agree exactly: the server renders the first paint and the browser takes over, so any
disagreement shows up as the page rearranging itself under the visitor the moment they touch a
control.

Five pieces of logic exist in both languages, each pinned by fixtures in `tests/Conformance/`: the
tokeniser, the varint codec, the facet-value projection, the URL codec and the haversine.
`composer conformance` runs both halves — the PHP pass compiles the artifacts the JavaScript pass
consumes, so the order matters.
