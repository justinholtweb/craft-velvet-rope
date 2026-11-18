<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope\Twig;

use Twig\Node\Node;
use Twig\Token;
use Twig\TokenParser\AbstractTokenParser;

/**
 * {% requireMembership %}
 * {% requireMembership 'gold' %}
 * {% requireMembership ['gold', 'silver'] redirect 'join' %}
 */
class RequireMembershipTokenParser extends AbstractTokenParser
{
    public function parse(Token $token): Node
    {
        $stream = $this->parser->getStream();
        $plans = $redirect = null;

        if (! $stream->test(Token::BLOCK_END_TYPE) && ! $stream->test(Token::NAME_TYPE, 'redirect')) {
            $plans = $this->parser->parseExpression();
        }

        if ($stream->nextIf(Token::NAME_TYPE, 'redirect')) {
            $redirect = $this->parser->parseExpression();
        }

        $stream->expect(Token::BLOCK_END_TYPE);

        return new RequireMembershipNode($plans, $redirect, $token->getLine());
    }

    public function getTag(): string
    {
        return 'requireMembership';
    }
}
