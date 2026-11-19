<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope\Membership;

use Illuminate\Support\Carbon;
use JustinHoltWeb\VelvetRope\Models\Member;
use JustinHoltWeb\VelvetRope\Models\Subscription;

/**
 * Keeps local subscription rows in step with Stripe subscription objects. Cashier's
 * webhook handlers write the core columns; this adds what Velvet Rope tracks on
 * top (price for MRR, when it went past due) and can write a whole row itself
 * where Cashier's handlers don't run (guest checkout, `--refresh`).
 */
class SubscriptionMirror
{
    /**
     * Velvet Rope's own columns, from a Stripe subscription object.
     *
     * @param  array<string, mixed>  $data
     */
    public function annotate(Subscription $subscription, array $data): void
    {
        $price = Mrr::priceFields($data);

        // Keep the last known price when Stripe sends an object without one (e.g. a deleted event).
        if ($price['interval'] !== null || ! isset($subscription->interval)) {
            $subscription->fill($price);
        }

        if ($subscription->stripe_status === 'past_due') {
            $subscription->past_due_since ??= now();
        } else {
            $subscription->past_due_since = null;
        }

        $subscription->save();
    }

    /**
     * Creates or updates the member's row for a Stripe subscription object, the
     * way Cashier's webhook handlers would.
     *
     * @param  array<string, mixed>  $data
     */
    public function upsert(Member $member, array $data): Subscription
    {
        /** @var Subscription $subscription */
        $subscription = $member->subscriptions()->firstOrNew(['stripe_id' => (string) $data['id']]);
        $items = is_array($data['items']['data'] ?? null) ? $data['items']['data'] : [];
        $single = count($items) === 1;
        $first = $items[0] ?? [];
        $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];

        $subscription->type ??= (string) ($metadata['type'] ?? $metadata['name'] ?? 'default');
        $subscription->stripe_status = (string) ($data['status'] ?? 'incomplete');
        $subscription->stripe_price = $single ? (string) ($first['price']['id'] ?? '') : null;
        $subscription->quantity = $single && isset($first['quantity']) ? (int) $first['quantity'] : null;
        $subscription->trial_ends_at = isset($data['trial_end']) && is_int($data['trial_end']) ? Carbon::createFromTimestamp($data['trial_end']) : null;

        $periodEnd = $first['current_period_end'] ?? $data['current_period_end'] ?? null;
        $subscription->ends_at = match (true) {
            (bool) ($data['cancel_at_period_end'] ?? false) && is_int($periodEnd) => Carbon::createFromTimestamp($periodEnd),
            is_int($data['cancel_at'] ?? null) => Carbon::createFromTimestamp($data['cancel_at']),
            is_int($data['canceled_at'] ?? null) => Carbon::createFromTimestamp($data['canceled_at']),
            default => null,
        };
        $subscription->save();

        $ids = [];
        foreach ($items as $item) {
            if (! is_array($item) || ! isset($item['id'], $item['price']['id'])) {
                continue;
            }
            $ids[] = $item['id'];
            $subscription->items()->updateOrCreate(['stripe_id' => $item['id']], [
                'stripe_product' => (string) ($item['price']['product'] ?? ''),
                'stripe_price' => (string) $item['price']['id'],
                'quantity' => $item['quantity'] ?? null,
            ]);
        }
        $subscription->items()->whereNotIn('stripe_id', $ids)->delete();

        $this->annotate($subscription, $data);

        return $subscription;
    }
}
