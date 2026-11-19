<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope\Http\Controllers;

use CraftCms\Cms\Cms;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use JustinHoltWeb\VelvetRope\Models\Member;
use JustinHoltWeb\VelvetRope\Settings;
use Stripe\Exception\ExceptionInterface as StripeException;

use function CraftCms\Cms\currentUserElement;
use function CraftCms\Cms\site_url;

/**
 * GET /actions/velvetrope/billing — Stripe's billing portal for the logged-in member
 * (change card, switch plan, cancel, invoices).
 */
class BillingPortalController
{
    public function __invoke(Settings $settings): RedirectResponse
    {
        $user = currentUserElement();

        if ($user === null) {
            return redirect()->guest(site_url((string) (Cms::config()->getLoginPath() ?: 'login')));
        }

        abort_unless((bool) config('cashier.secret'), 503, 'Stripe is not configured.');

        $member = Member::where('userId', $user->id)->first();
        abort_if($member === null || ! $member->hasStripeId(), 404);

        try {
            return $member->redirectToBillingPortal($settings->url($settings->successPath));
        } catch (StripeException $e) {
            Log::error("Velvet Rope billing portal for member {$member->id} failed: {$e->getMessage()}");
            abort(502, 'Payment provider error. Please try again shortly.');
        }
    }
}
