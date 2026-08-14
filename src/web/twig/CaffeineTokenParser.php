<?php

namespace justinholtweb\caffeine\web\twig;

use Twig\Node\Node;
use Twig\Token;
use Twig\TokenParser\AbstractTokenParser;

/**
 * `{% caffeine 'products' %} … {% endcaffeine %}`
 *
 * With options and a chosen variable name:
 *
 *     {% caffeine 'products' with { prefix: 'p_', tag: 'section' } as products %}
 *
 * The tag runs the engine once, exposes the result as `search` (or whatever `as` names), and
 * wraps its body in an element carrying the configuration the browser runtime reads back out.
 */
class CaffeineTokenParser extends AbstractTokenParser
{
    public function getTag(): string
    {
        return 'caffeine';
    }

    public function parse(Token $token): Node
    {
        $lineno = $token->getLine();
        $stream = $this->parser->getStream();

        $handle = $this->parser->parseExpression();
        $options = null;

        if ($stream->nextIf(Token::NAME_TYPE, 'with')) {
            $options = $this->parser->parseExpression();
        }

        $alias = 'search';

        if ($stream->nextIf(Token::NAME_TYPE, 'as')) {
            $alias = $stream->expect(Token::NAME_TYPE)->getValue();
        }

        $stream->expect(Token::BLOCK_END_TYPE);
        $body = $this->parser->subparse([$this, 'decideEnd'], true);
        $stream->expect(Token::BLOCK_END_TYPE);

        $nodes = ['handle' => $handle, 'body' => $body];

        if ($options !== null) {
            $nodes['options'] = $options;
        }

        return new CaffeineNode($nodes, ['alias' => $alias], $lineno);
    }

    public function decideEnd(Token $token): bool
    {
        return $token->test('endcaffeine');
    }
}
