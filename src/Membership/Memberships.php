<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope\Membership;

use JustinHoltWeb\VelvetRope\Models\Grant;
use JustinHoltWeb\VelvetRope\Models\Member;
use JustinHoltWeb\VelvetRope\Models\Subscription;
use JustinHoltWeb\VelvetRope\Plans\Plans;
use JustinHoltWeb\VelvetRope\Settings;

/**
 * Who has which plan right now: Stripe subscriptions (as the webhooks last left
 * them) plus gift and complimentary grants. Everything that decides access —
 * templates, middleware, plan gates, group sync — asks this.
 */
class Memberships
{
    public function __construct(
        private readonly Plans $plans,
        private readonly Settings $settings,
    ) {}

    public function grants(Subscription $s): bool
    {
        return Access::grants($s->stripe_status, $s->ends_at, $s->trial_ends_at, $s->past_due_since, $this->settings->graceDays);
    }

    /**
     * The user's latest subscription per plan handle (subscriptions for prices
     * that aren't a configured plan are left out).
     *
     * @return array<string, Subscription>
     */
    public function subscriptionsByPlan(int $userId): array
    {
        $member = Member::where('userId', $userId)->first();
        $byPlan = [];

        /** @var Subscription $subscription */
        foreach ($member?->subscriptions()->get() ?? [] as $subscription) {
            $plan = $this->plans->forSubscription($subscription->type, $subscription->stripe_price);

            if ($plan !== null) {
                $byPlan[$plan->handle] ??= $subscription; // newest first (Cashier orders by created_at desc)
            }
        }

        return $byPlan;
    }

    /**
     * Current grants (gifts, comps) per plan handle — the one that runs longest.
     *
     * @return array<string, Grant>
     */
    public function grantsByPlan(int $userId): array
    {
        $byPlan = [];

        foreach (Grant::where('userId', $userId)->current()->orderByDesc('endsAt')->get() as $grant) {
            if ($this->plans->get($grant->plan) !== null) {
                $byPlan[$grant->plan] ??= $grant;
            }
        }

        return $byPlan;
    }

    /** @return list<string> Handles of the plans currently granting the user access. */
    public function grantedPlans(int $userId): array
    {
        $plans = array_keys(array_filter($this->subscriptionsByPlan($userId), $this->grants(...)));

        foreach (array_keys($this->grantsByPlan($userId)) as $plan) {
            $plans[] = $plan;
        }

        return array_values(array_unique(array_map(strval(...), $plans)));
    }
}
