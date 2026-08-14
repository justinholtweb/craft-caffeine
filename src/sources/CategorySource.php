<?php

namespace justinholtweb\caffeine\sources;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Category;
use craft\elements\db\ElementQueryInterface;
use justinholtweb\caffeine\models\SourceDefinition;
use Throwable;

/**
 * Categories, scoped to groups.
 *
 * Frequently the *target* of a relation rather than an index of its own — a product index
 * denormalises a category's title into a facet — but a category listing is a real use for a
 * faceted index, and the dependency map handles the other case regardless.
 */
class CategorySource extends BaseSource
{
    public static function handle(): string
    {
        return 'category';
    }

    public static function displayName(): string
    {
        return Craft::t('caffeine', 'Categories');
    }

    public static function elementType(): string
    {
        return Category::class;
    }

    public function containerOptions(): array
    {
        $options = [];

        foreach (Craft::$app->getCategories()->getAllGroups() as $group) {
            $options[$group->handle] = $group->name;
        }

        return $options;
    }

    public function query(SourceDefinition $definition, int $siteId): ElementQueryInterface
    {
        $query = Category::find()
            ->siteId($siteId)
            ->orderBy(['elements.id' => SORT_ASC]);

        if ($definition->containers !== []) {
            $query->group($definition->containers);
        }

        return $this->applyStatus($query, $definition);
    }

    public function covers(SourceDefinition $definition, ElementInterface $element): bool
    {
        if (!$element instanceof Category) {
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
        // No such thing as a live category — only enabled or not.
        return $status === 'any' ? null : 'enabled';
    }
}
