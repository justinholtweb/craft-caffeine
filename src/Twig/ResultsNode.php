<?php

namespace JustinHoltWeb\Caffeine\Twig;

use JustinHoltWeb\Caffeine\Models\SearchContext;
use Twig\Attribute\YieldReady;
use Twig\Compiler;
use Twig\Node\CaptureNode;
use Twig\Node\Node;

/**
 * Compiles `{% caffeineresults %}`.
 *
 * The body is buffered rather than echoed straight out, so the rendered HTML can be handed to
 * the search context. That is what lets the fragment endpoint render the whole page template —
 * exactly as a normal request would, with every variable the route provides — and then return
 * only this region, without needing sentinel comments in the markup or a second template.
 */
#[YieldReady]
class ResultsNode extends Node
{
    public function compile(Compiler $compiler): void
    {
        // Twig 3 templates yield their output instead of echoing it, so output buffering would
        // capture nothing. CaptureNode collects the body's yields into a string the same way
        // `{% set x %}…{% endset %}` does.
        $capture = new CaptureNode($this->getNode('body'), $this->getTemplateLine());
        $capture->setAttribute('raw', true);

        $compiler
            ->addDebugInfo($this)
            ->write('$caffeineResults = ')
            ->subcompile($capture)
            ->raw("\n")
            ->write("if ((\$context['_caffeine'] ?? null) instanceof \\".SearchContext::class." ) {\n")
            ->indent()
            ->write("\$context['_caffeine']->captureResults(\$caffeineResults);\n")
            ->write('yield \\'.self::class."::wrap(\$context['_caffeine'], \$caffeineResults);\n")
            ->outdent()
            ->write("} else {\n")
            ->indent()
            // Outside a {% caffeine %} block this is meaningless, but throwing would be worse
            // than rendering what the author wrote.
            ->write("yield \$caffeineResults;\n")
            ->outdent()
            ->write("}\n");
    }

    public static function wrap(SearchContext $search, string $html): string
    {
        return sprintf(
            '<div id="%s" data-caffeine-results data-caffeine-region="%s">%s</div>',
            htmlspecialchars($search->getResultsId(), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($search->regionId('results'), ENT_QUOTES, 'UTF-8'),
            $html,
        );
    }
}
