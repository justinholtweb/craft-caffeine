<?php

namespace justinholtweb\caffeine\models;

use craft\base\Model;

/**
 * Where an index's records come from.
 *
 * The element type is a string rather than an enum so third-party sources registered through
 * `RegisterSourcesEvent` are first-class — a Commerce product source is configured exactly the
 * way the built-in entry source is.
 */
class SourceDefinition extends Model
{
    /**
     * The element type handle the source registry resolves: `entry`, `category`, `tag`,
     * `asset`, `user`, or anything a third party has registered.
     */
    public string $type = 'entry';

    /**
     * Which containers to pull from — section handles for entries, group handles for
     * categories and tags, volume handles for assets. Empty means all of them.
     *
     * @var string[]
     */
    public array $containers = [];

    /**
     * Entry type handles, for sources that have them. Empty means all.
     *
     * @var string[]
     */
    public array $subTypes = [];

    /**
     * Element status to index. Almost always `live`: an index that carries disabled entries
     * will happily show them to the public, because the artifact is served as a static file
     * and has no idea who is asking.
     */
    public string $status = 'live';

    /**
     * @param array<string, mixed> $config
     */
    public static function fromConfig(array $config): self
    {
        return new self([
            'type' => (string)($config['type'] ?? 'entry'),
            'containers' => array_values(array_filter((array)($config['containers'] ?? []), 'is_string')),
            'subTypes' => array_values(array_filter((array)($config['subTypes'] ?? []), 'is_string')),
            'status' => (string)($config['status'] ?? 'live'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toConfig(): array
    {
        return [
            'type' => $this->type,
            'containers' => array_values($this->containers),
            'subTypes' => array_values($this->subTypes),
            'status' => $this->status,
        ];
    }

    protected function defineRules(): array
    {
        return [
            [['type'], 'required'],
            [['status'], 'in', 'range' => ['live', 'enabled', 'any']],
        ];
    }
}
