<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope\Twig;

use Twig\Extension\AbstractExtension;

class VelvetRopeTwigExtension extends AbstractExtension
{
    public function getTokenParsers(): array
    {
        return [new RequireMembershipTokenParser];
    }
}
