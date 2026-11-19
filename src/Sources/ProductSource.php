<?php

namespace JustinHoltWeb\Caffeine\Sources;

use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Element\Queries\Contracts\ElementQueryInterface;
use CraftCms\Cms\Support\Facades\Plugins;
use JustinHoltWeb\Caffeine\Models\SourceDefinition;
use Throwable;

use function CraftCms\Cms\t;

/**
 * Commerce products, scoped to product types.
 *
 * Written against class strings rather than imports so this file loads on a site without
 * Commerce — `isAvailable()` is what keeps it out of the registry there, and it cannot do that
 * job if merely autoloading the class fatals.
 *
 * TODO(commerce-6): Commerce 6 is not installable yet, so the class names below are a guess
 * following Craft 6's `<Domain>\Elements\<Element>` layout, and `containerOptions()` guesses at
 * the product-types API. Until they are confirmed against Commerce 6, `isAvailable()` simply
 * returns false (the classes do not exist) and the source never appears. Verify all three when
 * Commerce 6 ships: the element class, the plugin class, and how product types are listed.
 */
class ProductSource extends BaseSource
{
    /** TODO(commerce-6): confirm. Was `craft\commerce\elements\Product` in Commerce 5. */
    private const PRODUCT = 'CraftCms\\Commerce\\Product\\Elements\\Product';

    /** TODO(commerce-6): confirm. Was `craft\commerce\Plugin` in Commerce 5. */
    private const COMMERCE = 'CraftCms\\Commerce\\Commerce';

    public static function handle(): string
    {
        return 'product';
    }

    public static function displayName(): string
    {
        return t('Products', category: 'caffeine');
    }

    public static function elementType(): string
    {
        return self::PRODUCT; // @phpstan-ignore return.type (Commerce is optional, so the class is named by string)
    }

    public static function isAvailable(): bool
    {
        return class_exists(self::PRODUCT)
            && class_exists(self::COMMERCE)
            && Plugins::isPluginEnabled('commerce');
    }

    public function containerOptions(): array
    {
        $options = [];

        try {
            // TODO(commerce-6): the Commerce 5 API, kept until Commerce 6's is known. Failing
            // here costs only the CP's container list, never the build.
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
            ->orderBy('elements.id');

        if ($definition->containers !== []) {
            $query->type($definition->containers); // @phpstan-ignore method.notFound (a Commerce ProductQuery)
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
                $handle = $element->getType()->handle; // @phpstan-ignore class.notFound (Commerce is optional)
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
