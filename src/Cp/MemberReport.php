<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope\Cp;

use JustinHoltWeb\VelvetRope\Membership\Memberships;
use JustinHoltWeb\VelvetRope\Membership\Mrr;
use JustinHoltWeb\VelvetRope\Models\Grant;
use JustinHoltWeb\VelvetRope\Models\GuestCheckout;
use JustinHoltWeb\VelvetRope\Models\Member;
use JustinHoltWeb\VelvetRope\Models\Subscription;
use JustinHoltWeb\VelvetRope\Plans\Plans;

/**
 * The numbers behind the Members screen. MRR follows Stripe's definition as far
 * as local data allows: paying subscriptions that still grant access (past-due
 * ones inside their grace period included), normalised to a month, after
 * recurring discounts; trials and gifts are not revenue.
 */
class MemberReport
{
    public function __construct(
        private readonly Memberships $memberships,
        private readonly Plans $plans,
    ) {}

    /** MRR of one subscription right now, or null when it isn't paying (or its price is unknown). */
    public function mrr(Subscription $s): ?int
    {
        if (! $this->memberships->grants($s) || $s->stripe_status === 'trialing' || ($s->trial_ends_at?->isFuture() ?? false)) {
            return null;
        }

        // `amount` already covers every item and seat (see Mrr::priceFields()).
        return Mrr::monthly($s->amount, $s->interval, $s->interval_count, 1, $s->discount_percent, $s->discount_amount);
    }

    /**
     * Site-wide totals.
     *
     * @return array{members: int, trialing: int, pastDue: int, gifted: int, mrr: array<string, int>, unknownPrice: int, review: int}
     */
    public function totals(): array
    {
        $members = $trialing = $pastDue = [];
        $mrr = [];
        $unknown = 0;

        Subscription::query()->whereNotIn('stripe_status', ['canceled', 'incomplete_expired'])->orWhereNull('ends_at')->orWhere('ends_at', '>', now())
            ->chunkById(500, function ($subscriptions) use (&$members, &$trialing, &$pastDue, &$mrr, &$unknown): void {
                foreach ($subscriptions as $s) {
                    if ($this->plans->forSubscription($s->type, $s->stripe_price) === null || ! $this->memberships->grants($s)) {
                        continue;
                    }

                    $members[$s->member_id] = true;

                    if ($s->stripe_status === 'trialing' || ($s->trial_ends_at?->isFuture() ?? false)) {
                        $trialing[$s->member_id] = true;

                        continue;
                    }

                    if ($s->stripe_status === 'past_due') {
                        $pastDue[$s->member_id] = true;
                    }

                    $monthly = $this->mrr($s);

                    if ($monthly === null) {
                        $unknown++;
                    } else {
                        $mrr[(string) $s->currency] = ($mrr[(string) $s->currency] ?? 0) + $monthly;
                    }
                }
            });

        $giftUserIds = Grant::query()->current()->distinct()->pluck('userId')->all();
        $memberUserIds = Member::whereIn('id', array_keys($members))->pluck('userId')->all();

        return [
            'members' => count(array_unique([...$memberUserIds, ...$giftUserIds])),
            'trialing' => count($trialing),
            'pastDue' => count($pastDue),
            'gifted' => count($giftUserIds),
            'mrr' => $mrr,
            'unknownPrice' => $unknown,
            'review' => GuestCheckout::where('outcome', GuestCheckout::REVIEW)->count(),
        ];
    }

    /**
     * One member's plans, as the index and member screen show them.
     *
     * @return list<array{plan: string, planName: string, status: string, granting: bool, mrr: ?int, currency: ?string, source: string, endsAt: ?\DateTimeInterface, subscription: ?Subscription, grant: ?Grant}>
     */
    public function plansFor(int $userId): array
    {
        $rows = [];

        foreach ($this->memberships->subscriptionsByPlan($userId) as $handle => $s) {
            $rows[] = [
                'plan' => $handle,
                'planName' => $this->plans->get($handle)->name ?? $handle,
                'status' => $s->stripe_status,
                'granting' => $this->memberships->grants($s),
                'mrr' => $this->mrr($s),
                'currency' => $s->currency,
                'source' => 'stripe',
                'endsAt' => $s->ends_at,
                'subscription' => $s,
                'grant' => null,
            ];
        }

        foreach ($this->memberships->grantsByPlan($userId) as $handle => $g) {
            $rows[] = [
                'plan' => $handle,
                'planName' => $this->plans->get($handle)->name ?? $handle,
                'status' => $g->source,
                'granting' => true,
                'mrr' => null,
                'currency' => null,
                'source' => $g->source,
                'endsAt' => $g->endsAt,
                'subscription' => null,
                'grant' => $g,
            ];
        }

        return $rows;
    }
}
