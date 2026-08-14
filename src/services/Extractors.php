<?php

namespace justinholtweb\caffeine\services;

use craft\base\Component;
use DateTimeInterface;
use justinholtweb\caffeine\events\RegisterExtractorsEvent;
use justinholtweb\caffeine\extractors\AddressExtractor;
use justinholtweb\caffeine\extractors\CollectionExtractor;
use justinholtweb\caffeine\extractors\ColorExtractor;
use justinholtweb\caffeine\extractors\ExtractedValue;
use justinholtweb\caffeine\extractors\LinkExtractor;
use justinholtweb\caffeine\extractors\MoneyExtractor;
use justinholtweb\caffeine\extractors\OptionExtractor;
use justinholtweb\caffeine\extractors\ValueExtractorInterface;
use Throwable;

/**
 * The value-extractor registry.
 *
 * Order is significant and deliberate. Collections unwrap first so everything after it sees a
 * single item. The specific shapes come next. `LinkExtractor` is last because it matches on
 * little more than "has a `getUrl()`", which is true of more things than are actually links —
 * anything with a stronger claim should get there first.
 *
 * Extractors registered through the event are checked *before* the built-ins, so a plugin can
 * override how its own field type is read.
 */
class Extractors extends Component
{
    /**
     * @event RegisterExtractorsEvent Fired when Caffeine collects the available extractors.
     */
    public const EVENT_REGISTER_EXTRACTORS = 'registerExtractors';

    /** @var class-string<ValueExtractorInterface>[]|null */
    private ?array $extractors = null;

    /**
     * @return class-string<ValueExtractorInterface>[]
     */
    public function all(): array
    {
        if ($this->extractors !== null) {
            return $this->extractors;
        }

        $event = new RegisterExtractorsEvent(['extractors' => []]);

        $this->trigger(self::EVENT_REGISTER_EXTRACTORS, $event);

        $registered = array_values(array_filter(
            $event->extractors,
            fn($class) => is_string($class) && is_subclass_of($class, ValueExtractorInterface::class),
        ));

        return $this->extractors = array_merge($registered, [
            CollectionExtractor::class,
            AddressExtractor::class,
            MoneyExtractor::class,
            ColorExtractor::class,
            OptionExtractor::class,
            LinkExtractor::class,
        ]);
    }

    /**
     * Turns a value object into something the index can hold, or leaves it alone.
     *
     * Anything that is not an object, or is a date, comes back untouched — dates are handled
     * everywhere else already, and wrapping them here would only make `ValueHelper` unwrap them
     * again.
     */
    public function expand(mixed $value): mixed
    {
        if (!is_object($value) || $value instanceof DateTimeInterface || $value instanceof ExtractedValue) {
            return $value;
        }

        foreach ($this->all() as $class) {
            try {
                if (!$class::supports($value)) {
                    continue;
                }

                $extracted = (new $class())->extract($value);
            } catch (Throwable) {
                // An extractor that throws on an odd value is a bug in that extractor, not a
                // reason to fail the build for the record that happened to contain it.
                continue;
            }

            if ($extracted === null) {
                continue;
            }

            // A collection extracts to a plain list, which needs walking again — its items are
            // the things with shapes worth recognising.
            return $extracted->parts === [] && is_array($extracted->primary)
                ? array_map(fn($item) => $this->expand($item), $extracted->primary)
                : $extracted;
        }

        return $value;
    }

    public function reset(): void
    {
        $this->extractors = null;
    }
}
