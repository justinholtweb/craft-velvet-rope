<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope\Membership;

use CraftCms\Cms\User\UserGroups;
use CraftCms\Cms\User\Users;
use Illuminate\Support\Facades\Log;
use JustinHoltWeb\VelvetRope\Models\Member;
use JustinHoltWeb\VelvetRope\Plans\Plans;

/**
 * Puts a member's Craft user in exactly the groups their subscriptions and grants pay for,
 * leaving groups Velvet Rope doesn't manage alone.
 */
class GroupSync
{
    public function __construct(
        private readonly Plans $plans,
        private readonly Memberships $memberships,
        private readonly UserGroups $userGroups,
        private readonly Users $users,
    ) {}

    /**
     * @return array{before: list<int>, after: list<int>}
     */
    public function syncUser(int $userId): array
    {
        return $this->sync(Member::forUser($userId));
    }

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

        foreach ($this->memberships->grantedPlans($member->userId) as $handle) {
            if (isset($managed[$handle])) {
                $granted[] = $managed[$handle];
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
