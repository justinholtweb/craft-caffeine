<?php

namespace justinholtweb\caffeine\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\caffeine\models\IndexDefinition;
use justinholtweb\caffeine\Plugin;
use Throwable;
use yii\console\ExitCode;

/**
 * Publishing artifacts and inspecting what is live.
 *
 * Separate from `index`, which deals in records. The two halves of a build are genuinely
 * separate operations — records are rebuilt whenever content changes, artifacts are cut when
 * that should become visible — and a deploy script usually wants to run them at different
 * moments.
 */
class ArtifactController extends Controller
{
    public $defaultAction = 'status';

    /** Publish even when the compiled artifact is identical to what is already live. */
    public bool $force = false;

    public function options($actionID): array
    {
        $options = parent::options($actionID);

        if ($actionID === 'publish') {
            $options[] = 'force';
        }

        return $options;
    }

    /**
     * Compiles and publishes one index, or every index when no handle is given.
     */
    public function actionPublish(?string $handle = null): int
    {
        $indexes = $this->resolveIndexes($handle);

        if ($indexes === []) {
            $this->stderr("No matching index.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $plugin = Plugin::getInstance();
        $failed = false;

        foreach ($indexes as $index) {
            if (!$plugin->indexes->isAllowed($index)) {
                $this->stdout("Skipping “{$index->handle}” — not available on this edition.\n", Console::FG_YELLOW);
                continue;
            }

            if ($this->force) {
                // Spends a version deliberately. The usual reason is that something outside
                // Caffeine ate the published files — a wiped web root, a new environment — and
                // the checksum match would otherwise convince it there was nothing to do.
                $plugin->artifacts->forget($index);
            }

            $this->stdout("Publishing “{$index->handle}”…\n", Console::FG_CYAN);

            try {
                $result = $plugin->artifacts->publish($index);
            } catch (Throwable $e) {
                $this->stderr("  {$e->getMessage()}\n", Console::FG_RED);
                $failed = true;
                continue;
            }

            if (!$result['published']) {
                $this->stdout(sprintf(
                    "  Unchanged — v%d is still current (%d records).\n",
                    $result['version'],
                    $result['records'],
                ), Console::FG_YELLOW);

                continue;
            }

            $this->stdout(sprintf(
                "  v%d — %d records, %s, %dms%s\n",
                $result['version'],
                $result['records'],
                $this->formatBytes($result['bytes']),
                $result['buildMs'],
                $result['pruned'] > 0 ? sprintf(', %d version(s) pruned', $result['pruned']) : '',
            ), Console::FG_GREEN);

            if ($result['url'] !== null) {
                $this->stdout("  {$result['url']}\n", Console::FG_GREY);
            }
        }

        return $failed ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Shows what is published for each index, and whether it is behind the CMS.
     */
    public function actionStatus(?string $handle = null): int
    {
        $indexes = $this->resolveIndexes($handle);

        if ($indexes === []) {
            $this->stdout("No indexes are defined yet.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $plugin = Plugin::getInstance();

        foreach ($indexes as $index) {
            $status = $plugin->artifacts->status($index);

            $this->stdout($index->handle, Console::FG_CYAN, Console::BOLD);
            $this->stdout("  {$index->name}\n");

            if ($status['version'] === null) {
                $this->stdout("  Never published.\n", Console::FG_YELLOW);
            } else {
                $this->stdout(sprintf(
                    "  v%d  %d records  %s  built in %dms  %s\n",
                    $status['version'],
                    $status['records'],
                    $this->formatBytes($status['bytes']),
                    $status['buildMs'] ?? 0,
                    $status['publishedAt'] ?? '',
                ));
            }

            if ($status['dirty'] > 0) {
                $this->stdout(sprintf(
                    "  %d record(s) changed since — run caffeine/index/build then caffeine/artifact/publish.\n",
                    $status['dirty'],
                ), Console::FG_YELLOW);
            }

            if ($status['url'] !== null) {
                $this->stdout("  {$status['url']}\n", Console::FG_GREY);
            }
        }

        $this->stdout("\nPublishing to " . $plugin->publisher->store()->describe() . "\n", Console::FG_GREY);

        return ExitCode::OK;
    }

    /**
     * Lists an index's recent versions.
     */
    public function actionVersions(string $handle): int
    {
        $index = Plugin::getInstance()->indexes->getByHandle($handle);

        if ($index === null) {
            $this->stderr("No index with the handle “{$handle}”.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $versions = Plugin::getInstance()->artifacts->versions($index->uid);

        if ($versions === []) {
            $this->stdout("Never published.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        foreach ($versions as $row) {
            $this->stdout(sprintf(
                "  v%-5d %-11s %7d records  %10s  %6dms  %s\n",
                $row['version'],
                $row['status'],
                $row['recordCount'],
                $this->formatBytes((int)$row['byteSize']),
                (int)$row['buildMs'],
                $row['dateCreated'],
            ), $row['status'] === 'live' ? Console::FG_GREEN : Console::FG_GREY);

            if (!empty($row['error'])) {
                $this->stderr("        {$row['error']}\n", Console::FG_RED);
            }
        }

        return ExitCode::OK;
    }

    /**
     * Checks that what is published matches what the stored records would compile to.
     *
     * Reads the artifact back out of the store, decodes it, and compares it against a fresh
     * compile — so it exercises the encoder, the decoder and the publisher against real content
     * rather than fixtures. Answers the operational question too: is what visitors are being
     * served actually current?
     */
    public function actionVerify(string $handle): int
    {
        $plugin = Plugin::getInstance();
        $index = $plugin->indexes->getByHandle($handle);

        if ($index === null) {
            $this->stderr("No index with the handle “{$handle}”.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $published = $plugin->artifacts->published($index);

        if ($published === null) {
            $this->stderr("Nothing is published for “{$handle}”.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $fresh = $plugin->artifacts->compile($index, $published->version);
        $matches = $published->toArray() === $fresh->toArray();

        $this->stdout(sprintf("  v%d, %d records\n", $published->version, $published->recordCount()));

        if ($matches) {
            $this->stdout("  Published artifact matches a fresh compile.\n", Console::FG_GREEN);
            return ExitCode::OK;
        }

        $this->stderr("  Published artifact differs from a fresh compile.\n", Console::FG_RED);

        foreach (array_keys($fresh->toArray()) as $section) {
            if (($published->toArray()[$section] ?? null) !== $fresh->toArray()[$section]) {
                $this->stderr("    {$section} differs\n", Console::FG_RED);
            }
        }

        $this->stdout("  Run caffeine/index/build then caffeine/artifact/publish.\n", Console::FG_YELLOW);

        return ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Retires versions beyond the `keepVersions` setting and deletes the files only they used.
     */
    public function actionPrune(?string $handle = null): int
    {
        $indexes = $this->resolveIndexes($handle);
        $total = 0;

        foreach ($indexes as $index) {
            $pruned = Plugin::getInstance()->artifacts->prune($index);
            $total += $pruned;

            $this->stdout(sprintf("  %s — %d version(s) retired\n", $index->handle, $pruned));
        }

        $this->stdout(sprintf("%d version(s) retired in total.\n", $total), Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * @return list<IndexDefinition>
     */
    private function resolveIndexes(?string $handle): array
    {
        $indexes = Plugin::getInstance()->indexes;

        if ($handle === null) {
            return $indexes->all();
        }

        $index = $indexes->getByHandle($handle);

        return $index === null ? [] : [$index];
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return "{$bytes} B";
        }

        if ($bytes < 1024 * 1024) {
            return sprintf('%.1f KB', $bytes / 1024);
        }

        return sprintf('%.2f MB', $bytes / 1024 / 1024);
    }
}
