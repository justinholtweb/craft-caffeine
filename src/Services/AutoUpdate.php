<?php

namespace JustinHoltWeb\Caffeine\Services;

use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Element\ElementHelper;
use CraftCms\Cms\Element\Events\ElementDeleted;
use CraftCms\Cms\Element\Events\ElementMovedInStructure;
use CraftCms\Cms\Element\Events\ElementRestored;
use CraftCms\Cms\Element\Events\ElementSaved;
use CraftCms\Cms\Element\Events\ElementSlugAndUriUpdated;
use CraftCms\Cms\Support\Facades\BulkOps;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use JustinHoltWeb\Caffeine\Jobs\UpdateJob;
use JustinHoltWeb\Caffeine\Models\IndexDefinition;
use JustinHoltWeb\Caffeine\Plugin;
use Throwable;

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
 * **Resaves stampeding the queue.** `resave:entries` over 5,000 entries must not queue 5,000
 * jobs. Marking is per element and cheap; *scheduling* is what gets coalesced, by
 * `BulkOps::defer()` during a bulk operation and by a debounce window outside one.
 */
class AutoUpdate
{
    /** Cache key prefix for "an update is already queued for this index". */
    public const PENDING_PREFIX = 'caffeine:pending:';

    /** Longest a pending marker outlives its debounce, so a lost job cannot wedge an index. */
    private const PENDING_GRACE = 300;

    /**
     * Registers the element listeners. Called once, from `Plugin::boot()`, on every request type:
     * a `resave:entries` is exactly the case the bulk-op coalescing exists for, and it only ever
     * runs from the command line.
     */
    public function register(): void
    {
        // Craft 6 fires `ElementSaved` once per site a save reaches, propagated sites included,
        // so this one listener also covers what Craft 5 needed `EVENT_AFTER_PROPAGATE_ELEMENT`
        // for: a record is per element *per site*, and a save that reaches four sites has to mark
        // four rows rather than one.
        Event::listen(ElementSaved::class, fn(ElementSaved $event) => $this->elementSaved($event->element));
        Event::listen(ElementRestored::class, fn(ElementRestored $event) => $this->elementSaved($event->element));

        // The URL is payload on almost every index, so a slug change that did not mark the record
        // would leave every link in the results pointing at the old address.
        Event::listen(ElementSlugAndUriUpdated::class, fn(ElementSlugAndUriUpdated $event) => $this->elementSaved($event->element));

        Event::listen(ElementDeleted::class, fn(ElementDeleted $event) => $this->elementDeleted($event->element));
        Event::listen(ElementMovedInStructure::class, fn(ElementMovedInStructure $event) => $this->elementSaved($event->element));

        // Replayed once when a bulk operation finishes, and only if these events happened during
        // it. The per-element listeners above still mark rows dirty as they go; this is purely
        // about scheduling the work once instead of once per element.
        foreach ([ElementSaved::class, ElementDeleted::class, ElementRestored::class] as $event) {
            BulkOps::defer($event, function() {
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

        $indexes = app(Indexes::class);
        $records = app(Records::class);
        $touched = [];

        try {
            foreach ($indexes->allowed() as $index) {
                if (!app(Sources::class)->covers($index, $element)) {
                    continue;
                }

                $records->touchElement($index->uid, (int)$element->id, [(int)$element->siteId]);
                $touched[$index->uid] = $index;
            }

            // Separately, and regardless of coverage: this element may be *read by* records in
            // indexes it is not itself part of. A Matrix block or a related category never has a
            // record of its own and always arrives here through this branch.
            $dependents = $records->indexUidsDependingOn([(int)$element->id]);

            if ($dependents !== []) {
                $records->markDirtyByDependency([(int)$element->id]);

                foreach ($dependents as $uid) {
                    $index = $indexes->getByUid($uid);

                    if ($index !== null) {
                        $touched[$uid] = $index;
                    }
                }
            }
        } catch (Throwable $e) {
            // Never let indexing break a save. A stale artifact is recoverable; an editor who
            // cannot publish an entry is an outage.
            Log::channel('caffeine')->error(
                sprintf('Caffeine could not mark element %d dirty: %s', $element->id, $e->getMessage()),
            );

            return;
        }

        $this->scheduleAllSafely($touched, $element);
    }

    public function elementDeleted(ElementInterface $element): void
    {
        if (!$this->enabled() || !$this->indexable($element)) {
            return;
        }

        $indexes = app(Indexes::class);
        $records = app(Records::class);
        $touched = [];

        try {
            foreach ($indexes->allowed() as $index) {
                if ($records->markDeleted($index->uid, [(int)$element->id]) > 0) {
                    $touched[$index->uid] = $index;
                }
            }

            foreach ($records->indexUidsDependingOn([(int)$element->id]) as $uid) {
                $index = $indexes->getByUid($uid);

                if ($index !== null) {
                    $touched[$uid] = $index;
                }
            }

            $records->markDirtyByDependency([(int)$element->id]);
        } catch (Throwable $e) {
            Log::channel('caffeine')->error(
                sprintf('Caffeine could not mark element %d deleted: %s', $element->id, $e->getMessage()),
            );

            return;
        }

        $this->scheduleAllSafely($touched, $element);
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
        $indexes = app(Indexes::class);
        $scheduled = 0;

        foreach (app(Records::class)->indexesWithDirtyRecords() as $uid) {
            $index = $indexes->getByUid($uid);

            if ($index !== null && $indexes->isAllowed($index) && $this->schedule($index)) {
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
        if (BulkOps::activeKeys() !== []) {
            return;
        }

        foreach ($indexes as $index) {
            $this->schedule($index);
        }
    }

    /**
     * `scheduleAll()` behind the same guard as the marking: with the queue off, scheduling runs
     * the build inline, and a build that throws must not take the editor's save down with it.
     *
     * @param array<string, IndexDefinition> $indexes
     */
    private function scheduleAllSafely(array $indexes, ElementInterface $element): void
    {
        try {
            $this->scheduleAll($indexes);
        } catch (Throwable $e) {
            Log::channel('caffeine')->error(
                sprintf('Caffeine could not schedule an update after element %d changed: %s', $element->id, $e->getMessage()),
            );
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
        $key = self::PENDING_PREFIX . $index->uid;
        $delay ??= max(0, $index->publishDebounce);

        // `add()` writes only when the key is absent, so two requests saving at once cannot both
        // decide nothing is pending and queue a job each. (Laravel's `get()` answers a miss with
        // null, not Yii's `false` — a straight port of the old check would never schedule.)
        //
        // The marker outlives the delay by a margin. If the queue never runs the job, it expires
        // and the next save schedules again rather than the index staying wedged forever.
        if (!Cache::add($key, true, $delay + self::PENDING_GRACE)) {
            return false;
        }

        if (!Plugin::getInstance()->getSettings()->useQueue) {
            // Synchronous, for tests and for sites running without a queue runner. The marker is
            // cleared by the job itself.
            UpdateJob::dispatchSync($index->uid);

            return true;
        }

        UpdateJob::dispatch($index->uid)->delay($delay);

        return true;
    }

    public function clearPending(string $indexUid): void
    {
        Cache::forget(self::PENDING_PREFIX . $indexUid);
    }

    public function isPending(string $indexUid): bool
    {
        return Cache::has(self::PENDING_PREFIX . $indexUid);
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

        // Craft 6 fires `ElementSaved` for drafts and revisions too — and for the nested entries
        // inside them, which is what `isDraftOrRevision()` adds over asking the element alone.
        return !ElementHelper::isDraftOrRevision($element);
    }
}
