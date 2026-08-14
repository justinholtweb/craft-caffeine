<?php

namespace justinholtweb\caffeine\models;

use craft\base\Model;
use craft\helpers\StringHelper;

/**
 * The whole shape of one index: where its records come from, what keys they carry, how those
 * keys behave, and how the front end talks to it.
 *
 * Lives in project config. An index is schema — it should deploy with the code the way a
 * section or a field layout does, not be re-entered by hand in every environment. Records and
 * artifacts are the opposite: derived, environment-local, and never in project config.
 */
class IndexDefinition extends Model
{
    /** Refinements resolve entirely in the browser against the downloaded artifact. */
    public const TRANSPORT_CLIENT = 'client';

    /** Refinements fetch a server-rendered fragment. HTMX-compatible; HTMX not required. */
    public const TRANSPORT_HTMX = 'htmx';

    /** Refinements hit an Algolia-shaped JSON endpoint. For bringing your own InstantSearch. */
    public const TRANSPORT_ALGOLIA = 'algolia-json';

    public const TRANSPORTS = [
        self::TRANSPORT_HTMX,
        self::TRANSPORT_CLIENT,
        self::TRANSPORT_ALGOLIA,
    ];

    public string $uid = '';

    /** Stable identifier used in Twig, in URLs and in the artifact filename. */
    public string $handle = '';

    public string $name = '';

    public ?string $description = null;

    /** @var SourceDefinition[] */
    public array $sources = [];

    /**
     * Sites to build records for. Empty means every site. One record per element per site,
     * because a facet label in French is not the same facet value as its English twin.
     *
     * @var int[]
     */
    public array $siteIds = [];

    /** @var AttributeDefinition[] */
    public array $attributes = [];

    /** @var SortingDefinition[] */
    public array $sortings = [];

    public string $transport = self::TRANSPORT_HTMX;

    public int $hitsPerPage = 24;

    /**
     * Ceiling on values returned per facet before the widget's "show more". Per-attribute
     * settings override this.
     */
    public int $maxValuesPerFacet = 20;

    /**
     * Seconds to wait after content changes before republishing, so a burst of editorial saves
     * costs one build rather than twenty. `0` republishes immediately.
     */
    public int $publishDebounce = 30;

    /**
     * Whether the payload — the card data, which is nearly all the bytes and none of the query
     * machinery — is split into a separate lazily-fetched shard. Worth it once an index is big
     * enough that the visitor should not download 40,000 product descriptions to filter on
     * brand.
     */
    public bool $shardPayload = false;

    public bool $enabled = true;

    /**
     * Words dropped from both documents and queries.
     *
     * Dropped from *both*, which is the part that has to be right. Removing "the" at index time
     * alone would make a search for "the saw" match nothing at all, because matching is
     * conjunctive and a token with no postings empties the result. So the list travels inside the
     * artifact and both engines filter queries through it.
     *
     * @var string[]
     */
    public array $stopwords = [];

    /**
     * Groups of interchangeable words, one group per entry: `sofa, couch, settee`.
     *
     * Expanded at index time rather than query time — a record containing "sofa" is indexed under
     * every word in its group. That costs a little artifact size and buys a great deal of
     * simplicity: neither engine learns what a synonym is, and there is no second map to keep in
     * step across two languages.
     *
     * @var string[]
     */
    public array $synonyms = [];

    /**
     * @return AttributeDefinition[]
     */
    public function facets(): array
    {
        return array_values(array_filter($this->attributes, fn(AttributeDefinition $a) => $a->isFacet()));
    }

    /**
     * @return AttributeDefinition[]
     */
    public function searchableAttributes(): array
    {
        return array_values(array_filter($this->attributes, fn(AttributeDefinition $a) => $a->isSearchable()));
    }

    /**
     * @return AttributeDefinition[]
     */
    public function payloadAttributes(): array
    {
        return array_values(array_filter($this->attributes, fn(AttributeDefinition $a) => $a->isPayload()));
    }

    public function getAttribute(string $key): ?AttributeDefinition
    {
        foreach ($this->attributes as $attribute) {
            if ($attribute->key === $key) {
                return $attribute;
            }
        }

        return null;
    }

    public function getFacet(string $key): ?AttributeDefinition
    {
        $attribute = $this->getAttribute($key);

        return $attribute?->isFacet() ? $attribute : null;
    }

    public function getSorting(string $name): ?SortingDefinition
    {
        foreach ($this->allSortings() as $sorting) {
            if ($sorting->name === $name) {
                return $sorting;
            }
        }

        return null;
    }

    /**
     * Configured sortings with `relevance` guaranteed present at the front.
     *
     * Every index can sort by relevance whether or not anyone configured it, so the query
     * engine always has a default to fall back to and never has to handle "no sortings".
     *
     * @return SortingDefinition[]
     */
    public function allSortings(): array
    {
        $sortings = [];

        foreach ($this->sortings as $sorting) {
            if ($sorting->isRelevance()) {
                continue;
            }

            $sortings[] = $sorting;
        }

        array_unshift($sortings, new SortingDefinition([
            'name' => SortingDefinition::RELEVANCE,
            'label' => 'Relevance',
        ]));

        return $sortings;
    }

    public function defaultSorting(): SortingDefinition
    {
        return $this->allSortings()[0];
    }

    /**
     * @param array<string, mixed> $config
     */
    /**
     * Builds a definition from the control panel's form payload.
     *
     * Lives here rather than in the controller so it can be tested without a request, and so the
     * one rule that matters is written down next to the model it protects: **an existing
     * definition is the starting point, not a blank one.** The CP renders the common per-attribute
     * settings and not the rest — value ordering, numeric buckets, transforms, the hierarchy
     * separator — and a form that rebuilt each attribute from scratch would silently reset every
     * one of them on the first save. Project config and this screen would then be permanently at
     * odds, with the screen always winning.
     *
     * @param array<string, mixed> $body
     */
    public static function fromForm(array $body, ?self $existing = null): self
    {
        $index = new self([
            'uid' => (string)($body['uid'] ?? ''),
            'handle' => trim((string)($body['handle'] ?? '')),
            'name' => trim((string)($body['name'] ?? '')),
            'description' => trim((string)($body['description'] ?? '')) ?: null,
            'transport' => (string)($body['transport'] ?? self::TRANSPORT_HTMX),
            'hitsPerPage' => (int)($body['hitsPerPage'] ?? 24),
            'maxValuesPerFacet' => (int)($body['maxValuesPerFacet'] ?? 20),
            'publishDebounce' => (int)($body['publishDebounce'] ?? 30),
            'shardPayload' => (bool)($body['shardPayload'] ?? false),
            'stopwords' => self::splitList($body['stopwords'] ?? ''),
            'synonyms' => array_values(array_filter(array_map('trim', preg_split('/\R/', (string)($body['synonyms'] ?? '')) ?: []))),
            'enabled' => (bool)($body['enabled'] ?? true),
            'siteIds' => array_map('intval', array_values((array)($body['siteIds'] ?? []))),
        ]);

        foreach (array_values((array)($body['sources'] ?? [])) as $row) {
            if (trim((string)($row['type'] ?? '')) === '') {
                continue;
            }

            $index->sources[] = new SourceDefinition([
                'type' => (string)$row['type'],
                'containers' => self::splitList($row['containers'] ?? ''),
                'subTypes' => self::splitList($row['subTypes'] ?? ''),
                'status' => (string)($row['status'] ?? 'live'),
            ]);
        }

        foreach (array_values((array)($body['attributes'] ?? [])) as $row) {
            $key = trim((string)($row['key'] ?? ''));

            if ($key === '') {
                continue;
            }

            $roles = [];

            foreach (AttributeDefinition::ROLES as $role) {
                if (!empty($row[$role])) {
                    $roles[] = $role;
                }
            }

            $attribute = $existing?->getAttribute($key) ?? new AttributeDefinition();

            $attribute->key = $key;
            $attribute->label = trim((string)($row['label'] ?? '')) ?: null;
            $attribute->source = (string)($row['source'] ?? AttributeDefinition::SOURCE_ATTRIBUTE);
            $attribute->path = trim((string)($row['path'] ?? ''));
            $attribute->roles = $roles;
            $attribute->searchWeight = (float)($row['searchWeight'] ?? 1.0);
            $attribute->facetType = (string)($row['facetType'] ?? AttributeDefinition::FACET_STRING);
            $attribute->facetOperator = (string)($row['facetOperator'] ?? 'or');

            $index->attributes[] = $attribute;
        }

        foreach (array_values((array)($body['sortings'] ?? [])) as $row) {
            $name = trim((string)($row['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $index->sortings[] = new SortingDefinition([
                'name' => $name,
                'label' => trim((string)($row['label'] ?? '')) ?: null,
                'attribute' => trim((string)($row['attribute'] ?? '')),
                'direction' => (string)($row['direction'] ?? 'asc'),
            ]);
        }

        return $index;
    }

    /**
     * @return list<string>
     */
    private static function splitList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('strval', $value), fn(string $v) => $v !== ''));
        }

        return array_values(array_filter(array_map('trim', explode(',', (string)$value)), fn(string $v) => $v !== ''));
    }

    public static function fromConfig(string $uid, array $config): self
    {
        return new self([
            'uid' => $uid,
            'handle' => (string)($config['handle'] ?? $uid),
            'name' => (string)($config['name'] ?? ''),
            'description' => isset($config['description']) ? (string)$config['description'] : null,
            'sources' => array_map(
                fn(array $row) => SourceDefinition::fromConfig($row),
                array_values(array_filter((array)($config['sources'] ?? []), 'is_array')),
            ),
            'siteIds' => array_map('intval', array_values((array)($config['siteIds'] ?? []))),
            'attributes' => array_map(
                fn(array $row) => AttributeDefinition::fromConfig($row),
                array_values(array_filter((array)($config['attributes'] ?? []), 'is_array')),
            ),
            'sortings' => array_map(
                fn(array $row) => SortingDefinition::fromConfig($row),
                array_values(array_filter((array)($config['sortings'] ?? []), 'is_array')),
            ),
            'transport' => (string)($config['transport'] ?? self::TRANSPORT_HTMX),
            'hitsPerPage' => (int)($config['hitsPerPage'] ?? 24),
            'maxValuesPerFacet' => (int)($config['maxValuesPerFacet'] ?? 20),
            'publishDebounce' => (int)($config['publishDebounce'] ?? 30),
            'stopwords' => array_values(array_filter((array)($config['stopwords'] ?? []), 'is_string')),
            'synonyms' => array_values(array_filter((array)($config['synonyms'] ?? []), 'is_string')),
            'shardPayload' => (bool)($config['shardPayload'] ?? false),
            'enabled' => (bool)($config['enabled'] ?? true),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toConfig(): array
    {
        return [
            'handle' => $this->handle,
            'name' => $this->name,
            'description' => $this->description,
            'sources' => array_map(fn(SourceDefinition $s) => $s->toConfig(), array_values($this->sources)),
            'siteIds' => array_values($this->siteIds),
            'attributes' => array_map(fn(AttributeDefinition $a) => $a->toConfig(), array_values($this->attributes)),
            'sortings' => array_map(fn(SortingDefinition $s) => $s->toConfig(), array_values($this->sortings)),
            'transport' => $this->transport,
            'hitsPerPage' => $this->hitsPerPage,
            'maxValuesPerFacet' => $this->maxValuesPerFacet,
            'publishDebounce' => $this->publishDebounce,
            'stopwords' => array_values($this->stopwords),
            'synonyms' => array_values($this->synonyms),
            'shardPayload' => $this->shardPayload,
            'enabled' => $this->enabled,
        ];
    }

    public function ensureUid(): string
    {
        if ($this->uid === '') {
            $this->uid = StringHelper::UUID();
        }

        return $this->uid;
    }

    protected function defineRules(): array
    {
        return [
            [['handle', 'name'], 'required'],
            [['handle'], 'match', 'pattern' => '/^[a-zA-Z][a-zA-Z0-9\-_]*$/', 'message' => 'Handles must start with a letter and contain only letters, numbers, hyphens and underscores.'],
            [['transport'], 'in', 'range' => self::TRANSPORTS],
            [['hitsPerPage'], 'integer', 'min' => 1, 'max' => 1000],
            [['maxValuesPerFacet'], 'integer', 'min' => 1, 'max' => 1000],
            [['publishDebounce'], 'integer', 'min' => 0, 'max' => 3600],
            // `skipOnEmpty => false` is load-bearing on all three. Yii skips an inline validator
            // when the attribute is empty, and an empty array counts as empty — so the rules that
            // exist precisely to reject "no sources" and "no attributes" were the ones that never
            // ran. An index with neither saved happily and then published nothing.
            [['sources'], 'validateSources', 'skipOnEmpty' => false],
            [['attributes'], 'validateAttributes', 'skipOnEmpty' => false],
            [['sortings'], 'validateSortings', 'skipOnEmpty' => false],
        ];
    }

    public function validateSources(string $attribute): void
    {
        if ($this->sources === []) {
            $this->addError($attribute, 'An index needs at least one source to pull records from.');
        }

        foreach ($this->sources as $source) {
            if (!$source->validate()) {
                $this->addError($attribute, implode(' ', $source->getFirstErrors()));
            }
        }
    }

    public function validateAttributes(string $attribute): void
    {
        if ($this->attributes === []) {
            $this->addError($attribute, 'An index needs at least one attribute.');
            return;
        }

        $seen = [];

        foreach ($this->attributes as $definition) {
            if (!$definition->validate()) {
                $this->addError($attribute, implode(' ', $definition->getFirstErrors()));
                continue;
            }

            if (isset($seen[$definition->key])) {
                $this->addError($attribute, "Two attributes both use the key “{$definition->key}”.");
            }

            $seen[$definition->key] = true;
        }
    }

    public function validateSortings(string $attribute): void
    {
        foreach ($this->sortings as $sorting) {
            if (!$sorting->validate()) {
                $this->addError($attribute, implode(' ', $sorting->getFirstErrors()));
                continue;
            }

            if ($sorting->isRelevance()) {
                continue;
            }

            // A sorting over a key with no precomputed order would have to sort at query time,
            // which is the cost the whole plugin exists to avoid.
            $target = $this->getAttribute($sorting->attribute);

            if ($target === null) {
                $this->addError($attribute, "Sorting “{$sorting->name}” orders by “{$sorting->attribute}”, which is not an attribute on this index.");
            } elseif (!$target->isSortable()) {
                $this->addError($attribute, "Sorting “{$sorting->name}” orders by “{$sorting->attribute}”, which needs the sortable role.");
            }
        }
    }
}
