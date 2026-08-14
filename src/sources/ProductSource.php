<?php

namespace justinholtweb\caffeine\sources;

use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use justinholtweb\caffeine\models\SourceDefinition;
use Throwable;

/**
 * Commerce products, scoped to product types.
 *
 * Written against class strings rather than imports so this file loads on a site without
 * Commerce — `isAvailable()` is what keeps it out of the registry there, and it cannot do that
 * job if merely autoloading the class fatals.
 */
class ProductSource extends BaseSource
{
    private const PRODUCT = 'craft\\commerce\\elements\\Product';
    private const COMMERCE = 'craft\\commerce\\Plugin';

    public static function handle(): string
    {
        return 'product';
    }

    public static function displayName(): string
    {
        return Craft::t('caffeine', 'Products');
    }

    public static function elementType(): string
    {
        return self::PRODUCT;
    }

    public static function isAvailable(): bool
    {
        return class_exists(self::PRODUCT)
            && class_exists(self::COMMERCE)
            && Craft::$app->getPlugins()->isPluginEnabled('commerce');
    }

    public function containerOptions(): array
    {
        $options = [];

        try {
            $types = (self::COMMERCE)::getInstance()->getProductTypes()->getAllProductTypes();
        } catch (Throwable) {
            return [];
        }

        foreach ($types as $type) {
            $options[$type->handle] = $type->name;
        }

        return $options;
    }

    public function query(SourceDefinition $definition, int $siteId): ElementQueryInterface
    {
        /** @var ElementQueryInterface $query */
        $query = (self::PRODUCT)::find()
            ->siteId($siteId)
            ->orderBy(['elements.id' => SORT_ASC]);

        if ($definition->containers !== []) {
            $query->type($definition->containers);
        }

        return $this->applyStatus($query, $definition);
    }

    public function covers(SourceDefinition $definition, ElementInterface $element): bool
    {
        if (!$element instanceof (self::PRODUCT)) {
            return false;
        }

        if ($definition->containers !== []) {
            try {
                $handle = $element->getType()->handle;
            } catch (Throwable) {
                return false;
            }

            if (!in_array($handle, $definition->containers, true)) {
                return false;
            }
        }

        return $this->coversStatus($definition, $element);
    }
}
