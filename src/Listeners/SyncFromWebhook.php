<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope\Listeners;

use JustinHoltWeb\VelvetRope\Membership\GroupSync;
use JustinHoltWeb\VelvetRope\Models\Member;
use JustinHoltWeb\VelvetRope\Models\Subscription;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookHandled;

/**
 * After Cashier has written a subscription webhook to the database, record when
 * it went past due (for the grace period) and resync the member's groups.
 */
class SyncFromWebhook
{
    private const array TYPES = [
        'customer.subscription.created',
        'customer.subscription.updated',
        'customer.subscription.deleted',
    ];

    public function __construct(private readonly GroupSync $groupSync) {}

    public function handle(WebhookHandled $event): void
    {
        $payload = $event->payload;

        if (! in_array($payload['type'] ?? null, self::TYPES, true)) {
            return;
        }

        $object = $payload['data']['object'] ?? [];

        if (is_string($object['id'] ?? null) && ($subscription = Subscription::where('stripe_id', $object['id'])->first()) !== null) {
            if ($subscription->stripe_status === 'past_due') {
                $subscription->past_due_since ??= now();
            } else {
                $subscription->past_due_since = null;
            }
            $subscription->save();
        }

        $member = is_string($object['customer'] ?? null) ? Cashier::findBillable($object['customer']) : null;

        if ($member instanceof Member) {
            $this->groupSync->sync($member);
        }
    }
}
