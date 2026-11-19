<?php

declare(strict_types=1);

namespace JustinHoltWeb\Caffeine\Gql;

use CraftCms\Cms\Gql\GqlEntityRegistry;
use GraphQL\Language\AST\BooleanValueNode;
use GraphQL\Language\AST\FloatValueNode;
use GraphQL\Language\AST\IntValueNode;
use GraphQL\Language\AST\ListValueNode;
use GraphQL\Language\AST\Node;
use GraphQL\Language\AST\ObjectValueNode;
use GraphQL\Language\AST\StringValueNode;
use GraphQL\Type\Definition\ScalarType;

/**
 * Any JSON value. Hits, facet counts and refinements are shaped by each index's definition, so
 * they can't be described by a fixed GraphQL type. Craft core has no JSON scalar.
 */
final class JsonType extends ScalarType
{
    public const string NAME = 'CaffeineJSON';

    public string $name = self::NAME;

    public ?string $description = 'Any JSON value: an object, array, string, number, boolean or null.';

    public static function getType(): self
    {
        return GqlEntityRegistry::getOrCreate(self::NAME, fn () => new self);
    }

    public function serialize($value): mixed
    {
        return $value;
    }

    /** Variables arrive already decoded. */
    public function parseValue($value): mixed
    {
        return $value;
    }

    /**
     * An inline literal, such as `refinements: {colour: ["red"]}`.
     *
     * @param  array<string, mixed>|null  $variables
     */
    public function parseLiteral(Node $valueNode, ?array $variables = null): mixed
    {
        return match (true) {
            $valueNode instanceof StringValueNode, $valueNode instanceof BooleanValueNode => $valueNode->value,
            $valueNode instanceof IntValueNode => (int) $valueNode->value,
            $valueNode instanceof FloatValueNode => (float) $valueNode->value,
            $valueNode instanceof ListValueNode => array_map(
                fn (Node $child) => $this->parseLiteral($child, $variables),
                iterator_to_array($valueNode->values),
            ),
            $valueNode instanceof ObjectValueNode => array_combine(
                array_map(fn ($field) => $field->name->value, iterator_to_array($valueNode->fields)),
                array_map(fn ($field) => $this->parseLiteral($field->value, $variables), iterator_to_array($valueNode->fields)),
            ),
            default => null,
        };
    }
}
