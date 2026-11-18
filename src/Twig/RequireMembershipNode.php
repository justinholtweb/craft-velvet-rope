<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope\Twig;

use Illuminate\Support\Facades\App;
use JustinHoltWeb\VelvetRope\VelvetRope;
use Twig\Attribute\YieldReady;
use Twig\Compiler;
use Twig\Node\Expression\AbstractExpression;
use Twig\Node\Node;

#[YieldReady]
class RequireMembershipNode extends Node
{
    public function __construct(?AbstractExpression $plans, ?AbstractExpression $redirect, int $lineno)
    {
        $nodes = array_filter(['plans' => $plans, 'redirect' => $redirect]);
        parent::__construct($nodes, [], $lineno);
    }

    public function compile(Compiler $compiler): void
    {
        $compiler->addDebugInfo($this)->write('\\'.App::class.'::make(\\'.VelvetRope::class.'::class)->requireMembership(');
        $this->hasNode('plans') ? $compiler->subcompile($this->getNode('plans')) : $compiler->raw('[]');
        $compiler->raw(', ');
        $this->hasNode('redirect') ? $compiler->subcompile($this->getNode('redirect')) : $compiler->raw('null');
        $compiler->raw(");\n");
    }
}
