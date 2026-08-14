<?php

namespace justinholtweb\caffeine\sources;

use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use justinholtweb\caffeine\models\SourceDefinition;
use Throwable;

/**
 * Entries, scoped by section and entry type.
 */
class EntrySource extends BaseSource
{
    public static function handle(): string
    {
        return 'entry';
    }

    public static function displayName(): string
    {
        return 'Entries';
    }

    public static function elementType(): string
    {
        return Entry::class;
    }

    public function containerOptions(): array
    {
        $options = [];

        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            $options[$section->handle] = $section->name;
        }

        return $options;
    }

    public function subTypeOptions(): array
    {
        $options = [];

        foreach (Craft::$app->getEntries()->getAllEntryTypes() as $entryType) {
            $options[$entryType->handle] = $entryType->name;
        }

        return $options;
    }

    public function query(SourceDefinition $definition, int $siteId): ElementQueryInterface
    {
        $query = Entry::find()
            ->siteId($siteId)
            // Ordered by id so a build walks records in a stable order across runs. Anything
            // that changes between builds — post date, structure position — would reshuffle
            // batches and make an interrupted build hard to resume.
            ->orderBy(['elements.id' => SORT_ASC]);

        if ($definition->containers !== []) {
            $query->section($definition->containers);
        }

        if ($definition->subTypes !== []) {
            $query->type($definition->subTypes);
        }

        return $this->applyStatus($query, $definition);
    }

    public function covers(SourceDefinition $definition, ElementInterface $element): bool
    {
        if (!$element instanceof Entry) {
            return false;
        }

        try {
            $section = $element->getSection();
        } catch (Throwable) {
            // Entries without a section are nested — Matrix blocks and the like. They are
            // content *within* an indexed entry, never records in their own right; the
            // dependency map is what carries their changes through.
            return false;
        }

        if ($section === null) {
            return false;
        }

        if ($definition->containers !== [] && !in_array($section->handle, $definition->containers, true)) {
            return false;
        }

        if ($definition->subTypes !== []) {
            try {
                $typeHandle = $element->getType()->handle;
            } catch (Throwable) {
                return false;
            }

            if (!in_array($typeHandle, $definition->subTypes, true)) {
                return false;
            }
        }

        return $this->matchesStatus($definition, $element);
    }

    private function matchesStatus(SourceDefinition $definition, Entry $entry): bool
    {
        if ($entry->getIsDraft() || $entry->getIsRevision()) {
            return false;
        }

        return match ($definition->status) {
            'any' => true,
            'enabled' => $entry->enabled && $entry->getEnabledForSite(),
            default => $entry->getStatus() === Entry::STATUS_LIVE,
        };
    }
}
