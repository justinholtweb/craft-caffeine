<?php

namespace justinholtweb\caffeine\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use Illuminate\Support\Collection;
use justinholtweb\caffeine\extractors\ExtractedValue;
use justinholtweb\caffeine\helpers\ValueHelper;
use justinholtweb\caffeine\models\AttributeDefinition;
use justinholtweb\caffeine\models\IndexDefinition;
use justinholtweb\caffeine\models\MappedRecord;
use justinholtweb\caffeine\models\MappingContext;
use justinholtweb\caffeine\Plugin;
use Throwable;

/**
 * Turns an element into a record, following each attribute's path into the content.
 *
 * The path language is deliberately small — dotted segments, descending through fields and
 * related elements — because it is configured in the control panel by someone who should not
 * have to write code, and because every extra capability here is one the browser-side engine
 * would eventually have to understand too.
 */
class Mapper extends Component
{
    /** Guard against a relation loop turning one record into an infinite walk. */
    private const MAX_DEPTH = 6;

    /**
     * Materialising a relation is where an index build can quietly become O(n²). A product
     * with a category field that happens to hold 40,000 entries would pull all of them in to
     * produce one facet value, so relations are capped and the overflow logged rather than
     * silently truncated.
     */
    private const MAX_RELATED = 200;

    public function map(IndexDefinition $index, ElementInterface $element): MappedRecord
    {
        $context = new MappingContext($element, (int)$element->siteId);

        $record = new MappedRecord(
            elementId: (int)$element->id,
            siteId: (int)$element->siteId,
            objectId: $this->objectId($element),
        );

        foreach ($index->attributes as $attribute) {
            $raw = $this->extract($element, $attribute, $context);

            // Not unwrapped. `facetValues()` and `sortableValue()` flatten again internally and
            // take the primary, while `payloadValue()` and `searchableText()` want the named
            // parts — a card wants a link's text as well as its URL. Unwrapping here would throw
            // the parts away before either of them could ask.
            $values = ValueHelper::applyTransforms(ValueHelper::flatten($raw, false), $attribute->transforms);

            if ($attribute->isFacet()) {
                if ($attribute->facetType === AttributeDefinition::FACET_GEO) {
                    $point = ValueHelper::geoValue($values);

                    if ($point !== null) {
                        $record->geo[$attribute->key] = $point;
                    }
                } else {
                    $record->facets[$attribute->key] = ValueHelper::facetValues($values, $attribute);
                }
            }

            if ($attribute->isSortable()) {
                $record->sortable[$attribute->key] = ValueHelper::sortableValue($values);
            }

            if ($attribute->isPayload()) {
                $record->payload[$attribute->key] = ValueHelper::payloadValue($values);
            }

            if ($attribute->isSearchable()) {
                $record->searchable[$attribute->key] = ValueHelper::searchableText($values);
            }
        }

        $record->dependencies = $context->dependencies();

        return $record;
    }

    /**
     * The record's stable public id.
     *
     * Element id plus site id, because one element is several records in a multi-site install
     * and a hit has to say which one it is.
     */
    public function objectId(ElementInterface $element): string
    {
        return $element->id . '-' . $element->siteId;
    }

    /**
     * Reads an attribute's value off an element, descending its dotted path.
     */
    private function extract(ElementInterface $element, AttributeDefinition $attribute, MappingContext $context): mixed
    {
        $segments = array_values(array_filter(explode('.', $attribute->path), fn($s) => $s !== ''));

        if ($segments === []) {
            return null;
        }

        $first = array_shift($segments);
        $value = $attribute->source === AttributeDefinition::SOURCE_FIELD
            ? $this->fieldValue($element, $first)
            : $this->attributeValue($element, $first);

        return $this->descend($value, $segments, $context, 0);
    }

    /**
     * Walks the remaining path segments through whatever the last one produced.
     */
    private function descend(mixed $value, array $segments, MappingContext $context, int $depth): mixed
    {
        $value = $this->materialize($value, $context, $depth);

        if ($segments === [] || $value === null) {
            return $value;
        }

        if ($depth >= self::MAX_DEPTH) {
            return null;
        }

        $segment = array_shift($segments);
        // Not unwrapped: an extracted value is exactly what the next segment descends into.
        $holders = ValueHelper::flatten($value, false);
        $results = [];

        foreach ($holders as $holder) {
            if ($holder instanceof ExtractedValue) {
                // `location.city`, `banner.text`, `price.currency`. The extractor named these
                // parts; the path just asks for one.
                if ($holder->has($segment)) {
                    $results[] = $this->descend($holder->part($segment), $segments, $context, $depth + 1);
                }

                continue;
            }

            if (!$holder instanceof ElementInterface) {
                // A path can only descend through elements and extracted values. Reaching a
                // scalar with segments still to go means the path is wrong — usually a field
                // handle typo — and the CP's record preview is where that gets noticed.
                continue;
            }

            $next = $this->hasField($holder, $segment)
                ? $this->fieldValue($holder, $segment)
                : $this->attributeValue($holder, $segment);

            $results[] = $this->descend($next, $segments, $context, $depth + 1);
        }

        return $results;
    }

    /**
     * Resolves lazy values — element queries and collections — into arrays, recording every
     * element seen as a dependency.
     *
     * Recurses into arrays because `flatten()` deliberately no longer resolves anything: a
     * Matrix block's relation field arrives as a query nested inside the array of blocks, and
     * nothing further down the chain would know to execute it.
     */
    private function materialize(mixed $value, MappingContext $context, int $depth): mixed
    {
        if (is_array($value)) {
            return array_map(fn($item) => $this->materialize($item, $context, $depth), $value);
        }

        if ($value instanceof ElementQueryInterface) {
            $elements = $value->limit(self::MAX_RELATED)->all();

            if (count($elements) === self::MAX_RELATED) {
                Craft::warning(
                    sprintf('A relation hit the %d-element cap while indexing; the rest were skipped.', self::MAX_RELATED),
                    Plugin::LOG_CATEGORY,
                );
            }

            foreach ($elements as $related) {
                $context->dependOn($related);
            }

            return $elements;
        }

        if ($value instanceof Collection) {
            $items = $value->all();

            foreach ($items as $item) {
                if ($item instanceof ElementInterface) {
                    $context->dependOn($item);
                }
            }

            return $items;
        }

        if ($value instanceof ElementInterface) {
            $context->dependOn($value);

            return $value;
        }

        // Everything else that is still an object — a link, a price, an address, a colour — gets
        // one last chance to say what it is. Without this it reaches `ValueHelper` as an opaque
        // object and indexes as whatever `__toString()` gives, which is frequently nothing.
        return Plugin::getInstance()->extractors->expand($value);
    }

    private function attributeValue(ElementInterface $element, string $name): mixed
    {
        try {
            return $element->$name;
        } catch (Throwable) {
            return null;
        }
    }

    private function fieldValue(ElementInterface $element, string $handle): mixed
    {
        try {
            return $element->getFieldValue($handle);
        } catch (Throwable) {
            return null;
        }
    }

    private function hasField(ElementInterface $element, string $handle): bool
    {
        try {
            return $element->getFieldLayout()?->getFieldByHandle($handle) !== null;
        } catch (Throwable) {
            return false;
        }
    }
}
