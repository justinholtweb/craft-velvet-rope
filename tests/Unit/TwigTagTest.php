<?php

declare(strict_types=1);

use JustinHoltWeb\VelvetRope\Twig\VelvetRopeTwigExtension;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

function compileVr(string $template): string
{
    $twig = new Environment(new ArrayLoader(['t' => $template]));
    $twig->addExtension(new VelvetRopeTwigExtension);

    return $twig->compileSource($twig->getLoader()->getSourceContext('t'));
}

it('compiles every form of the tag to the shared PHP API', function (string $tag, string $call) {
    expect(compileVr($tag))->toContain($call);
})->with([
    ['{% requireMembership %}', 'requireMembership([], null)'],
    ["{% requireMembership 'gold' %}", 'requireMembership("gold", null)'],
    ["{% requireMembership ['gold', 'silver'] %}", 'requireMembership(["gold", "silver"], null)'],
    ["{% requireMembership 'gold' redirect 'join' %}", 'requireMembership("gold", "join")'],
    ["{% requireMembership redirect '/plans' %}", 'requireMembership([], "/plans")'],
]);

it('calls the same VelvetRope service the Blade directive uses', function () {
    expect(compileVr("{% requireMembership 'gold' %}"))->toContain('JustinHoltWeb\VelvetRope\VelvetRope::class');
});
