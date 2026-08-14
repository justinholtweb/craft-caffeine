<?php

namespace justinholtweb\caffeine\sources;

use craft\base\Component;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use justinholtweb\caffeine\models\SourceDefinition;

/**
 * Shared behaviour for sources: status handling and the element-type guard.
 */
abstract class BaseSource extends Component implements SourceInterface
{
    public static function isAvailable(): bool
    {
        return true;
    }

    public function containerOptions(): array
    {
        return [];
    }

    public function subTypeOptions(): array
    {
        return [];
    }

    public function covers(SourceDefinition $definition, ElementInterface $element): bool
    {
        return $element instanceof (static::elementType());
    }

    /**
     * Applies the definition's status to a query.
     *
     * `any` still excludes drafts and revisions. An index is a public artifact served as a
     * static file — it cannot check who is asking, so a draft that reached it would be visible
     * to everyone.
     */
    protected function applyStatus(ElementQueryInterface $query, SourceDefinition $definition): ElementQueryInterface
    {
        $query
            ->drafts(false)
            ->revisions(false)
            ->provisionalDrafts(false);

        return $query->status($this->statusFor($definition->status));
    }

    /**
     * Whether one element satisfies the definition's status, without a query.
     *
     * The counterpart to `applyStatus()`, and the reason both exist: the build re-loads changed
     * elements *without* the status filter, precisely so it can notice one that has just stopped
     * qualifying and remove it. That check happens here, per element.
     */
    protected function coversStatus(SourceDefinition $definition, ElementInterface $element): bool
    {
        if ($element->getIsDraft() || $element->getIsRevision()) {
            return false;
        }

        if ($definition->status === 'any') {
            return true;
        }

        return $element->getStatus() === $this->statusFor($definition->status);
    }

    /**
     * Translates the definition's status into one this element type understands.
     *
     * `live` is an entry concept. Categories, assets and users have no such status, and asking
     * for it returns nothing at all rather than erroring — an empty index with no explanation.
     * Each source maps the three choices onto whatever its element type actually has.
     */
    protected function statusFor(string $status): ?string
    {
        return match ($status) {
            'any' => null,
            'enabled' => 'enabled',
            default => 'live',
        };
    }
}
