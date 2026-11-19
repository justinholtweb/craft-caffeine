<?php

namespace JustinHoltWeb\Caffeine\Jobs;

use CraftCms\Cms\Queue\Job;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use JustinHoltWeb\Caffeine\Services\Artifacts;
use JustinHoltWeb\Caffeine\Services\AutoUpdate;
use JustinHoltWeb\Caffeine\Services\Builder;
use JustinHoltWeb\Caffeine\Services\Indexes;
use Throwable;

use function CraftCms\Cms\t;

/**
 * Rebuilds an index's stale records and republishes its artifact.
 *
 * The only place in Caffeine where a build happens in the background, and the only place two of
 * them could collide — a debounced job and a manual rebuild, or two queue runners on the same
 * site — so it is lock-guarded end to end.
 *
 * Failure behaviour is chosen, not incidental. If the build throws, the previously published
 * artifact is still live and still correct-as-of-its-time: nothing was written, the pointer was
 * never touched, and the dirty rows stay dirty so the next run retries them. A stale index is
 * recoverable; a missing one takes the page down.
 */
class UpdateJob extends Job
{
    private const MAX_ATTEMPTS = 5;

    /** Seconds to wait for another run's lock before handing the work to a fresh job. */
    private const LOCK_WAIT = 3;

    /**
     * Expiry of the update lock. Laravel locks are not released when the process dies, so this
     * is what frees an index whose worker was killed mid-build. Comfortably longer than a build.
     */
    private const LOCK_TTL = 1800;

    /** Seconds before a job that lost the lock tries again. */
    private const RETRY_DELAY = 10;

    /**
     * @param string $indexUid The index to update.
     * @param bool $publish Set false to rebuild records without cutting a new artifact.
     * @param int $attempt Guards against a job that keeps losing the lock requeueing itself forever.
     */
    public function __construct(
        public string $indexUid = '',
        public bool $publish = true,
        public int $attempt = 0,
    ) {
        parent::__construct();
    }

    public function handle(Indexes $indexes, Builder $builder, Artifacts $artifacts, AutoUpdate $autoUpdate): void
    {
        $index = $indexes->getByUid($this->indexUid);

        if ($index === null || !$indexes->isAllowed($index)) {
            return;
        }

        // Cleared first, deliberately. Anything saved from here on is a change this run may not
        // see, and clearing the marker now means that save schedules a follow-up rather than
        // being swallowed by a job that had already read the dirty list.
        $autoUpdate->clearPending($this->indexUid);

        $lock = Cache::lock('caffeine:update:' . $this->indexUid, self::LOCK_TTL);

        try {
            $lock->block(self::LOCK_WAIT);
        } catch (LockTimeoutException) {
            $this->requeue();

            return;
        }

        try {
            $this->setProgress(0, t('Rebuilding records', category: 'caffeine'));

            $result = $builder->buildDirty($index, function(int $done, int $total) {
                // Capped below the halfway mark: publishing is the other half of the work, and a
                // progress bar that reaches 100% and then sits there is worse than none.
                $this->setProgress((int)floor(($done / max(1, $total)) * 50), t('Rebuilding records', category: 'caffeine'));
            });

            foreach ($result['errors'] as $error) {
                Log::channel('caffeine')->error(sprintf('Caffeine “%s”: %s', $index->handle, $error));
            }

            if (!$this->publish) {
                return;
            }

            $this->setProgress(50, t('Publishing artifact', category: 'caffeine'));

            $published = $artifacts->publish($index);

            $this->setProgress(100);

            Log::channel('caffeine')->info(
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
            );
        } finally {
            $lock->release();
        }
    }

    /**
     * Another run holds the lock. Come back shortly rather than dropping the work.
     *
     * A fresh delayed job rather than `$this->release()`: Craft 6 marks a released job's
     * progress row as done, so the retry would be invisible in the queue manager.
     */
    private function requeue(): void
    {
        if ($this->attempt >= self::MAX_ATTEMPTS) {
            Log::channel('caffeine')->warning(
                sprintf('Caffeine gave up waiting for the update lock on “%s”.', $this->indexUid),
            );

            return;
        }

        try {
            static::dispatch($this->indexUid, $this->publish, $this->attempt + 1)->delay(self::RETRY_DELAY);
        } catch (Throwable $e) {
            Log::channel('caffeine')->error('Caffeine could not requeue an update: ' . $e->getMessage());
        }
    }

    protected function defaultDescription(): string
    {
        $index = app(Indexes::class)->getByUid($this->indexUid);

        return t('Updating the “{index}” search index', [
            'index' => $index->name ?? $this->indexUid,
        ], category: 'caffeine');
    }
}
