<?php

namespace justinholtweb\caffeine\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\events\BulkOpEvent;
use craft\events\ElementEvent;
use craft\events\MoveElementEvent;
use craft\services\Elements;
use craft\services\Structures;
use justinholtweb\caffeine\models\IndexDefinition;
use justinholtweb\caffeine\Plugin;
use justinholtweb\caffeine\queue\jobs\UpdateJob;
use Throwable;
use yii\base\Event;

/**
 * Keeps published artifacts true to the content.
 *
 * The rule everything here follows: **mark, never build**. An element save records that some
 * rows are stale and schedules work; it does not map records and it does not publish. Doing
 * either inline would put an artifact build inside the request that saved an entry, which is
 * exactly the cost the plugin exists to remove.
 *
 * Two hard parts have specific answers.
 *
 * **Related content going stale.** A record that denormalises a category's title into a facet
 * label depends on that category, and renaming it must mark that record dirty or the artifact
 * serves a label that exists nowhere in the CMS. `caffeine_deps` records every element read to
 * build each record — the same shape as Craft's own `templatecacheelements`, for the same reason.
 *
 * **Resaves stampeding the queue.** `resave/entries` over 5,000 entries must not queue 5,000
 * jobs. Marking is per element and cheap; *scheduling* is what gets coalesced, by
 * `BulkOpEvent::defer()` during a bulk operation and by a debounce window outside one.
 */
class AutoUpdate extends Component
{
    /** Cache key prefix for "an update is already queued for this index". */
    public const PENDING_PREFIX = 'caffeine:pending:';

    /** Longest a pending marker outlives its debounce, so a lost job cannot wedge an index. */
    private const PENDING_GRACE = 300;

    public function register(): void
    {
        Event::on(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT, function(ElementEvent $event) {
            $this->elementSaved($event->element);
        });

        // Propagation is a separate event per site, and a record is per element *per site*, so a
        // save that reaches four sites has to mark four rows rather than one.
        Event::on(Elements::class, Elements::EVENT_AFTER_PROPAGATE_ELEMENT, function(ElementEvent $event) {
            $this->elementSaved($event->element);
        });

        Event::on(Elements::class, Elements::EVENT_AFTER_RESTORE_ELEMENT, function(ElementEvent $event) {
            $this->elementSaved($event->element);
        });

        // The URL is payload on almost every index, so a slug change that did not mark the record
        // would leave every link in the results pointing at the old address.
        Event::on(Elements::class, Elements::EVENT_AFTER_UPDATE_SLUG_AND_URI, function(ElementEvent $event) {
            $this->elementSaved($event->element);
        });

        Event::on(Elements::class, Elements::EVENT_AFTER_DELETE_ELEMENT, function(ElementEvent $event) {
            $this->elementDeleted($event->element);
        });

        Event::on(Structures::class, Structures::EVENT_AFTER_MOVE_ELEMENT, function(MoveElementEvent $event) {
            $this->elementSaved($event->element);
        });

        // Fires once when a bulk operation finishes, and only if these events happened during it.
        // The per-element handlers above still mark rows dirty as they go; this is purely about
        // scheduling the work once instead of once per element.
        foreach ([
            Elements::EVENT_AFTER_SAVE_ELEMENT,
            Elements::EVENT_AFTER_DELETE_ELEMENT,
            Elements::EVENT_AFTER_RESTORE_ELEMENT,
        ] as $name) {
            BulkOpEvent::defer(Elements::class, $name, function() {
                $this->scheduleDirty();
            });
        }
    }

    /**
     * Marks the rows an element's save invalidated, and schedules the indexes it touched.
     */
    public function elementSaved(ElementInterface $element): void
    {
        if (!$this->enabled() || !$this->indexable($element)) {
            return;
        }

        $plugin = Plugin::getInstance();
        $touched = [];

        try {
            foreach ($plugin->indexes->allowed() as $index) {
                if (!$plugin->sources->covers($index, $element)) {
                    continue;
                }

                $plugin->records->touchElement($index->uid, (int)$element->id, [(int)$element->siteId]);
                $touched[$index->uid] = $index;
            }

            // Separately, and regardless of coverage: this element may be *read by* records in
            // indexes it is not itself part of. A Matrix block or a related category never has a
            // record of its own and always arrives here through this branch.
            $dependents = $plugin->records->indexUidsDependingOn([(int)$element->id]);

            if ($dependents !== []) {
                $plugin->records->markDirtyByDependency([(int)$element->id]);

                foreach ($dependents as $uid) {
                    $index = $plugin->indexes->getByUid($uid);

                    if ($index !== null) {
                        $touched[$uid] = $index;
                    }
                }
            }
        } catch (Throwable $e) {
            // Never let indexing break a save. A stale artifact is recoverable; an editor who
            // cannot publish an entry is an outage.
            Craft::error(
                sprintf('Caffeine could not mark element %d dirty: %s', $element->id, $e->getMessage()),
                Plugin::LOG_CATEGORY,
            );

            return;
        }

        $this->scheduleAll($touched);
    }

    public function elementDeleted(ElementInterface $element): void
    {
        if (!$this->enabled() || !$this->indexable($element)) {
            return;
        }

        $plugin = Plugin::getInstance();
        $touched = [];

        try {
            foreach ($plugin->indexes->allowed() as $index) {
                if ($plugin->records->markDeleted($index->uid, [(int)$element->id]) > 0) {
                    $touched[$index->uid] = $index;
                }
            }

            foreach ($plugin->records->indexUidsDependingOn([(int)$element->id]) as $uid) {
                $index = $plugin->indexes->getByUid($uid);

                if ($index !== null) {
                    $touched[$uid] = $index;
                }
            }

            $plugin->records->markDirtyByDependency([(int)$element->id]);
        } catch (Throwable $e) {
            Craft::error(
                sprintf('Caffeine could not mark element %d deleted: %s', $element->id, $e->getMessage()),
                Plugin::LOG_CATEGORY,
            );

            return;
        }

        $this->scheduleAll($touched);
    }

    /**
     * Schedules every index that has anything waiting.
     *
     * What the deferred bulk-op handler calls. Asking the store which indexes are dirty is more
     * robust than accumulating uids across a bulk operation that may span several requests.
     *
     * @return int Indexes scheduled.
     */
    public function scheduleDirty(): int
    {
        $plugin = Plugin::getInstance();
        $scheduled = 0;

        foreach ($plugin->records->indexesWithDirtyRecords() as $uid) {
            $index = $plugin->indexes->getByUid($uid);

            if ($index !== null && $plugin->indexes->isAllowed($index) && $this->schedule($index)) {
                $scheduled++;
            }
        }

        return $scheduled;
    }

    /**
     * @param array<string, IndexDefinition> $indexes
     */
    public function scheduleAll(array $indexes): void
    {
        // Inside a bulk operation nothing is scheduled here. The deferred handler fires once when
        // the operation completes and schedules whatever is dirty by then — so a 5,000-entry
        // resave produces one job, not five thousand.
        if (Craft::$app->getElements()->getBulkOpKeys() !== []) {
            return;
        }

        foreach ($indexes as $index) {
            $this->schedule($index);
        }
    }

    /**
     * Queues an update for an index, unless one is already pending.
     *
     * The debounce is the difference between an editor fixing six typos in a minute and six
     * artifact rebuilds. The first save queues a job delayed by `publishDebounce`; the rest see
     * the pending marker and do nothing, because the job will pick up their changes too.
     *
     * @return bool Whether this call queued anything.
     */
    public function schedule(IndexDefinition $index, ?int $delay = null): bool
    {
        $cache = Craft::$app->getCache();
        $key = self::PENDING_PREFIX . $index->uid;

        if ($cache->get($key) !== false) {
            return false;
        }

        $delay ??= max(0, $index->publishDebounce);
        $settings = Plugin::getInstance()->getSettings();

        // The marker outlives the delay by a margin. If the queue never runs the job, it expires
        // and the next save schedules again rather than the index staying wedged forever.
        $cache->set($key, true, $delay + self::PENDING_GRACE);

        if (!$settings->useQueue) {
            // Synchronous, for tests and for sites running without a queue runner. The marker is
            // cleared by the job itself.
            (new UpdateJob(['indexUid' => $index->uid]))->execute(Craft::$app->getQueue());

            return true;
        }

        Craft::$app->getQueue()
            ->delay($delay)
            ->push(new UpdateJob(['indexUid' => $index->uid]));

        return true;
    }

    public function clearPending(string $indexUid): void
    {
        Craft::$app->getCache()->delete(self::PENDING_PREFIX . $indexUid);
    }

    public function isPending(string $indexUid): bool
    {
        return Craft::$app->getCache()->get(self::PENDING_PREFIX . $indexUid) !== false;
    }

    private function enabled(): bool
    {
        return Plugin::getInstance()->getSettings()->autoUpdate;
    }

    /**
     * Whether an element is the kind of thing that can be in an index at all.
     *
     * Drafts and revisions are explicitly not: indexing a draft would put unpublished content in
     * front of visitors, and indexing revisions would multiply every record by its history.
     */
    private function indexable(ElementInterface $element): bool
    {
        if ($element->id === null) {
            return false;
        }

        return !$element->getIsDraft() && !$element->getIsRevision();
    }
}
