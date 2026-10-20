<?php

namespace justinholtweb\caffeine\services;

use Craft;
use craft\base\Component;
use craft\events\ConfigEvent;
use justinholtweb\caffeine\events\DefineIndexEvent;
use justinholtweb\caffeine\models\AttributeDefinition;
use justinholtweb\caffeine\models\Edition;
use justinholtweb\caffeine\models\IndexDefinition;
use justinholtweb\caffeine\models\SortingDefinition;
use justinholtweb\caffeine\Plugin;

/**
 * Index definitions, stored in project config.
 *
 * An index is schema, so it belongs with the rest of a site's configuration and deploys with
 * the code. Everything derived from it — records, artifacts, dependency edges — is
 * environment-local and stays in the database and on disk.
 */
class Indexes extends Component
{
    /** The plugin's own top-level project-config key. Removed wholesale on uninstall. */
    public const CONFIG_ROOT = 'caffeine';

    /** Indexes live at `caffeine.indexes.<uid>`. */
    public const CONFIG_PATH = self::CONFIG_ROOT . '.indexes';

    /**
     * @event DefineIndexEvent Fired for each index as definitions load, before anything builds or
     * queries against them. Attributes and sortings a handler adds are flagged `dynamic` and never
     * saved to project config.
     *
     * ```php
     * Event::on(Indexes::class, Indexes::EVENT_DEFINE_INDEX, function(DefineIndexEvent $event) {
     *     if ($event->index->handle !== 'products') {
     *         return;
     *     }
     *
     *     $event->index->attributes[] = new AttributeDefinition([
     *         'key' => 'spec_motor',
     *         'label' => 'Motor',
     *         'source' => AttributeDefinition::SOURCE_FIELD,
     *         'path' => 'listingSpecs.Motor',
     *         'roles' => [AttributeDefinition::ROLE_FACET],
     *     ]);
     * });
     * ```
     */
    public const EVENT_DEFINE_INDEX = 'defineIndex';

    /** @var array<string, IndexDefinition>|null Keyed by uid, with every handler applied. */
    private ?array $indexes = null;

    /** @var array<string, IndexDefinition>|null Keyed by uid, exactly as project config has them. */
    private ?array $stored = null;

    /**
     * Every index, keyed by handle.
     *
     * @return array<string, IndexDefinition>
     */
    public function all(): array
    {
        $byHandle = [];

        foreach ($this->allByUid() as $index) {
            $byHandle[$index->handle] = $index;
        }

        return $byHandle;
    }

    /**
     * Every index keyed by uid, as the build and the query see them: with whatever
     * `EVENT_DEFINE_INDEX` handlers added.
     *
     * @return array<string, IndexDefinition>
     */
    public function allByUid(): array
    {
        if ($this->indexes !== null) {
            return $this->indexes;
        }

        $indexes = [];

        foreach ($this->storedByUid() as $uid => $stored) {
            $indexes[$uid] = $this->define($stored);
        }

        return $this->indexes = $indexes;
    }

    /**
     * Every index keyed by uid, exactly as project config stores it, with no handler applied.
     *
     * What the control panel edits. Editing the defined version instead would show the module's
     * attributes as if they were part of the index, and a save would freeze any adjustment a
     * handler made to a stored one into project config.
     *
     * @return array<string, IndexDefinition>
     */
    public function storedByUid(): array
    {
        if ($this->stored !== null) {
            return $this->stored;
        }

        $config = Craft::$app->getProjectConfig()->get(self::CONFIG_PATH) ?? [];
        $indexes = [];

        foreach ($config as $uid => $row) {
            if (!is_array($row)) {
                continue;
            }

            $indexes[$uid] = IndexDefinition::fromConfig((string)$uid, $row);
        }

        // Deterministic order, so the CP list and every console command agree on what "the
        // first index" means — which matters once Lite starts enforcing its limit of one.
        uasort($indexes, fn(IndexDefinition $a, IndexDefinition $b) => strcmp($a->handle, $b->handle));

        return $this->stored = $indexes;
    }

    public function getStoredByUid(string $uid): ?IndexDefinition
    {
        return $this->storedByUid()[$uid] ?? null;
    }

    public function getStoredByHandle(string $handle): ?IndexDefinition
    {
        foreach ($this->storedByUid() as $index) {
            if ($index->handle === $handle) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Applies `EVENT_DEFINE_INDEX` to a copy of a stored definition.
     *
     * Whatever a handler added is flagged `dynamic`, by identity rather than by trusting the
     * handler to set the flag. Anything it added that would break the build is dropped with a
     * warning: a module's typo should cost one facet, not every listing on the site.
     */
    private function define(IndexDefinition $stored): IndexDefinition
    {
        $index = clone $stored;
        $index->attributes = array_map(fn(AttributeDefinition $a) => clone $a, $stored->attributes);
        $index->sortings = array_map(fn(SortingDefinition $s) => clone $s, $stored->sortings);

        if (!$this->hasEventHandlers(self::EVENT_DEFINE_INDEX)) {
            return $index;
        }

        $before = [];

        foreach ([...$index->attributes, ...$index->sortings] as $definition) {
            $before[spl_object_id($definition)] = true;
        }

        $this->trigger(self::EVENT_DEFINE_INDEX, new DefineIndexEvent(['index' => $index]));

        $keys = [];
        $attributes = [];

        foreach (array_values($index->attributes) as $attribute) {
            if (!isset($before[spl_object_id($attribute)])) {
                $attribute->dynamic = true;

                if (!$attribute->validate() || isset($keys[$attribute->key])) {
                    Craft::warning(sprintf(
                        'Caffeine ignored the attribute “%s” added to “%s”: %s',
                        $attribute->key,
                        $index->handle,
                        isset($keys[$attribute->key]) ? 'the key is already in use.' : implode(' ', $attribute->getFirstErrors()),
                    ), Plugin::LOG_CATEGORY);

                    continue;
                }
            }

            $keys[$attribute->key] = true;
            $attributes[] = $attribute;
        }

        $index->attributes = $attributes;

        foreach ($index->sortings as $sorting) {
            if (!isset($before[spl_object_id($sorting)])) {
                $sorting->dynamic = true;
            }
        }

        return $index;
    }

    /**
     * Marks every record of an index for rebuilding and schedules the update.
     *
     * For a module whose `EVENT_DEFINE_INDEX` handler just started adding something different —
     * an editor created a new attribute — so the records catch up now rather than at the next
     * deploy. Safe to call as often as you like: the update is debounced like any other.
     *
     * @return int Records marked.
     */
    public function invalidate(IndexDefinition|string $index): int
    {
        // Definitions are re-read, so the handler runs again and sees the new attribute set.
        $this->reset();

        $index = $index instanceof IndexDefinition
            ? $this->getByUid($index->uid)
            : ($this->getByHandle($index) ?? $this->getByUid($index));

        if ($index === null) {
            return 0;
        }

        $plugin = Plugin::getInstance();
        $marked = $plugin->records->markIndexDirty($index->uid);

        if ($plugin->getSettings()->autoUpdate && $this->isAllowed($index)) {
            $plugin->autoUpdate->schedule($index);
        }

        return $marked;
    }

    /**
     * Marks an index's records dirty when the definition they would be built under is not the
     * one the live artifact was published from.
     *
     * The automatic half of `invalidate()`: a deploy that changes what a module adds never
     * touches project config, so nothing else would notice. Runs before every dirty build, so
     * `caffeine/index/build` after a deploy does the right thing without `--all`.
     *
     * @return bool Whether the definition had changed.
     */
    public function checkDefinition(IndexDefinition $index): bool
    {
        $published = Plugin::getInstance()->artifacts->definitionHash($index->uid);

        // Published before fingerprints were recorded. Nothing to compare against; the next
        // publish records one.
        if ($published === null || $published === $index->fingerprint()) {
            return false;
        }

        Plugin::getInstance()->records->markIndexDirty($index->uid);

        return true;
    }

    /**
     * Indexes this installation is licensed to use.
     *
     * Lite allows one. Rather than refusing to load the others — which would silently break a
     * site that downgraded — the extras stay visible in the CP and are skipped at build and
     * query time, so the operator can see exactly what they would get back by upgrading.
     *
     * @return array<string, IndexDefinition>
     */
    public function allowed(): array
    {
        $all = $this->all();

        if (Plugin::getInstance()->isPro()) {
            return $all;
        }

        return array_slice($all, 0, Edition::maxIndexes(false) ?? count($all), true);
    }

    /**
     * A definition with anything this edition cannot run removed.
     *
     * The counterpart to refusing at save time, and it exists for one case: a site whose Pro
     * licence lapsed. Rather than breaking — an exception on a listing page is an outage — the
     * index keeps working with the Lite feature set. The *stored* definition is untouched and the
     * control panel keeps showing it, so renewing restores exactly what was there before.
     */
    public function forEdition(IndexDefinition $index): IndexDefinition
    {
        if (Plugin::getInstance()->isPro()) {
            return $index;
        }

        $downgraded = clone $index;
        $facetTypes = Edition::facetTypes(false);

        // A facet of an unsupported type is dropped rather than reinterpreted. Treating a geo
        // facet as a string facet would produce one bucket per record — visibly broken, and
        // harder to diagnose than a facet that is simply not there.
        $downgraded->attributes = array_values(array_filter(
            array_map(fn(AttributeDefinition $a) => clone $a, $index->attributes),
            fn(AttributeDefinition $a) => !$a->isFacet() || in_array($a->facetType, $facetTypes, true),
        ));

        $keys = array_map(fn(AttributeDefinition $a) => $a->key, $downgraded->attributes);
        $kept = 0;

        $downgraded->sortings = array_values(array_filter(
            $index->sortings,
            function(SortingDefinition $sorting) use ($keys, &$kept) {
                // A sorting whose attribute just went with a dropped facet cannot work either.
                if (!$sorting->isRelevance() && !in_array($sorting->attribute, $keys, true)) {
                    return false;
                }

                return $sorting->isRelevance() || ++$kept <= Edition::LITE_MAX_SORTINGS;
            },
        ));

        if (!in_array($downgraded->transport, Edition::transports(false), true)) {
            $downgraded->transport = IndexDefinition::TRANSPORT_HTMX;
        }

        if (!Edition::allowsWordLists(false)) {
            $downgraded->stopwords = [];
            $downgraded->synonyms = [];
        }

        return $downgraded;
    }

    public function isAllowed(IndexDefinition $index): bool
    {
        return isset($this->allowed()[$index->handle]);
    }

    public function getByHandle(string $handle): ?IndexDefinition
    {
        return $this->all()[$handle] ?? null;
    }

    public function getByUid(string $uid): ?IndexDefinition
    {
        return $this->allByUid()[$uid] ?? null;
    }

    /**
     * Saves an index definition to project config.
     */
    public function save(IndexDefinition $index, bool $runValidation = true): bool
    {
        if ($runValidation && !$index->validate()) {
            return false;
        }

        // Handles are the index's public identity — Twig looks one up by handle, and so does the
        // artifact path. Two definitions sharing one would silently shadow each other here (this
        // list is keyed by handle) while both kept publishing to the same directory, each
        // overwriting the other's pointer. Cheaper to refuse.
        if ($runValidation) {
            foreach ($this->allByUid() as $uid => $existing) {
                if ($existing->handle === $index->handle && $uid !== $index->uid) {
                    $index->addError('handle', Craft::t(
                        'caffeine',
                        'Another index already uses the handle “{handle}”.',
                        ['handle' => $index->handle],
                    ));

                    return false;
                }
            }
        }

        $uid = $index->ensureUid();

        // Refused rather than silently downgraded: an operator who configured a numeric facet
        // should be told it needs Pro, not given a string facet and left to wonder why the
        // ranges do nothing.
        if ($runValidation) {
            $problems = Edition::problems($index, Plugin::getInstance()->isPro());

            if ($problems !== []) {
                foreach ($problems as $problem) {
                    $index->addError('attributes', $problem);
                }

                return false;
            }
        }

        Craft::$app->getProjectConfig()->set(
            self::CONFIG_PATH . '.' . $uid,
            $index->toConfig(),
            "Save Caffeine index “{$index->handle}”",
        );

        $this->reset();

        return true;
    }

    public function delete(IndexDefinition $index): bool
    {
        if ($index->uid === '') {
            return false;
        }

        // Torn down here as well as in the project-config handler, and deliberately so. The
        // handler is what runs on the *other* environments, when the deletion arrives through
        // `project-config/apply`. On this one it cannot be relied on: project config coalesces
        // changes within a request, so an index created and deleted in the same run — a test, a
        // migration, a seeding script — fires no removal event at all, and its records and
        // published files would outlive it with nothing left that knew they were orphans.
        // Both paths are idempotent, so running both is free.
        $this->tearDown($index->uid);

        Craft::$app->getProjectConfig()->remove(
            self::CONFIG_PATH . '.' . $index->uid,
            "Delete Caffeine index “{$index->handle}”",
        );

        $this->reset();

        return true;
    }

    /**
     * Handles an index appearing or changing in project config.
     *
     * A changed definition invalidates every record built under the old one — a renamed key or
     * a new facet means the stored records no longer match what the artifact needs. Rather
     * than trying to work out which changes are compatible, Caffeine marks the whole index
     * dirty and lets the build settle it. Rebuilds are background work; a subtly stale artifact
     * is a bug report.
     */
    public function handleChangedIndex(ConfigEvent $event): void
    {
        $this->reset();

        // Craft passes the `{uid}` token positionally, not by name.
        $uid = $event->tokenMatches[0] ?? null;

        if (!is_string($uid)) {
            return;
        }

        Plugin::getInstance()->records->markIndexDirty($uid);
    }

    public function handleDeletedIndex(ConfigEvent $event): void
    {
        $this->reset();

        // Craft passes the `{uid}` token positionally, not by name.
        $uid = $event->tokenMatches[0] ?? null;

        if (!is_string($uid)) {
            return;
        }

        $this->tearDown($uid);
    }

    /**
     * Removes everything derived from an index definition: its records, its dependency edges,
     * its published files and its ledger rows.
     */
    private function tearDown(string $uid): void
    {
        Plugin::getInstance()->records->deleteForIndex($uid);
        Plugin::getInstance()->artifacts->deleteForIndex($uid);
    }

    /**
     * Removes everything left behind by indexes that no longer exist, and files in the publish
     * directories of the ones that do that nothing references any more.
     *
     * What Craft's garbage collection runs, and what `caffeine/index/prune-orphans` runs for an
     * operator who wants it done now — after a deploy, or after restoring a database.
     *
     * @return array{indexes: int, records: int, files: list<string>, busy: list<string>}
     */
    public function collectGarbage(): array
    {
        $plugin = Plugin::getInstance();
        $known = array_keys($this->allByUid());

        $result = [
            'indexes' => $plugin->artifacts->deleteOrphans($known),
            'records' => $plugin->records->deleteOrphans($known),
            'files' => [],
            'busy' => [],
        ];

        foreach ($this->all() as $index) {
            $deleted = $plugin->artifacts->sweep($index);

            if ($deleted === null) {
                // Publishing right now. The next run gets it.
                $result['busy'][] = $index->handle;
                continue;
            }

            array_push($result['files'], ...$deleted);
        }

        return $result;
    }

    public function reset(): void
    {
        $this->indexes = null;
        $this->stored = null;
    }
}
