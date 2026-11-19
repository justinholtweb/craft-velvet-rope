<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope;

use CraftCms\Cms\Cms;
use CraftCms\Cms\User\Elements\User;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use JustinHoltWeb\VelvetRope\Membership\Memberships;
use JustinHoltWeb\VelvetRope\Models\Subscription;
use JustinHoltWeb\VelvetRope\Plans\Plan;
use JustinHoltWeb\VelvetRope\Plans\Plans;

use function CraftCms\Cms\currentUserElement;
use function CraftCms\Cms\site_url;
use function CraftCms\Cms\t;

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
        private readonly Memberships $memberships,
    ) {}

    /**
     * Plans, with names translated into the current site's language (category
     * `site`, so they live alongside your other site translations).
     *
     * @return list<Plan>
     */
    public function plans(): array
    {
        return array_values(array_map(self::translated(...), $this->plans->all()));
    }

    public function plan(string $handle): ?Plan
    {
        $plan = $this->plans->get($handle);

        return $plan !== null ? self::translated($plan) : null;
    }

    private static function translated(Plan $plan): Plan
    {
        return $plan->withName(t($plan->name, category: 'site'));
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

    /** The plan's checkout link on the current site; Pro: with a promotion code to apply. */
    public function checkoutUrl(string $plan, ?string $promo = null): ?string
    {
        if ($this->plans->get($plan) === null) {
            return null;
        }

        return self::linkTo("checkout/{$plan}", $promo !== null && $promo !== '' ? ['promo' => $promo] : null);
    }

    /**
     * Pro: what can be bought as a gift.
     *
     * @return list<array{handle: string, plan: string, planName: string, months: int, url: string}>
     */
    public function giftOptions(): array
    {
        if (! Plugin::isPro()) {
            return [];
        }

        $options = [];
        foreach ($this->plans->giftOptions() as $option) {
            $options[] = [
                'handle' => $option->handle,
                'plan' => $option->plan,
                'planName' => $this->plan($option->plan)->name ?? $option->plan,
                'months' => $option->months,
                'url' => self::linkTo("gift/{$option->handle}"),
            ];
        }

        return $options;
    }

    public function giftUrl(string $option): ?string
    {
        return Plugin::isPro() && $this->plans->giftOption($option) !== null ? self::linkTo("gift/{$option}") : null;
    }

    /** Where a gift-code form posts (fields: `code`, optional `redirect`; needs `csrfInput()` / `@csrf`). */
    public function redeemUrl(): string
    {
        return self::linkTo('redeem');
    }

    /**
     * The outcome of the last redeem attempt, once: `{ok: bool, message: string}`.
     *
     * @return array{ok: bool, message: string}|null
     */
    public function notice(): ?array
    {
        $notice = session('velvetrope.notice');

        return is_array($notice) && isset($notice['ok'], $notice['message']) ? ['ok' => (bool) $notice['ok'], 'message' => (string) $notice['message']] : null;
    }

    /**
     * The user's current gift or complimentary access to a plan, if any.
     *
     * @return array{plan: string, source: string, startsAt: \DateTimeInterface, endsAt: \DateTimeInterface}|null
     */
    public function grant(string $plan, ?User $user = null): ?array
    {
        $user ??= currentUserElement();
        $grant = $user?->id !== null ? ($this->memberships->grantsByPlan((int) $user->id)[$plan] ?? null) : null;

        return $grant === null ? null : ['plan' => $plan, 'source' => $grant->source, 'startsAt' => $grant->startsAt, 'endsAt' => $grant->endsAt];
    }

    public function billingUrl(): string
    {
        return self::linkTo('billing');
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
        $plans = self::planList($plans);

        foreach ($plans as $plan) {
            if ($this->plans->get($plan) === null) {
                Log::warning("Velvet Rope: requireMembership names unknown plan “{$plan}”; nobody can pass it.");
            }
        }

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

    /**
     * A Velvet Rope action URL on a site (default: the current one). Action routes are
     * resolved on every site, including ones with a base path like `/fr/`.
     *
     * @param  array<string, string>|null  $params
     */
    public static function linkTo(string $path, ?array $params = null, ?int $siteId = null): string
    {
        return site_url(trim(Cms::config()->actionTrigger, '/').'/velvetrope/'.$path, $params, siteId: $siteId);
    }

    /**
     * Normalises the plans a gate asks for, failing closed. Only a truly empty
     * argument means "any plan"; a list containing anything but non-empty strings
     * (an unset Twig variable, ids instead of handles) is a template bug, and
     * silently dropping those values would widen the gate to every member.
     *
     * @param  string|array<array-key, mixed>  $plans
     * @return list<string>
     */
    public static function planList(string|array $plans): array
    {
        $list = is_string($plans) ? [$plans] : array_values($plans);

        foreach ($list as $plan) {
            if (! is_string($plan) || trim($plan) === '') {
                throw new InvalidArgumentException('requireMembership expects plan handles (non-empty strings); got '.get_debug_type($plan).'.');
            }
        }

        return array_map(trim(...), $list);
    }

    /** @return list<string> Handles of the plans currently granting the user access. */
    private function grantedPlans(?User $user = null): array
    {
        $user ??= currentUserElement();

        if ($user === null || $user->id === null) {
            return [];
        }

        return $this->grantedMemo[$user->id] ??= $this->memberships->grantedPlans((int) $user->id);
    }

    private function grants(Subscription $s): bool
    {
        return $this->memberships->grants($s);
    }

    /** @var array<int, list<string>> */
    private array $grantedMemo = [];

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

        return $this->memo[$user->id] ??= $this->memberships->subscriptionsByPlan((int) $user->id);
    }
}
