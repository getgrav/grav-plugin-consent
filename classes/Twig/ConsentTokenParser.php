<?php

declare(strict_types=1);

namespace Grav\Plugin\Consent\Twig;

use Twig\Error\SyntaxError;
use Twig\Node\Node;
use Twig\Token;
use Twig\TokenParser\AbstractTokenParser;

/**
 * {% consent 'marketing' %} … {% else %} … {% endconsent %}
 *
 * The single highest-value thing in the plugin. Third-party embeds — YouTube,
 * Maps, a Vimeo player — are where every site owner gets stuck, because the
 * markup is inside a page or a template rather than in an asset registration,
 * and there is nothing to hook.
 *
 * Without an `{% else %}` branch the plugin renders its own placeholder: the
 * service name, one line of explanation, and a button that grants exactly that
 * category and loads the content in place.
 */
final class ConsentTokenParser extends AbstractTokenParser
{
    public function parse(Token $token): Node
    {
        $lineno = $token->getLine();
        $stream = $this->parser->getStream();

        $category = $this->parser->parseExpression();

        // Optional `with {service: 'youtube'}` to name the service the
        // placeholder should describe, when the category alone is too vague.
        $options = null;
        if ($stream->nextIf(Token::NAME_TYPE, 'with')) {
            $options = $this->parser->parseExpression();
        }

        $stream->expect(Token::BLOCK_END_TYPE);
        $body = $this->parser->subparse([$this, 'decideFork']);

        $else = null;
        $tag = $stream->next()->getValue();
        if ($tag === 'else') {
            $stream->expect(Token::BLOCK_END_TYPE);
            $else = $this->parser->subparse([$this, 'decideEnd']);
            $tag = $stream->next()->getValue();
        }

        if ($tag !== 'endconsent') {
            throw new SyntaxError(
                sprintf('Unexpected end of template. Twig was looking for "else" or "endconsent" to close the "consent" block started at line %d.', $lineno),
                $stream->getCurrent()->getLine(),
                $stream->getSourceContext()
            );
        }

        $stream->expect(Token::BLOCK_END_TYPE);

        $nodes = ['category' => $category, 'body' => $body];
        if ($options !== null) {
            $nodes['options'] = $options;
        }
        if ($else !== null) {
            $nodes['else'] = $else;
        }

        return new ConsentNode($nodes, [], $lineno);
    }

    public function decideFork(Token $token): bool
    {
        return $token->test(['else', 'endconsent']);
    }

    public function decideEnd(Token $token): bool
    {
        return $token->test(['endconsent']);
    }

    public function getTag(): string
    {
        return 'consent';
    }
}
