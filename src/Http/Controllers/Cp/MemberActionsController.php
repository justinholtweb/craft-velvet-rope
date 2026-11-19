<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope\Http\Controllers\Cp;

use CraftCms\Cms\User\Elements\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use JustinHoltWeb\VelvetRope\Membership\GroupSync;
use JustinHoltWeb\VelvetRope\Models\Grant;
use JustinHoltWeb\VelvetRope\Plans\Plans;
use JustinHoltWeb\VelvetRope\Plugin;

use function CraftCms\Cms\cp_url;
use function CraftCms\Cms\currentUserElement;

/**
 * POST actions from the Membership screen. Routes require “Manage memberships”
 * (and the CP group's auth + CSRF); grants are Pro.
 */
class MemberActionsController
{
    public function resync(int $userId, GroupSync $groupSync): RedirectResponse
    {
        $this->user($userId);
        $groupSync->syncUser($userId);

        return redirect(cp_url("users/{$userId}/membership"));
    }

    public function grant(Request $request, int $userId, Plans $plans, GroupSync $groupSync): RedirectResponse
    {
        abort_unless(Plugin::isPro(), 404);
        $this->user($userId);
        $data = $request->validate([
            'plan' => ['required', 'string', 'regex:/^[a-z][a-z0-9-]{0,63}$/'],
            'months' => ['required', 'integer', 'min:1', 'max:120'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        abort_if($plans->get($data['plan']) === null, 422, 'Unknown plan.');

        Grant::create([
            'userId' => $userId,
            'plan' => $data['plan'],
            'source' => Grant::SOURCE_COMP,
            'startsAt' => now(),
            'endsAt' => now()->addMonths((int) $data['months']),
            'note' => $data['note'] ?? null,
            'createdById' => currentUserElement()?->id,
        ]);
        $groupSync->syncUser($userId);
        Log::info('Velvet Rope: user '.currentUserElement()?->id." granted {$data['plan']} to user {$userId} for {$data['months']} months");

        return redirect(cp_url("users/{$userId}/membership"));
    }

    public function revoke(int $userId, int $grantId, GroupSync $groupSync): RedirectResponse
    {
        abort_unless(Plugin::isPro(), 404);
        $this->user($userId);
        $grant = Grant::where('userId', $userId)->whereKey($grantId)->firstOrFail();
        // Ended now rather than deleted, so a redeemed gift keeps its history.
        $grant->update(['endsAt' => now()]);
        $groupSync->syncUser($userId);

        return redirect(cp_url("users/{$userId}/membership"));
    }

    private function user(int $userId): User
    {
        $user = User::find()->id($userId)->status(null)->one();
        abort_unless($user instanceof User, 404);

        return $user;
    }
}
