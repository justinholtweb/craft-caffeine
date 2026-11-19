<?php

namespace JustinHoltWeb\Caffeine\Models;

use Illuminate\Validation\Rule;

/**
 * A named ordering over an index, precomputed into the artifact.
 *
 * These are Algolia's "replicas" in everything but storage cost: because the order is worked
 * out once at build time and stored as a list of record ids, switching sort at query time is a
 * pointer swap rather than a sort.
 */
class SortingDefinition extends Model
{
    /** The reserved sorting every index has: descending full-text score, then the tie-break. */
    public const RELEVANCE = 'relevance';

    /** The name used in `sortBy` and in the URL. */
    public string $name = '';

    public ?string $label = null;

    /** The attribute key to order by. Ignored for `relevance`. */
    public string $attribute = '';

    public string $direction = 'asc';

    /** Added by an `Indexes::EVENT_DEFINE_INDEX` handler. Never written to project config. */
    public bool $dynamic = false;

    public function label(): string
    {
        return $this->label ?: $this->name;
    }

    public function isRelevance(): bool
    {
        return $this->name === self::RELEVANCE;
    }

    public function isDescending(): bool
    {
        return strtolower($this->direction) === 'desc';
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        return new self([
            'name' => (string) ($config['name'] ?? ''),
            'label' => isset($config['label']) ? (string) $config['label'] : null,
            'attribute' => (string) ($config['attribute'] ?? ''),
            'direction' => (string) ($config['direction'] ?? 'asc'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toConfig(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->label,
            'attribute' => $this->attribute,
            'direction' => $this->direction,
        ];
    }

    public function getRules(): array
    {
        return [
            'name' => ['required', 'string', 'regex:/^[a-zA-Z][a-zA-Z0-9_]*$/'],
            'direction' => [Rule::in(['asc', 'desc'])],
        ];
    }

    protected function checks(): void
    {
        if (! $this->isRelevance() && $this->attribute === '') {
            $this->addError('attribute', 'Choose the attribute this sorting orders by.');
        }
    }
}
