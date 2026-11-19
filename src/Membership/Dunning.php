<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope\Membership;

use JustinHoltWeb\VelvetRope\Jobs\SendMemberEmail;
use JustinHoltWeb\VelvetRope\Models\Member;
use JustinHoltWeb\VelvetRope\Models\Subscription;
use JustinHoltWeb\VelvetRope\Plans\Plans;
use JustinHoltWeb\VelvetRope\Plugin;
use JustinHoltWeb\VelvetRope\Settings;
use JustinHoltWeb\VelvetRope\VelvetRope;

/**
 * Pro: emails a member whose payment failed — once when it fails, once a day
 * before their grace period ends, once when access ends — and never twice for
 * the same lapse. `dunning_stage` on the subscription remembers what was sent;
 * paying up resets it.
 */
class Dunning
{
    public const int NONE = 0;

    public const int FAILED = 1;

    public const int GRACE_ENDING = 2;

    public const int ENDED = 3;

    public const array MESSAGES = [
        self::FAILED => 'velvetrope_payment_failed',
        self::GRACE_ENDING => 'velvetrope_grace_ending',
        self::ENDED => 'velvetrope_access_ended',
    ];

    public function __construct(
        private readonly Settings $settings,
        private readonly Plans $plans,
        private readonly Memberships $memberships,
    ) {}

    public function enabled(): bool
    {
        return Plugin::isPro() && $this->settings->dunningEmails;
    }

    /** The stage a subscription should now be at, given what's already been sent. Pure. */
    public static function nextStage(string $status, int $sent, bool $granting, ?\DateTimeInterface $graceEndsAt, ?\DateTimeInterface $now = null): int
    {
        $now ??= now();

        if (in_array($status, ['active', 'trialing'], true)) {
            return self::NONE;
        }

        if ($status === 'past_due' && $granting) {
            $endingSoon = $graceEndsAt !== null && $graceEndsAt->getTimestamp() - $now->getTimestamp() <= 86400;

            return max($sent, $endingSoon && $sent >= self::FAILED ? self::GRACE_ENDING : self::FAILED);
        }

        // Access has gone after a failed payment (grace over, or Stripe gave up). With no grace
        // period, that's the first email they get.
        if (! $granting && $sent >= self::FAILED && in_array($status, ['past_due', 'unpaid', 'canceled', 'incomplete_expired'], true)) {
            return self::ENDED;
        }

        if (! $granting && $sent === self::NONE && in_array($status, ['past_due', 'unpaid'], true)) {
            return self::ENDED;
        }

        return $sent;
    }

    /** Moves one subscription along, sending the email for the stage it reaches. */
    public function check(Subscription $subscription): void
    {
        $granting = $this->memberships->grants($subscription);
        $graceEndsAt = $subscription->past_due_since?->copy()->addDays($this->settings->graceDays);
        $next = self::nextStage($subscription->stripe_status, $subscription->dunning_stage, $granting, $this->settings->graceDays > 0 ? $graceEndsAt : null);

        if ($next === $subscription->dunning_stage) {
            return;
        }

        // Claim the stage first (conditional update), so concurrent webhooks and the hourly sweep send once.
        $claimed = Subscription::whereKey($subscription->getKey())
            ->where('dunning_stage', $subscription->dunning_stage)
            ->update(['dunning_stage' => $next]);
        $subscription->dunning_stage = $next;

        if (! $claimed || $next === self::NONE || ! $this->enabled()) {
            return;
        }

        $member = Member::find($subscription->member_id);
        $plan = $this->plans->forSubscription($subscription->type, $subscription->stripe_price);

        if ($member === null || $plan === null) {
            return;
        }

        SendMemberEmail::dispatch(self::MESSAGES[$next], $member->userId, null, [
            'planName' => $plan->name,
            'billingUrl' => VelvetRope::linkTo('billing'),
            'graceEndsAt' => $graceEndsAt?->toDateString() ?? '',
        ]);
    }

    /** Hourly: grace periods end without Stripe saying anything. */
    public function sweep(): int
    {
        $moved = 0;

        Subscription::query()
            ->where(fn ($q) => $q->where('stripe_status', 'past_due')->orWhere('dunning_stage', '>', self::NONE))
            ->chunkById(200, function ($subscriptions) use (&$moved): void {
                foreach ($subscriptions as $subscription) {
                    $before = $subscription->dunning_stage;
                    $this->check($subscription);
                    $moved += (int) ($before !== $subscription->dunning_stage);
                }
            });

        return $moved;
    }
}
