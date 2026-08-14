<?php

namespace justinholtweb\caffeine\web\twig;

use Twig\Compiler;
use Twig\Node\Node;

/**
 * Compiles `{% caffeineresults %}`.
 *
 * The body is buffered rather than echoed straight out, so the rendered HTML can be handed to
 * the search context. That is what lets the fragment endpoint render the whole page template —
 * exactly as a normal request would, with every variable the route provides — and then return
 * only this region, without needing sentinel comments in the markup or a second template.
 */
class ResultsNode extends Node
{
    public function compile(Compiler $compiler): void
    {
        $compiler
            ->addDebugInfo($this)
            ->write("ob_start();\n")
            ->subcompile($this->getNode('body'))
            ->write("\$caffeineResults = ob_get_clean();\n")
            ->write("if ((\$context['_caffeine'] ?? null) instanceof \\justinholtweb\\caffeine\\models\\SearchContext) {\n")
            ->indent()
            ->write("\$context['_caffeine']->captureResults(\$caffeineResults);\n")
            ->write("echo \\justinholtweb\\caffeine\\web\\twig\\ResultsNode::wrap(\$context['_caffeine'], \$caffeineResults);\n")
            ->outdent()
            ->write("} else {\n")
            ->indent()
            // Outside a {% caffeine %} block this is meaningless, but throwing would be worse
            // than rendering what the author wrote.
            ->write("echo \$caffeineResults;\n")
            ->outdent()
            ->write("}\n");
    }

    public static function wrap(\justinholtweb\caffeine\models\SearchContext $search, string $html): string
    {
        return sprintf(
            '<div id="%s" data-caffeine-results data-caffeine-region="%s">%s</div>',
            htmlspecialchars($search->getResultsId(), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($search->regionId('results'), ENT_QUOTES, 'UTF-8'),
            $html,
        );
    }
}
