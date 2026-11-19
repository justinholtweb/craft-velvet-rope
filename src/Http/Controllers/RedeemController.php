<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope\Http\Controllers;

use CraftCms\Cms\Cms;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use JustinHoltWeb\VelvetRope\Gifts\Gifts;
use JustinHoltWeb\VelvetRope\Settings;

use function CraftCms\Cms\currentUserElement;
use function CraftCms\Cms\site_url;
use function CraftCms\Cms\t;

/**
 * POST /actions/velvetrope/redeem (logged in, CSRF-protected, rate-limited) — turns a
 * gift code into access. `redirect` may name a path on this site.
 */
class RedeemController
{
    public function __invoke(Request $request, Gifts $gifts, Settings $settings): RedirectResponse
    {
        $user = currentUserElement();

        if ($user === null) {
            return redirect()->guest(site_url((string) (Cms::config()->getLoginPath() ?: 'login')));
        }

        $code = $request->input('code');
        $grant = is_string($code) ? $gifts->redeem($code, (int) $user->id) : null;
        // Only ever a path on this site (never `back()`, which would follow a Referer header anywhere).
        $redirect = $request->input('redirect');
        $redirect = is_string($redirect) && $redirect !== '' && ! preg_match('#^[a-z][a-z0-9+.-]*:#i', $redirect) ? $redirect : null;

        if ($grant === null) {
            return redirect($settings->url($redirect ?? ($settings->redeemPath ?: $settings->successPath)))
                ->with('velvetrope.notice', ['ok' => false, 'message' => t('That gift code isn’t valid, or it has already been used.', category: 'velvetrope')]);
        }

        return redirect($settings->url($redirect ?? $settings->successPath))
            ->with('velvetrope.notice', ['ok' => true, 'message' => t('Gift redeemed: you have {plan} until {date}.', [
                'plan' => $grant['planName'],
                'date' => $grant['endsAt'],
            ], category: 'velvetrope')]);
    }
}
