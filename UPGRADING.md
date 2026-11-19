# Upgrading Caffeine from Craft 5 to Craft 6

Caffeine 6 is a native Craft 6 plugin. It runs on Laravel and has no dependency on the Yii 2
adapter. The query engines, the artifact format and the front-end runtime haven't changed, so your
index definitions, published artifacts, templates and front-end code carry over. What changes is
the PHP around them: namespaces, events, console commands and a few settings.

> **Beta.** Caffeine 6 is in beta, like Craft 6 itself. Test the upgrade on a copy of your site
> before relying on it in production. Caffeine 5 remains the stable release for Craft 5.

| | Caffeine 5.x | Caffeine 6.x |
| --- | --- | --- |
| Status | Stable | Beta |
| Craft CMS | 5.3+ | 6.0+ |
| PHP | 8.2+ | 8.5+ |
| Commands | `php craft caffeine/index/build` | `php artisan caffeine:index:build` (old names still work) |
| Config file | `config/caffeine.php` | `config/craft/caffeine.php` |

## Before you start

1. **Update to Caffeine 5.3.0** while the site is still on Craft 5, and run its migrations.
   Caffeine 6 won't install over anything older.

   ```bash
   composer require justinholtweb/craft-caffeine:^5.3.0
   php craft up
   ```

2. **Move indexes off categories and tags.** Craft 6 has no categories or tags, and Craft's own
   upgrade expects you to have converted them to entries first. After converting, change any
   index source of type *Categories* or *Tags* to *Entries*, pointed at the new section. On
   Craft 6 an index that still has a category or tag source won't save until you do, and builds
   nothing for that source in the meantime.

3. **Commerce product indexes** need Commerce 6, which isn't released yet. Leave those sites on
   Craft 5 for now.

4. **Find your extensions.** If a plugin or module listens for Caffeine's events, or registers a
   custom source or extractor, it needs the changes under [Custom code](#custom-code).

## Upgrade

Follow Craft's [Craft 6 upgrade guide](https://craftcms.com/docs/6.x/upgrade.html), requiring
Caffeine 6 in the same step:

```bash
composer require "craftcms/cms:^6.0@beta" "justinholtweb/craft-caffeine:^6.0@beta" -W
php artisan craft:up
```

Then rebuild and republish every index once, so each artifact is built by Caffeine 6:

```bash
php artisan caffeine:index:build --all
php artisan caffeine:artifact:publish
```

Drop the `@beta` flags once Craft 6 and Caffeine 6 have stable releases.

## What carries over

- **Index definitions.** They live in project config under the same `caffeine.indexes` key.
- **Records and published artifacts.** The tables and the artifact format are unchanged.
- **Templates.** `{% caffeine %}`, `{% caffeineresults %}`, the `caffeine()` function, the widgets,
  and widget overrides in `templates/_caffeine/` all work as before.
- **Front-end code.** The bundled runtime, InstantSearch adapters and the URLs they call —
  `actions/caffeine/search`, `actions/caffeine/fragment`, `caffeine/search/<handle>` and
  `caffeine/fragment` — are unchanged.
- **Settings.** Plugin settings stay in project config. Move `config/caffeine.php` to
  `config/craft/caffeine.php` if you have one.
- **Permissions.** *Manage indexes* and *Rebuild and republish indexes* keep their names.

## What changes

### Publishing to a filesystem

*Filesystem* is now *Filesystem disk*: the name of a disk in `config/filesystems.php`. Craft's
upgrade turns each Craft 5 filesystem into a disk with the same name, so the setting normally
carries over. Disks don't throw on a failed write by default, so Caffeine checks each write itself
and reports a failed publish instead of leaving a partial one.

### Fragment tokens

The token a page embeds for the fragment endpoint is now encrypted with your app key, rather than
signed with Craft 5's security key. A page cached before the upgrade carries an old token. When the
fragment request fails, the runtime falls back to an ordinary page load, so visitors still get
results; clear your static cache after upgrading to get the in-place updates back.

### Console commands

| Caffeine 5 | Caffeine 6 |
| --- | --- |
| `php craft caffeine/index/status` | `php artisan caffeine:index:status` |
| `php craft caffeine/index/build [handle] --all --workers=N` | `php artisan caffeine:index:build [handle] --all --workers=N` |
| `php craft caffeine/index/preview <handle> <id>` | `php artisan caffeine:index:preview <handle> <id>` |
| `php craft caffeine/index/prune-orphans` | `php artisan caffeine:index:prune-orphans` |
| `php craft caffeine/artifact/publish [handle] --force` | `php artisan caffeine:artifact:publish [handle] --force` |
| `php craft caffeine/artifact/status` | `php artisan caffeine:artifact:status` |
| `php craft caffeine/artifact/verify <handle>` | `php artisan caffeine:artifact:verify <handle>` |
| `php craft caffeine/artifact/versions <handle>` | `php artisan caffeine:artifact:versions <handle>` |
| `php craft caffeine/artifact/prune [handle]` | `php artisan caffeine:artifact:prune [handle]` |

The old names still work as aliases (`php craft caffeine/index/build` too), so deploy scripts
don't have to change on day one.

### Logs

Caffeine logs to `storage/logs/caffeine-YYYY-MM-DD.log` through a Laravel log channel, at the
level set in its settings.

### The control panel

The index screens are rebuilt with Craft 6's UI. Rebuild and publish buttons moved from the index
list to each index's **Tools** screen, next to the record preview and query playground. Plugin
settings are on the standard **Settings → Plugins → Caffeine** screen.

## Custom code

### Namespaces

`justinholtweb\caffeine\…` is now `JustinHoltWeb\Caffeine\…`, and the sub-namespaces are
capitalised: `services` → `Services`, `models` → `Models`, `sources` → `Sources`, `extractors` →
`Extractors`, `search` → `Search`, `events` → `Events`.

Craft's own classes moved too. For a custom source: `craft\base\ElementInterface` is
`CraftCms\Cms\Element\Contracts\ElementInterface`, and `craft\elements\db\ElementQueryInterface` is
`CraftCms\Cms\Element\Queries\Contracts\ElementQueryInterface`.

### Events

Caffeine's events are Laravel events now. Register listeners in a service provider's or plugin's
`boot()`:

```php
// Caffeine 5
Event::on(Indexes::class, Indexes::EVENT_DEFINE_INDEX, function (DefineIndexEvent $event) { … });

// Caffeine 6
use Illuminate\Support\Facades\Event;
use JustinHoltWeb\Caffeine\Events\DefineIndex;

Event::listen(DefineIndex::class, function (DefineIndex $event) { … });
```

| Caffeine 5 | Caffeine 6 |
| --- | --- |
| `Sources::EVENT_REGISTER_SOURCES` + `RegisterSourcesEvent` | `RegisterSources` |
| `Extractors::EVENT_REGISTER_EXTRACTORS` + `RegisterExtractorsEvent` | `RegisterExtractors` |
| `Indexes::EVENT_DEFINE_INDEX` + `DefineIndexEvent` | `DefineIndex` |
| `Builder::EVENT_DEFINE_SOURCE_QUERY` + `DefineSourceQueryEvent` | `DefineSourceQuery` |

The payloads are the same: `$event->sources`, `$event->extractors`, `$event->index`, and
`$event->index`, `source`, `definition`, `siteId` and `query`.

### Services

Plugin components are now container services. Instead of `Plugin::getInstance()->indexes`, use
`app(\JustinHoltWeb\Caffeine\Services\Indexes::class)` or inject the class.

## New in Caffeine 6

- **GraphQL** (Pro): a `caffeineSearch` query over the published artifact, scoped per index in
  each schema's permissions. See the README.
- **The sidebar item** can be renamed and given another icon in Caffeine's settings.
- **Tools screen** for each index: update stale records, rebuild everything, publish now.

## Rolling back

Restore your Craft 5 site and database from the backup you took before upgrading, and require
`justinholtweb/craft-caffeine:^5.3.0`. Republish each index afterwards so the artifacts on disk
match the restored records.
