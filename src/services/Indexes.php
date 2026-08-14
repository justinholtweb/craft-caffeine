<?php

namespace justinholtweb\caffeine\services;

use Craft;
use craft\base\Component;
use craft\events\ConfigEvent;
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

    /** @var array<string, IndexDefinition>|null Keyed by uid. */
    private ?array $indexes = null;

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
     * @return array<string, IndexDefinition>
     */
    public function allByUid(): array
    {
        if ($this->indexes !== null) {
            return $this->indexes;
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

        return $this->indexes = $indexes;
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

        $this->indexes = null;

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

        $this->indexes = null;

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
        $this->indexes = null;

        $uid = $event->tokenMatches['uid'] ?? null;

        if (!is_string($uid)) {
            return;
        }

        Plugin::getInstance()->records->markIndexDirty($uid);
    }

    public function handleDeletedIndex(ConfigEvent $event): void
    {
        $this->indexes = null;

        $uid = $event->tokenMatches['uid'] ?? null;

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

    public function reset(): void
    {
        $this->indexes = null;
    }
}
