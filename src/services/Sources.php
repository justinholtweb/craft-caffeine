<?php

namespace justinholtweb\caffeine\services;

use craft\base\Component;
use craft\base\ElementInterface;
use justinholtweb\caffeine\events\RegisterSourcesEvent;
use justinholtweb\caffeine\models\IndexDefinition;
use justinholtweb\caffeine\models\SourceDefinition;
use justinholtweb\caffeine\Plugin;
use justinholtweb\caffeine\sources\AssetSource;
use justinholtweb\caffeine\sources\CategorySource;
use justinholtweb\caffeine\sources\EntrySource;
use justinholtweb\caffeine\sources\ProductSource;
use justinholtweb\caffeine\sources\TagSource;
use justinholtweb\caffeine\sources\UserSource;
use justinholtweb\caffeine\sources\SourceInterface;

/**
 * The source registry.
 */
class Sources extends Component
{
    /**
     * @event RegisterSourcesEvent Fired when Caffeine collects the available source types.
     */
    public const EVENT_REGISTER_SOURCES = 'registerSources';

    /** @var array<string, SourceInterface>|null */
    private ?array $sources = null;

    /**
     * Available sources, keyed by handle.
     *
     * Sources beyond entries are a Pro capability, and unavailable ones are dropped rather than
     * listed-and-broken, so the CP never offers a Commerce source on a site without Commerce.
     *
     * @return array<string, SourceInterface>
     */
    public function all(): array
    {
        if ($this->sources !== null) {
            return $this->sources;
        }

        $event = new RegisterSourcesEvent([
            'sources' => [
                EntrySource::class,
                CategorySource::class,
                TagSource::class,
                AssetSource::class,
                UserSource::class,
                ProductSource::class,
            ],
        ]);

        $this->trigger(self::EVENT_REGISTER_SOURCES, $event);

        $sources = [];
        $isPro = Plugin::getInstance()->isPro();

        foreach ($event->sources as $class) {
            if (!is_subclass_of($class, SourceInterface::class) || !$class::isAvailable()) {
                continue;
            }

            if (!$isPro && $class::handle() !== EntrySource::handle()) {
                continue;
            }

            $sources[$class::handle()] = new $class();
        }

        return $this->sources = $sources;
    }

    public function getByHandle(string $handle): ?SourceInterface
    {
        return $this->all()[$handle] ?? null;
    }

    /**
     * Every source definition on an index whose source type is actually available.
     *
     * @return array<int, array{source: SourceInterface, definition: SourceDefinition}>
     */
    public function resolve(IndexDefinition $index): array
    {
        $resolved = [];

        foreach ($index->sources as $definition) {
            $source = $this->getByHandle($definition->type);

            if ($source === null) {
                continue;
            }

            $resolved[] = ['source' => $source, 'definition' => $definition];
        }

        return $resolved;
    }

    /**
     * Whether an element belongs in an index right now.
     */
    public function covers(IndexDefinition $index, ElementInterface $element): bool
    {
        foreach ($this->resolve($index) as ['source' => $source, 'definition' => $definition]) {
            if ($source->covers($definition, $element)) {
                return true;
            }
        }

        return false;
    }

    public function reset(): void
    {
        $this->sources = null;
    }
}
