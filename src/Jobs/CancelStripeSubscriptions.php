<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use JustinHoltWeb\VelvetRope\Models\Subscription;
use Laravel\Cashier\Cashier;
use Stripe\Exception\InvalidRequestException;

/**
 * Cancels a deleted user's Stripe subscriptions immediately, so nobody keeps
 * being billed for an account that no longer exists.
 */
class CancelStripeSubscriptions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 300, 900, 3600];

    /**
     * @param  list<string>  $subscriptionIds  Stripe subscription ids (sub_…)
     */
    public function __construct(public int $userId, public array $subscriptionIds) {}

    public function handle(): void
    {
        foreach ($this->subscriptionIds as $id) {
            try {
                Cashier::stripe()->subscriptions->cancel($id);
            } catch (InvalidRequestException $e) {
                // Already cancelled or gone at Stripe: nothing left to bill.
                if (! in_array($e->getStripeCode(), ['resource_missing'], true) && ! str_contains($e->getMessage(), 'canceled')) {
                    throw $e;
                }
            }

            // Soft-deleted users still have their rows; mark them ended now.
            Subscription::where('stripe_id', $id)->update(['stripe_status' => 'canceled', 'ends_at' => now()]);
            Log::info("Velvet Rope cancelled {$id} for deleted user {$this->userId}");
        }
    }
}
