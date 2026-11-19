<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope\Http\Controllers;

use CraftCms\Cms\Cms;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use JustinHoltWeb\VelvetRope\Checkout\CheckoutSessions;
use JustinHoltWeb\VelvetRope\Membership\Memberships;
use JustinHoltWeb\VelvetRope\Models\Member;
use JustinHoltWeb\VelvetRope\Plans\Plans;
use JustinHoltWeb\VelvetRope\Plugin;
use JustinHoltWeb\VelvetRope\Settings;
use JustinHoltWeb\VelvetRope\VelvetRope;
use Stripe\Exception\ExceptionInterface as StripeException;

use function CraftCms\Cms\currentUserElement;
use function CraftCms\Cms\site_url;

/**
 * GET /actions/velvetrope/checkout/{plan}[?promo=CODE] — sends the visitor to Stripe
 * Checkout for the plan; the webhook does the rest. Guests are sent to log in,
 * unless guest checkout is switched on.
 */
class CheckoutController
{
    public function __invoke(Request $request, string $plan, Plans $plans, Settings $settings, CheckoutSessions $sessions, Memberships $memberships): Responsable|RedirectResponse
    {
        $plan = $plans->get($plan) ?? abort(404);
        $user = currentUserElement();
        $promo = is_string($request->query('promo')) ? $request->query('promo') : null;

        if ($user === null && ! $settings->guestCheckout) {
            return redirect()->guest(site_url((string) (Cms::config()->getLoginPath() ?: 'login')));
        }

        abort_unless((bool) config('cashier.secret'), 503, 'Stripe is not configured.');

        try {
            if ($user === null) {
                return redirect($sessions->forGuest($plan, $promo));
            }

            $granted = $memberships->grantedPlans((int) $user->id);

            if (in_array($plan->handle, $granted, true)) {
                return redirect($settings->url($settings->successPath));
            }

            // Lite: one plan at a time — switching happens in Stripe's billing portal.
            if ($granted !== [] && ! Plugin::isPro()) {
                return redirect(VelvetRope::linkTo('billing'));
            }

            return $sessions->forMember(Member::forUser((int) $user->id), $plan, $promo);
        } catch (StripeException $e) {
            Log::error("Velvet Rope checkout for plan {$plan->handle} failed: {$e->getMessage()}");
            abort(502, 'Payment provider error. Please try again shortly.');
        }
    }
}
