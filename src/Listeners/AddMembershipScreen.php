<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope\Listeners;

use CraftCms\Cms\User\Events\EditUserScreensResolving;
use JustinHoltWeb\VelvetRope\Http\Controllers\Cp\MemberScreenController;
use JustinHoltWeb\VelvetRope\Plugin;

use function CraftCms\Cms\t;

/** A “Membership” screen on user edit pages, for people who can view members. */
class AddMembershipScreen
{
    public function handle(EditUserScreensResolving $event): void
    {
        if ($event->currentUser?->can(Plugin::PERMISSION_VIEW)) {
            $event->screens[MemberScreenController::SCREEN] = ['label' => t('Membership', category: 'velvetrope')];
        }
    }
}
