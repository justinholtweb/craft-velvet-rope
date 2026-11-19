<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use JustinHoltWeb\VelvetRope\Checkout\CheckoutSessions;
use JustinHoltWeb\VelvetRope\Plans\Plans;
use JustinHoltWeb\VelvetRope\Plugin;
use Stripe\Exception\ExceptionInterface as StripeException;

use function CraftCms\Cms\currentUserElement;

/**
 * Pro. GET /actions/velvetrope/gift/{option} — buy a gift membership (logged in or
 * not). The code is emailed to the buyer once Stripe confirms payment.
 */
class GiftController
{
    public function __invoke(string $option, Plans $plans, CheckoutSessions $sessions): RedirectResponse
    {
        abort_unless(Plugin::isPro(), 404);
        $option = $plans->giftOption($option) ?? abort(404);
        abort_unless((bool) config('cashier.secret'), 503, 'Stripe is not configured.');

        try {
            return redirect($sessions->forGift($option, currentUserElement()?->email));
        } catch (StripeException $e) {
            Log::error("Velvet Rope gift checkout for {$option->handle} failed: {$e->getMessage()}");
            abort(502, 'Payment provider error. Please try again shortly.');
        }
    }
}
