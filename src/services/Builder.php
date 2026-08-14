<?php

namespace justinholtweb\caffeine\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use justinholtweb\caffeine\models\IndexDefinition;
use justinholtweb\caffeine\Plugin;
use Throwable;

/**
 * Walks an index's sources and writes records.
 *
 * This is the only place in Caffeine that runs element queries, and it runs them in the
 * background. Everything the front end does afterwards reads the artifact instead — which is
 * the whole point.
 */
class Builder extends Component
{
    /** Elements per batch. Also the eager-loading unit, so it wants to be a real page's worth. */
    private const BATCH_SIZE = 100;

    /**
     * Rebuilds every record for an index.
     *
     * @param callable(int, int): void|null $progress Called with (done, total).
     * @return array{records: int, pruned: int, errors: list<string>}
     */
    public function buildAll(IndexDefinition $index, ?callable $progress = null): array
    {
        $records = 0;
        $errors = [];
        $seenElementIds = [];
        $total = $this->countElements($index);

        foreach ($this->siteIds($index) as $siteId) {
            foreach (Plugin::getInstance()->sources->resolve($index) as ['source' => $source, 'definition' => $definition]) {
                $query = $source->query($definition, $siteId);

                foreach ($query->batch(self::BATCH_SIZE) as $elements) {
                    foreach ($elements as $element) {
                        $seenElementIds[$element->id] = true;

                        try {
                            $this->buildOne($index, $element);
                            $records++;
                        } catch (Throwable $e) {
                            $errors[] = sprintf('Element %d: %s', $element->id, $e->getMessage());

                            Craft::error(
                                sprintf('Caffeine failed to index element %d for “%s”: %s', $element->id, $index->handle, $e->getMessage()),
                                Plugin::LOG_CATEGORY,
                            );
                        }
                    }

                    if ($progress !== null) {
                        $progress($records, $total);
                    }
                }
            }
        }

        // Anything not seen this pass no longer qualifies — moved section, disabled, deleted.
        // Without this an index only ever grows.
        $pruned = Plugin::getInstance()->records->pruneMissing($index->uid, array_map('intval', array_keys($seenElementIds)));

        return ['records' => $records, 'pruned' => $pruned, 'errors' => $errors];
    }

    /**
     * Rebuilds only the records marked dirty.
     *
     * @param callable(int, int): void|null $progress Called with (done, total).
     * @return array{records: int, removed: int, errors: list<string>}
     */
    public function buildDirty(IndexDefinition $index, ?callable $progress = null): array
    {
        $elementIds = Plugin::getInstance()->records->dirtyElementIds($index->uid);

        if ($elementIds === []) {
            return ['records' => 0, 'removed' => 0, 'errors' => []];
        }

        return $this->buildElements($index, $elementIds, $progress);
    }

    /**
     * Rebuilds specific elements, removing any that no longer belong in the index.
     *
     * @param list<int> $elementIds
     * @param callable(int, int): void|null $progress Called with (done, total).
     * @return array{records: int, removed: int, errors: list<string>}
     */
    public function buildElements(IndexDefinition $index, array $elementIds, ?callable $progress = null): array
    {
        $records = 0;
        $removed = 0;
        $errors = [];
        $sources = Plugin::getInstance()->sources;

        $siteIds = $this->siteIds($index);
        $total = max(1, count($elementIds) * count($siteIds));
        $done = 0;

        foreach ($siteIds as $siteId) {
            foreach (array_chunk($elementIds, self::BATCH_SIZE) as $chunk) {
                foreach ($this->loadElements($index, $chunk, $siteId) as $element) {
                    try {
                        // An element can stop qualifying without being deleted — moved to
                        // another section, or disabled. Rebuilding it blindly would leave a
                        // live record for content that should have vanished from the index.
                        if (!$sources->covers($index, $element)) {
                            $removed += Plugin::getInstance()->records->markDeleted($index->uid, [(int)$element->id]);
                            continue;
                        }

                        $this->buildOne($index, $element);
                        $records++;
                    } catch (Throwable $e) {
                        $errors[] = sprintf('Element %d: %s', $element->id, $e->getMessage());
                    }
                }

                $done += count($chunk);

                if ($progress !== null) {
                    $progress(min($done, $total), $total);
                }
            }
        }

        // Ids that produced no element at all in any site are gone from Craft entirely.
        $missing = $this->missingElementIds($index, $elementIds);

        if ($missing !== []) {
            $removed += Plugin::getInstance()->records->markDeleted($index->uid, $missing);
        }

        return ['records' => $records, 'removed' => $removed, 'errors' => $errors];
    }

    public function buildOne(IndexDefinition $index, ElementInterface $element): void
    {
        $record = Plugin::getInstance()->mapper->map($index, $element);

        Plugin::getInstance()->records->save($index, $record);
    }

    /**
     * @param list<int> $elementIds
     * @return ElementInterface[]
     */
    private function loadElements(IndexDefinition $index, array $elementIds, int $siteId): array
    {
        $elements = [];

        foreach (Plugin::getInstance()->sources->resolve($index) as ['source' => $source, 'definition' => $definition]) {
            $query = $source->query($definition, $siteId);
            $query->id($elementIds);
            // The definition's status filter is deliberately dropped here: this pass has to be
            // able to see an element that has just been disabled, precisely so it can notice
            // and remove it. `covers()` re-applies the status check per element.
            $query->status(null);

            foreach ($query->all() as $element) {
                $elements[$element->id . '-' . $element->siteId] = $element;
            }
        }

        return array_values($elements);
    }

    /**
     * @param list<int> $elementIds
     * @return list<int>
     */
    private function missingElementIds(IndexDefinition $index, array $elementIds): array
    {
        $found = [];

        foreach ($this->siteIds($index) as $siteId) {
            foreach (Plugin::getInstance()->sources->resolve($index) as ['source' => $source, 'definition' => $definition]) {
                $query = $source->query($definition, $siteId);
                $query->id($elementIds);
                $query->status(null);

                foreach ($query->ids() as $id) {
                    $found[$id] = true;
                }
            }
        }

        return array_values(array_diff($elementIds, array_map('intval', array_keys($found))));
    }

    /**
     * @return list<int>
     */
    public function siteIds(IndexDefinition $index): array
    {
        if ($index->siteIds !== []) {
            return $index->siteIds;
        }

        return array_map(
            fn($site) => (int)$site->id,
            Craft::$app->getSites()->getAllSites(),
        );
    }

    public function countElements(IndexDefinition $index): int
    {
        $total = 0;

        foreach ($this->siteIds($index) as $siteId) {
            foreach (Plugin::getInstance()->sources->resolve($index) as ['source' => $source, 'definition' => $definition]) {
                $total += (int)$source->query($definition, $siteId)->count();
            }
        }

        return $total;
    }
}
