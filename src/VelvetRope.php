<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope;

use CraftCms\Cms\Cms;
use CraftCms\Cms\User\Elements\User;
use JustinHoltWeb\VelvetRope\Membership\Access;
use JustinHoltWeb\VelvetRope\Models\Member;
use JustinHoltWeb\VelvetRope\Models\Subscription;
use JustinHoltWeb\VelvetRope\Plans\Plan;
use JustinHoltWeb\VelvetRope\Plans\Plans;

use function CraftCms\Cms\currentUserElement;
use function CraftCms\Cms\site_url;

/**
 * The template API, shared by Twig (`velvetRope.…`), Blade (`$velvetRope->…`,
 * `@subscribed`, `@requireMembership`) and route middleware (`member:gold`).
 * Every engine calls these same methods, so they can't drift apart.
 */
class VelvetRope
{
    public function __construct(
        private readonly Plans $plans,
        private readonly Settings $settings,
    ) {}

    /** @return list<Plan> */
    public function plans(): array
    {
        return array_values($this->plans->all());
    }

    public function plan(string $handle): ?Plan
    {
        return $this->plans->get($handle);
    }

    /**
     * Whether the user (default: the current one) has access through any of the
     * given plans — or through any plan at all when none are given.
     */
    public function subscribed(string ...$plans): bool
    {
        return $this->grantedPlans() !== [] && ($plans === [] || array_intersect($plans, $this->grantedPlans()) !== []);
    }

    /**
     * Status of one of the user's plans, or null when they've never had it.
     *
     * @return array{plan: string, status: string, active: bool, onTrial: bool, pastDue: bool, cancelled: bool, endsAt: ?\DateTimeInterface, trialEndsAt: ?\DateTimeInterface}|null
     */
    public function subscription(string $plan, ?User $user = null): ?array
    {
        $subscription = $this->subscriptionsFor($user)[$plan] ?? null;

        if ($subscription === null) {
            return null;
        }

        return [
            'plan' => $plan,
            'status' => $subscription->stripe_status,
            'active' => $this->grants($subscription),
            'onTrial' => $subscription->trial_ends_at?->isFuture() ?? false,
            'pastDue' => $subscription->stripe_status === 'past_due',
            'cancelled' => $subscription->ends_at !== null,
            'endsAt' => $subscription->ends_at,
            'trialEndsAt' => $subscription->trial_ends_at,
        ];
    }

    public function checkoutUrl(string $plan): ?string
    {
        return $this->plans->get($plan) !== null ? site_url("velvet-rope/checkout/{$plan}") : null;
    }

    public function billingUrl(): string
    {
        return site_url('velvet-rope/billing');
    }

    /**
     * Ends the request unless the current user has one of the plans: guests go to
     * the login page, logged-in non-members to `$redirect` (default: the join path
     * setting, else the plan's checkout when there's exactly one plan, else 403).
     *
     * @param  string|list<string>  $plans
     */
    public function requireMembership(string|array $plans = [], ?string $redirect = null): void
    {
        $plans = array_values(array_filter((array) $plans, 'is_string'));

        if ($this->subscribed(...$plans)) {
            return;
        }

        if (currentUserElement() === null) {
            redirect()->guest(site_url((string) (Cms::config()->getLoginPath() ?: 'login')))->throwResponse();
        }

        $target = $redirect ?? ($this->settings->joinPath ? $this->settings->url($this->settings->joinPath) : null);
        $target ??= count($plans) === 1 ? $this->checkoutUrl($plans[0]) : null;

        if ($target === null) {
            abort(403);
        }

        redirect($target)->throwResponse();
    }

    /** @return list<string> Handles of the plans currently granting the user access. */
    private function grantedPlans(?User $user = null): array
    {
        return array_keys(array_filter($this->subscriptionsFor($user), $this->grants(...)));
    }

    private function grants(Subscription $s): bool
    {
        return Access::grants($s->stripe_status, $s->ends_at, $s->trial_ends_at, $s->past_due_since, $this->settings->graceDays);
    }

    /** @var array<int, array<string, Subscription>> */
    private array $memo = [];

    /**
     * The user's latest subscription per plan handle.
     *
     * @return array<string, Subscription>
     */
    private function subscriptionsFor(?User $user): array
    {
        $user ??= currentUserElement();

        if ($user === null || $user->id === null) {
            return [];
        }

        return $this->memo[$user->id] ??= (function () use ($user): array {
            $member = Member::where('userId', $user->id)->first();
            $byPlan = [];

            /** @var Subscription $subscription */
            foreach ($member?->subscriptions()->get() ?? [] as $subscription) {
                $plan = $this->plans->forSubscription($subscription->type, $subscription->stripe_price);

                if ($plan !== null) {
                    $byPlan[$plan->handle] ??= $subscription; // newest first (Cashier orders by created_at desc)
                }
            }

            return $byPlan;
        })();
    }
}
