<?php

declare(strict_types=1);

namespace JustinHoltWeb\Caffeine\Console\Commands\Concerns;

use JustinHoltWeb\Caffeine\Models\IndexDefinition;
use JustinHoltWeb\Caffeine\Services\Indexes;

/**
 * What the Caffeine commands share: turning an optional handle argument into indexes, and
 * printing sizes.
 */
trait ResolvesIndexes
{
    /**
     * One index by handle, or every index when no handle is given.
     *
     * @return list<IndexDefinition>
     */
    protected function resolveIndexes(?string $handle): array
    {
        $indexes = app(Indexes::class);

        if ($handle === null || $handle === '') {
            return array_values($indexes->all());
        }

        $index = $indexes->getByHandle($handle);

        return $index !== null ? [$index] : [];
    }

    protected function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return "{$bytes} B";
        }

        if ($bytes < 1024 * 1024) {
            return sprintf('%.1f KB', $bytes / 1024);
        }

        return sprintf('%.2f MB', $bytes / 1024 / 1024);
    }

    /** A line in the muted colour Craft 5's `Console::FG_GREY` gave. */
    protected function muted(string $text): void
    {
        $this->line('<fg=gray>' . $this->escape($text) . '</>');
    }

    protected function escape(string $text): string
    {
        return \Symfony\Component\Console\Formatter\OutputFormatter::escape($text);
    }
}
