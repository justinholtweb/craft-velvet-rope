<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope\Listeners;

use JustinHoltWeb\VelvetRope\Checkout\GuestCheckouts;
use JustinHoltWeb\VelvetRope\Gifts\Gifts;
use Laravel\Cashier\Events\WebhookReceived;

/**
 * Checkout events Cashier doesn't handle itself: guest sign-ups and gift
 * purchases. Signatures are verified before this runs (and webhooks are refused
 * outright without a signing secret). An exception here means a 500, so Stripe
 * retries; both handlers are idempotent per Checkout session.
 */
class HandleCheckoutWebhooks
{
    public function __construct(
        private readonly GuestCheckouts $guests,
        private readonly Gifts $gifts,
    ) {}

    public function handle(WebhookReceived $event): void
    {
        $payload = $event->payload;

        if (! in_array($payload['type'] ?? null, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
            return;
        }

        $session = $payload['data']['object'] ?? null;

        if (! is_array($session)) {
            return;
        }

        $this->guests->complete($session);
        // Only Pro sells gifts, but one paid for just before a downgrade is still issued.
        $this->gifts->purchased($session);
    }
}
