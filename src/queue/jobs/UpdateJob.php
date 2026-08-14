<?php

namespace justinholtweb\caffeine\queue\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\caffeine\Plugin;
use Throwable;

/**
 * Rebuilds an index's stale records and republishes its artifact.
 *
 * The only place in Caffeine where a build happens in the background, and the only place two of
 * them could collide — a debounced job and a manual rebuild, or two queue runners on the same
 * site — so it is mutex-guarded end to end.
 *
 * Failure behaviour is chosen, not incidental. If the build throws, the previously published
 * artifact is still live and still correct-as-of-its-time: nothing was written, the pointer was
 * never touched, and the dirty rows stay dirty so the next run retries them. A stale index is
 * recoverable; a missing one takes the page down.
 */
class UpdateJob extends BaseJob
{
    public string $indexUid = '';

    /** Set false to rebuild records without cutting a new artifact. */
    public bool $publish = true;

    /** Guards against a job that keeps losing the mutex requeueing itself forever. */
    public int $attempt = 0;

    private const MAX_ATTEMPTS = 5;

    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();
        $index = $plugin->indexes->getByUid($this->indexUid);

        if ($index === null || !$plugin->indexes->isAllowed($index)) {
            return;
        }

        // Cleared first, deliberately. Anything saved from here on is a change this run may not
        // see, and clearing the marker now means that save schedules a follow-up rather than
        // being swallowed by a job that had already read the dirty list.
        $plugin->autoUpdate->clearPending($this->indexUid);

        $mutex = Craft::$app->getMutex();
        $lock = 'caffeine:update:' . $this->indexUid;

        if (!$mutex->acquire($lock, 3)) {
            $this->requeue($queue);

            return;
        }

        try {
            $this->setProgress($queue, 0, Craft::t('caffeine', 'Rebuilding records'));

            $result = $plugin->builder->buildDirty($index, function(int $done, int $total) use ($queue) {
                // Capped below the halfway mark: publishing is the other half of the work, and a
                // progress bar that reaches 100% and then sits there is worse than none.
                $this->setProgress($queue, ($done / max(1, $total)) * 0.5, Craft::t('caffeine', 'Rebuilding records'));
            });

            foreach ($result['errors'] as $error) {
                Craft::error(sprintf('Caffeine “%s”: %s', $index->handle, $error), Plugin::LOG_CATEGORY);
            }

            if (!$this->publish) {
                return;
            }

            $this->setProgress($queue, 0.5, Craft::t('caffeine', 'Publishing artifact'));

            $published = $plugin->artifacts->publish($index);

            $this->setProgress($queue, 1);

            Craft::info(
                $published['published']
                    ? sprintf(
                        'Caffeine updated “%s”: %d record(s) rebuilt, published v%d in %dms.',
                        $index->handle,
                        $result['records'],
                        $published['version'],
                        $published['buildMs'],
                    )
                    : sprintf(
                        'Caffeine rebuilt %d record(s) for “%s”; the artifact was unchanged.',
                        $result['records'],
                        $index->handle,
                    ),
                Plugin::LOG_CATEGORY,
            );
        } finally {
            $mutex->release($lock);
        }
    }

    /**
     * Another run holds the lock. Come back shortly rather than dropping the work.
     */
    private function requeue($queue): void
    {
        if ($this->attempt >= self::MAX_ATTEMPTS) {
            Craft::warning(
                sprintf('Caffeine gave up waiting for the update lock on “%s”.', $this->indexUid),
                Plugin::LOG_CATEGORY,
            );

            return;
        }

        try {
            Craft::$app->getQueue()->delay(10)->push(new self([
                'indexUid' => $this->indexUid,
                'publish' => $this->publish,
                'attempt' => $this->attempt + 1,
            ]));
        } catch (Throwable $e) {
            Craft::error('Caffeine could not requeue an update: ' . $e->getMessage(), Plugin::LOG_CATEGORY);
        }
    }

    protected function defaultDescription(): ?string
    {
        $index = Plugin::getInstance()->indexes->getByUid($this->indexUid);

        return Craft::t('caffeine', 'Updating the “{index}” search index', [
            'index' => $index?->name ?? $this->indexUid,
        ]);
    }
}
