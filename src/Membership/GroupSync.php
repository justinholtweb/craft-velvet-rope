<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope\Membership;

use CraftCms\Cms\User\UserGroups;
use CraftCms\Cms\User\Users;
use Illuminate\Support\Facades\Log;
use JustinHoltWeb\VelvetRope\Models\Member;
use JustinHoltWeb\VelvetRope\Models\Subscription;
use JustinHoltWeb\VelvetRope\Plans\Plans;
use JustinHoltWeb\VelvetRope\Settings;

/**
 * Puts a member's Craft user in exactly the groups their subscriptions pay for,
 * leaving groups Velvet Rope doesn't manage alone.
 */
class GroupSync
{
    public function __construct(
        private readonly Plans $plans,
        private readonly Settings $settings,
        private readonly UserGroups $userGroups,
        private readonly Users $users,
    ) {}

    /**
     * @return array{before: list<int>, after: list<int>}
     */
    public function sync(Member $member): array
    {
        $managed = $granted = [];

        foreach ($this->plans->all() as $plan) {
            if ($plan->userGroupUid !== null && ($group = $this->userGroups->getGroupByUid($plan->userGroupUid)) !== null) {
                $managed[$plan->handle] = (int) $group->id;
            }
        }

        /** @var Subscription $subscription */
        foreach ($member->subscriptions()->get() as $subscription) {
            $plan = $this->plans->forSubscription($subscription->type, $subscription->stripe_price);

            if ($plan === null || ! isset($managed[$plan->handle])) {
                continue;
            }

            if (Access::grants($subscription->stripe_status, $subscription->ends_at, $subscription->trial_ends_at, $subscription->past_due_since, $this->settings->graceDays)) {
                $granted[] = $managed[$plan->handle];
            }
        }

        $before = $this->userGroups->getGroupsByUserId($member->userId)->pluck('id')->map(intval(...))->sort()->values()->all();
        $after = Access::groupIds($before, array_values($managed), $granted);

        if ($after !== $before) {
            $this->users->assignUserToGroups($member->userId, $after);
            Log::info("Velvet Rope moved user {$member->userId} from groups [".implode(',', $before).'] to ['.implode(',', $after).']');
        }

        return ['before' => $before, 'after' => $after];
    }
}
