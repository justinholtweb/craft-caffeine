<?php

namespace justinholtweb\caffeine\web\twig;

use Twig\Compiler;
use Twig\Node\Node;

/**
 * Compiles `{% caffeine %}`.
 *
 * Three things happen, in this order and for a reason:
 *
 * 1. The search runs *before* any of the body is rendered, so `search` is fully populated for
 *    widgets that appear above the results as well as below them.
 * 2. The body renders inside a wrapper element carrying the runtime configuration as a `data-`
 *    attribute. The runtime finds its own instances by that attribute rather than by a selector
 *    the developer has to remember to keep in sync.
 * 3. The previous value of the alias is saved and restored, so a `{% caffeine %}` block nested
 *    inside a loop that already had a `search` variable does not clobber it.
 */
class CaffeineNode extends Node
{
    public function compile(Compiler $compiler): void
    {
        $alias = $this->getAttribute('alias');

        $compiler
            ->addDebugInfo($this)
            ->write("\$caffeineOptions = ");

        if ($this->hasNode('options')) {
            $compiler->subcompile($this->getNode('options'));
        } else {
            $compiler->raw('[]');
        }

        $compiler
            ->raw(";\n")
            ->write("\$caffeineOptions = is_array(\$caffeineOptions) ? \$caffeineOptions : [];\n")
            // `$this` here is the compiled Twig template, so this records the template the tag
            // actually appears in — including when that is a partial. The fragment endpoint
            // re-renders exactly this, which is how hit markup stays in Twig and stays honest.
            ->write("\$caffeineOptions['template'] = \$this->getTemplateName();\n")
            ->write("\$caffeineOptions['element'] = \justinholtweb\\caffeine\\web\\twig\\CaffeineNode::element(\$context);\n")
            ->write("\$caffeineSearch = \\justinholtweb\\caffeine\\Plugin::getInstance()->search->context(")
            ->subcompile($this->getNode('handle'))
            ->raw(", \$caffeineOptions);\n")
            // Both names are set: the alias for the template, and a fixed key so
            // {% caffeineresults %} can find the context whatever the developer called it.
            ->write("\$caffeinePrevious = \$context['{$alias}'] ?? null;\n")
            ->write("\$caffeinePreviousInternal = \$context['_caffeine'] ?? null;\n")
            ->write("\$context['{$alias}'] = \$caffeineSearch;\n")
            ->write("\$context['_caffeine'] = \$caffeineSearch;\n")
            ->write("echo \\justinholtweb\\caffeine\\web\\twig\\CaffeineNode::open(\$caffeineSearch, \$caffeineOptions);\n")
            ->subcompile($this->getNode('body'))
            ->write("echo \\justinholtweb\\caffeine\\web\\twig\\CaffeineNode::close(\$caffeineOptions);\n")
            ->write("\$context['{$alias}'] = \$caffeinePrevious;\n")
            ->write("\$context['_caffeine'] = \$caffeinePreviousInternal;\n");
    }

    /**
     * The element the page is being rendered for, if there is one.
     *
     * A results block frequently reads from `entry` — a category listing filtered to the
     * section that entry describes — and the fragment endpoint has to put it back or the
     * re-render fails on a null. Only the id travels, signed; the element is loaded fresh.
     *
     * @param array<string, mixed> $context
     * @return array{type: string, id: int, siteId: int}|null
     */
    public static function element(array $context): ?array
    {
        foreach (['entry', 'category', 'product', 'element'] as $name) {
            $value = $context[$name] ?? null;

            if ($value instanceof \craft\base\ElementInterface && $value->id !== null) {
                return ['type' => $name, 'id' => (int)$value->id, 'siteId' => (int)$value->siteId];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function open(\justinholtweb\caffeine\models\SearchContext $search, array $options): string
    {
        $tag = self::tagName($options);

        if ($tag === null) {
            return '';
        }

        $htmx = ($options['htmx'] ?? false) === true;

        // Registered here rather than in the plugin's init, so a site that never uses the tag
        // never ships the runtime — and never when HTMX is driving, because two libraries
        // intercepting the same click is a race, not a feature.
        if (!$htmx && ($options['runtime'] ?? true) !== false) {
            \Craft::$app->getView()->registerAssetBundle(
                \justinholtweb\caffeine\web\assets\runtime\CaffeineAsset::class,
            );
        }

        $attributes = [
            'data-caffeine' => json_encode($search->config(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];

        if ($htmx) {
            // `hx-boost` on the wrapper rather than `hx-get` on every control: the links already
            // carry the right href, so boosting them needs no per-link attributes and no second
            // set of URLs to keep in step with the first.
            //
            // It fetches the page and selects the results block out of it, which is more bytes
            // than the fragment endpoint and updates only that block — facet counts elsewhere on
            // the page keep their previous numbers. Put the widgets inside
            // `{% caffeineresults %}` if that matters, or use the bundled runtime, which swaps
            // every region and is why HTMX is supported rather than required.
            $target = '#' . $search->getResultsId();

            $attributes += [
                'hx-boost' => 'true',
                'hx-select' => $target,
                'hx-target' => $target,
                'hx-swap' => 'outerHTML',
                'hx-push-url' => 'true',
            ];
        }

        if (!empty($options['class'])) {
            $attributes['class'] = (string)$options['class'];
        }

        if (!empty($options['id'])) {
            $attributes['id'] = (string)$options['id'];
        }

        $rendered = '';

        foreach ($attributes as $name => $value) {
            $rendered .= sprintf(' %s="%s"', $name, htmlspecialchars($value, ENT_QUOTES, 'UTF-8'));
        }

        return "<{$tag}{$rendered}>";
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function close(array $options): string
    {
        $tag = self::tagName($options);

        return $tag === null ? '' : "</{$tag}>";
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function tagName(array $options): ?string
    {
        $tag = $options['tag'] ?? 'div';

        if ($tag === false || $tag === null || $tag === '') {
            // Opted out of the wrapper entirely. The runtime then has nothing to find, so this
            // is only sensible for a listing that is server-rendered and never enhanced.
            return null;
        }

        return preg_match('/^[a-zA-Z][a-zA-Z0-9-]*$/', (string)$tag) ? (string)$tag : 'div';
    }
}
