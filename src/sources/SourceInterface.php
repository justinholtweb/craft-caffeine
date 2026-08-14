<?php

namespace justinholtweb\caffeine\sources;

use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use justinholtweb\caffeine\models\SourceDefinition;

/**
 * Supplies the elements an index is built from.
 *
 * The registry is extensible by event, so a Commerce product source or a Formie submission
 * source is configured and built exactly the way the built-in entry source is.
 */
interface SourceInterface
{
    /** The handle a source definition names, e.g. `entry`. */
    public static function handle(): string;

    public static function displayName(): string;

    /** @return class-string<ElementInterface> */
    public static function elementType(): string;

    /**
     * Whether this source can be used here. A Commerce source returns false when Commerce is
     * not installed, so it never appears as an option rather than appearing and then failing.
     */
    public static function isAvailable(): bool;

    /**
     * The containers a definition can be scoped to — section handles for entries, group
     * handles for categories — as `handle => label`, for the CP screen.
     *
     * @return array<string, string>
     */
    public function containerOptions(): array;

    /**
     * Sub-types within those containers, e.g. entry types.
     *
     * @return array<string, string>
     */
    public function subTypeOptions(): array;

    /**
     * The query yielding every element this definition covers, for one site.
     */
    public function query(SourceDefinition $definition, int $siteId): ElementQueryInterface;

    /**
     * Whether a given element falls inside this definition.
     *
     * Used on save, to decide whether the element belongs to the index at all — and, just as
     * importantly, whether it has *stopped* belonging, since an entry moved out of an indexed
     * section has to be removed rather than ignored.
     */
    public function covers(SourceDefinition $definition, ElementInterface $element): bool;
}
