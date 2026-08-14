<?php

namespace justinholtweb\caffeine\web\twig;

use justinholtweb\caffeine\models\SearchContext;
use justinholtweb\caffeine\Plugin;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Caffeine's Twig surface.
 *
 * Two tags and one function. The tags are the ergonomic path; the function is there because
 * sometimes a search belongs in a `{% set %}` at the top of a template rather than wrapped
 * around half of it, and nothing in the design requires the tag.
 */
class Extension extends AbstractExtension
{
    public function getName(): string
    {
        return 'caffeine';
    }

    public function getTokenParsers(): array
    {
        return [
            new CaffeineTokenParser(),
            new ResultsTokenParser(),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('caffeine', [$this, 'search']),
        ];
    }

    /**
     * @param array<string, mixed> $options
     */
    public function search(string $handle, array $options = []): SearchContext
    {
        return Plugin::getInstance()->search->context($handle, $options);
    }
}
