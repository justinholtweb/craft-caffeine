<?php

namespace justinholtweb\caffeine\models;

use craft\base\Model;

/**
 * One key in an index's records, and what Caffeine is allowed to do with it.
 *
 * Roles are deliberately not mutually exclusive. A product's title is usually `searchable`,
 * `sortable` and `payload` all at once; its brand is usually `facet` and `payload`. Modelling
 * them as a set rather than a single "type" is what keeps the CP screen honest about the fact
 * that one field feeds several parts of the artifact.
 */
class AttributeDefinition extends Model
{
    /** Text is tokenised into the artifact's inverted index. */
    public const ROLE_SEARCHABLE = 'searchable';

    /** Values become a refinable facet. */
    public const ROLE_FACET = 'facet';

    /** A sort order over this key is precomputed into the artifact. */
    public const ROLE_SORTABLE = 'sortable';

    /** The value rides along on the hit so Twig can render a card without a database round trip. */
    public const ROLE_PAYLOAD = 'payload';

    public const ROLES = [
        self::ROLE_SEARCHABLE,
        self::ROLE_FACET,
        self::ROLE_SORTABLE,
        self::ROLE_PAYLOAD,
    ];

    /** Free-text values: "Acme", "Blue". Refined by exact match. */
    public const FACET_STRING = 'string';

    /** Path values: "Home > Tools > Saws". Refined by level, each level narrowing the last. */
    public const FACET_HIERARCHICAL = 'hierarchical';

    /** Numbers, refined by range and bucketed for display. */
    public const FACET_NUMERIC = 'numeric';

    /** True/false. */
    public const FACET_BOOLEAN = 'boolean';

    /** Dates, stored as Unix timestamps so they refine as numbers but format as dates. */
    public const FACET_DATE = 'date';

    /**
     * A coordinate pair, filtered by distance from a point rather than by equality.
     *
     * Never bucketed: interning coordinates would give one facet value per record and a postings
     * list with one id in each — all cost, no use.
     */
    public const FACET_GEO = 'geo';

    public const FACET_TYPES = [
        self::FACET_STRING,
        self::FACET_HIERARCHICAL,
        self::FACET_NUMERIC,
        self::FACET_BOOLEAN,
        self::FACET_DATE,
        self::FACET_GEO,
    ];

    /** Element attributes, not custom fields: `title`, `slug`, `uri`, `postDate`, `id`. */
    public const SOURCE_ATTRIBUTE = 'attribute';

    /** A custom field, optionally with a dotted path into nested content. */
    public const SOURCE_FIELD = 'field';

    /**
     * The key this appears under in a record, and the name refinements use. Never renamed
     * silently: changing it invalidates every bookmarked filter URL on the site.
     */
    public string $key = '';

    /** What the CP and the facet widgets call it. Falls back to the key. */
    public ?string $label = null;

    public string $source = self::SOURCE_ATTRIBUTE;

    /**
     * For `attribute`, the attribute name. For `field`, the field handle, optionally followed
     * by a dotted path into nested content — `specs.material` reaches into a Matrix field.
     */
    public string $path = '';

    /** @var string[] */
    public array $roles = [];

    /**
     * Relative weight when this key matches a full-text query. Only meaningful with
     * `searchable`. A title matching should outrank a body matching.
     */
    public float $searchWeight = 1.0;

    public string $facetType = self::FACET_STRING;

    /**
     * `or` (disjunctive) lets a visitor pick Acme *or* Globex and see both. `and` (conjunctive)
     * requires every picked value. The distinction also changes how counts are computed — see
     * docs/QUERY_SPEC.md, which is the only place the rule is written down.
     */
    public string $facetOperator = 'or';

    /** `count`, `alpha`, or `manual` (honouring `facetValueOrder`). */
    public string $facetSort = 'count';

    /** @var string[] Explicit value ordering when `facetSort` is `manual`. */
    public array $facetValueOrder = [];

    /** How many values the facet returns before "show more". */
    public int $maxValuesPerFacet = 20;

    /**
     * Boundaries for a numeric facet's display buckets, e.g. `[0, 25, 50, 100]`. Refinement is
     * still by exact range; buckets only decide how values are grouped for the widget.
     *
     * @var float[]
     */
    public array $numericBuckets = [];

    /** Separator for hierarchical values, matching Algolia's convention. */
    public string $hierarchySeparator = ' > ';

    /**
     * Ordered list of value transforms applied at index time: `trim`, `lower`, `upper`,
     * `stripTags`, `slug`, `unique`, `first`, `sort`, `compact`, or `date:<format>`.
     *
     * A fixed vocabulary rather than an expression, because this runs over every record on
     * every build and arrives from project config — neither is a place for arbitrary code.
     *
     * @var string[]
     */
    public array $transforms = [];

    public function label(): string
    {
        return $this->label ?: $this->key;
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    public function isSearchable(): bool
    {
        return $this->hasRole(self::ROLE_SEARCHABLE);
    }

    public function isFacet(): bool
    {
        return $this->hasRole(self::ROLE_FACET);
    }

    public function isSortable(): bool
    {
        return $this->hasRole(self::ROLE_SORTABLE);
    }

    public function isPayload(): bool
    {
        return $this->hasRole(self::ROLE_PAYLOAD);
    }

    public function isDisjunctive(): bool
    {
        return $this->facetOperator === 'or';
    }

    /**
     * Whether refinements on this facet are numbers rather than strings. Dates included: they
     * are indexed as Unix timestamps so a date facet is a numeric one that formats differently.
     */
    public function isNumericFacet(): bool
    {
        return in_array($this->facetType, [self::FACET_NUMERIC, self::FACET_DATE], true);
    }

    protected function defineRules(): array
    {
        return [
            [['key', 'source', 'path'], 'required'],
            [['key'], 'match', 'pattern' => '/^[a-zA-Z][a-zA-Z0-9_]*$/', 'message' => 'Keys must start with a letter and contain only letters, numbers and underscores.'],
            [['source'], 'in', 'range' => [self::SOURCE_ATTRIBUTE, self::SOURCE_FIELD]],
            [['facetType'], 'in', 'range' => self::FACET_TYPES],
            [['facetOperator'], 'in', 'range' => ['or', 'and']],
            [['facetSort'], 'in', 'range' => ['count', 'alpha', 'manual']],
            [['maxValuesPerFacet'], 'integer', 'min' => 1, 'max' => 1000],
            [['searchWeight'], 'number', 'min' => 0],
            [['roles'], 'validateRoles'],
        ];
    }

    public function validateRoles(string $attribute): void
    {
        if ($this->roles === []) {
            $this->addError($attribute, 'Give the attribute at least one role, or leave it out of the index.');
            return;
        }

        foreach ($this->roles as $role) {
            if (!in_array($role, self::ROLES, true)) {
                $this->addError($attribute, "“{$role}” is not a role Caffeine knows about.");
            }
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromConfig(array $config): self
    {
        return new self([
            'key' => (string)($config['key'] ?? ''),
            'label' => isset($config['label']) ? (string)$config['label'] : null,
            'source' => (string)($config['source'] ?? self::SOURCE_ATTRIBUTE),
            'path' => (string)($config['path'] ?? ''),
            'roles' => array_values(array_filter((array)($config['roles'] ?? []), 'is_string')),
            'searchWeight' => (float)($config['searchWeight'] ?? 1.0),
            'facetType' => (string)($config['facetType'] ?? self::FACET_STRING),
            'facetOperator' => (string)($config['facetOperator'] ?? 'or'),
            'facetSort' => (string)($config['facetSort'] ?? 'count'),
            'facetValueOrder' => array_values((array)($config['facetValueOrder'] ?? [])),
            'maxValuesPerFacet' => (int)($config['maxValuesPerFacet'] ?? 20),
            'numericBuckets' => array_map('floatval', array_values((array)($config['numericBuckets'] ?? []))),
            'hierarchySeparator' => (string)($config['hierarchySeparator'] ?? ' > '),
            'transforms' => array_values(array_filter((array)($config['transforms'] ?? []), 'is_string')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toConfig(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'source' => $this->source,
            'path' => $this->path,
            'roles' => array_values($this->roles),
            'searchWeight' => $this->searchWeight,
            'facetType' => $this->facetType,
            'facetOperator' => $this->facetOperator,
            'facetSort' => $this->facetSort,
            'facetValueOrder' => array_values($this->facetValueOrder),
            'maxValuesPerFacet' => $this->maxValuesPerFacet,
            'numericBuckets' => array_values($this->numericBuckets),
            'hierarchySeparator' => $this->hierarchySeparator,
            'transforms' => array_values($this->transforms),
        ];
    }
}
