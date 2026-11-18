<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cashier only verifies webhook signatures when a signing secret is configured;
 * without one it accepts any POST, so anyone could fake a "subscription created"
 * and be put in a paid group. Velvet Rope refuses webhooks until the secret is set.
 */
class RequireWebhookSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('cashier.webhook.secret')) {
            abort(503, 'Stripe webhook signing secret is not configured.');
        }

        return $next($request);
    }
}
