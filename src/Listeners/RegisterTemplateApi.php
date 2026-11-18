<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope\Listeners;

use CraftCms\Cms\Twig\Events\TwigCreated;
use CraftCms\Cms\View\Events\TemplateGlobalsResolving;
use CraftCms\Cms\View\TemplateMode;
use JustinHoltWeb\VelvetRope\Twig\VelvetRopeTwigExtension;
use JustinHoltWeb\VelvetRope\VelvetRope;

/**
 * `velvetRope` as a template global — Craft feeds these to Twig *and* Blade —
 * plus the `{% requireMembership %}` tag for Twig.
 */
class RegisterTemplateApi
{
    public function globals(TemplateGlobalsResolving $event): void
    {
        $event->globals['velvetRope'] = app(VelvetRope::class);
    }

    public function twig(TwigCreated $event): void
    {
        if ($event->templateMode === TemplateMode::Site) {
            $event->twig->addExtension(new VelvetRopeTwigExtension);
        }
    }

    /** @return array<class-string, string> */
    public function subscribe(): array
    {
        return [
            TemplateGlobalsResolving::class => 'globals',
            TwigCreated::class => 'twig',
        ];
    }
}
