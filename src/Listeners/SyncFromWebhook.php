<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope\Listeners;

use JustinHoltWeb\VelvetRope\Membership\Dunning;
use JustinHoltWeb\VelvetRope\Membership\GroupSync;
use JustinHoltWeb\VelvetRope\Membership\SubscriptionMirror;
use JustinHoltWeb\VelvetRope\Models\Member;
use JustinHoltWeb\VelvetRope\Models\Subscription;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookHandled;

/**
 * After Cashier has written a subscription webhook to the database: record the
 * price (for MRR) and when it went past due (for the grace period), resync the
 * member's groups, and send any dunning email that's now due (Pro).
 */
class SyncFromWebhook
{
    private const array TYPES = [
        'customer.subscription.created',
        'customer.subscription.updated',
        'customer.subscription.deleted',
    ];

    public function __construct(
        private readonly GroupSync $groupSync,
        private readonly SubscriptionMirror $mirror,
        private readonly Dunning $dunning,
    ) {}

    public function handle(WebhookHandled $event): void
    {
        $payload = $event->payload;

        if (! in_array($payload['type'] ?? null, self::TYPES, true)) {
            return;
        }

        $object = $payload['data']['object'] ?? [];
        $subscription = is_string($object['id'] ?? null) ? Subscription::where('stripe_id', $object['id'])->first() : null;

        if ($subscription !== null) {
            $this->mirror->annotate($subscription, $object);
        }

        $member = is_string($object['customer'] ?? null) ? Cashier::findBillable($object['customer']) : null;

        if ($member instanceof Member) {
            $this->groupSync->sync($member);

            if ($subscription !== null) {
                $this->dunning->check($subscription);
            }
        }
    }
}
