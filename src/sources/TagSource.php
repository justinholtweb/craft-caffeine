<?php

namespace justinholtweb\caffeine\sources;

use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Tag;
use justinholtweb\caffeine\models\SourceDefinition;
use Throwable;

/**
 * Tags, scoped to groups.
 */
class TagSource extends BaseSource
{
    public static function handle(): string
    {
        return 'tag';
    }

    public static function displayName(): string
    {
        return Craft::t('caffeine', 'Tags');
    }

    public static function elementType(): string
    {
        return Tag::class;
    }

    public function containerOptions(): array
    {
        $options = [];

        foreach (Craft::$app->getTags()->getAllTagGroups() as $group) {
            $options[$group->handle] = $group->name;
        }

        return $options;
    }

    public function query(SourceDefinition $definition, int $siteId): ElementQueryInterface
    {
        $query = Tag::find()
            ->siteId($siteId)
            ->orderBy(['elements.id' => SORT_ASC]);

        if ($definition->containers !== []) {
            $query->group($definition->containers);
        }

        return $this->applyStatus($query, $definition);
    }

    public function covers(SourceDefinition $definition, ElementInterface $element): bool
    {
        if (!$element instanceof Tag) {
            return false;
        }

        if ($definition->containers !== []) {
            try {
                $group = $element->getGroup();
            } catch (Throwable) {
                return false;
            }

            if (!in_array($group->handle, $definition->containers, true)) {
                return false;
            }
        }

        return $this->coversStatus($definition, $element);
    }

    protected function statusFor(string $status): ?string
    {
        return $status === 'any' ? null : 'enabled';
    }
}
