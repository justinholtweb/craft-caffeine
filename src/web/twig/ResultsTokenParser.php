<?php

namespace justinholtweb\caffeine\web\twig;

use Twig\Node\Node;
use Twig\Token;
use Twig\TokenParser\AbstractTokenParser;

/**
 * `{% caffeineresults %} … {% endcaffeineresults %}`
 *
 * Marks the region a refinement replaces. The fragment endpoint re-renders the same template
 * and returns exactly this block, which is why hit markup stays in Twig and behaves identically
 * whether the swap is done by the bundled runtime, by HTMX, or by a plain page load.
 */
class ResultsTokenParser extends AbstractTokenParser
{
    public function getTag(): string
    {
        return 'caffeineresults';
    }

    public function parse(Token $token): Node
    {
        $lineno = $token->getLine();
        $stream = $this->parser->getStream();

        $stream->expect(Token::BLOCK_END_TYPE);
        $body = $this->parser->subparse([$this, 'decideEnd'], true);
        $stream->expect(Token::BLOCK_END_TYPE);

        return new ResultsNode(['body' => $body], [], $lineno);
    }

    public function decideEnd(Token $token): bool
    {
        return $token->test('endcaffeineresults');
    }
}
