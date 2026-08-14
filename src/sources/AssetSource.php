<?php

namespace justinholtweb\caffeine\sources;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\Assets;
use justinholtweb\caffeine\models\SourceDefinition;
use Throwable;

/**
 * Assets, scoped to volumes and optionally to file kinds.
 *
 * File kind stands in for the sub-type an asset does not otherwise have, which makes
 * "everything in Documents that is a PDF" expressible without a custom field.
 */
class AssetSource extends BaseSource
{
    public static function handle(): string
    {
        return 'asset';
    }

    public static function displayName(): string
    {
        return Craft::t('caffeine', 'Assets');
    }

    public static function elementType(): string
    {
        return Asset::class;
    }

    public function containerOptions(): array
    {
        $options = [];

        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            $options[$volume->handle] = $volume->name;
        }

        return $options;
    }

    public function subTypeOptions(): array
    {
        $options = [];

        foreach (Assets::getFileKinds() as $kind => $info) {
            $options[$kind] = $info['label'] ?? $kind;
        }

        return $options;
    }

    public function query(SourceDefinition $definition, int $siteId): ElementQueryInterface
    {
        $query = Asset::find()
            ->siteId($siteId)
            ->orderBy(['elements.id' => SORT_ASC]);

        if ($definition->containers !== []) {
            $query->volume($definition->containers);
        }

        if ($definition->subTypes !== []) {
            $query->kind($definition->subTypes);
        }

        return $this->applyStatus($query, $definition);
    }

    public function covers(SourceDefinition $definition, ElementInterface $element): bool
    {
        if (!$element instanceof Asset) {
            return false;
        }

        if ($definition->containers !== []) {
            try {
                $volume = $element->getVolume();
            } catch (Throwable) {
                return false;
            }

            if (!in_array($volume->handle, $definition->containers, true)) {
                return false;
            }
        }

        if ($definition->subTypes !== [] && !in_array($element->kind, $definition->subTypes, true)) {
            return false;
        }

        return $this->coversStatus($definition, $element);
    }

    protected function statusFor(string $status): ?string
    {
        return $status === 'any' ? null : 'enabled';
    }
}
