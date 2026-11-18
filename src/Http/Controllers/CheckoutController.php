<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope\Http\Controllers;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use JustinHoltWeb\VelvetRope\Models\Member;
use JustinHoltWeb\VelvetRope\Plans\Plans;
use JustinHoltWeb\VelvetRope\Settings;
use Stripe\Exception\ApiErrorException;

use function CraftCms\Cms\currentUserElement;

/**
 * GET /velvet-rope/checkout/{plan} — logged-in users only. Sends them to Stripe
 * Checkout for the plan; the webhook does the rest.
 */
class CheckoutController
{
    public function __invoke(string $plan, Plans $plans, Settings $settings): Responsable|RedirectResponse
    {
        $plan = $plans->get($plan) ?? abort(404);
        $user = currentUserElement() ?? abort(403);
        abort_unless((bool) config('cashier.secret'), 503, 'Stripe is not configured.');

        $member = Member::forUser((int) $user->id);

        if ($member->subscribed($plan->handle)) {
            return redirect($settings->url($settings->successPath));
        }

        $builder = $member->newSubscription($plan->handle, $plan->priceId);

        if ($plan->trialDays > 0) {
            $builder->trialDays($plan->trialDays);
        }

        try {
            return $builder->checkout([
                'success_url' => $settings->url($settings->successPath),
                'cancel_url' => $settings->url($settings->cancelPath),
            ]);
        } catch (ApiErrorException $e) {
            Log::error("Velvet Rope checkout for plan {$plan->handle} failed: {$e->getMessage()}");
            abort(502, 'Payment provider error. Please try again shortly.');
        }
    }
}
