<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use JustinHoltWeb\VelvetRope\Http\Controllers\BillingPortalController;
use JustinHoltWeb\VelvetRope\Http\Controllers\CheckoutController;
use JustinHoltWeb\VelvetRope\Http\Controllers\GiftController;
use JustinHoltWeb\VelvetRope\Http\Controllers\RedeemController;

// Action routes (`/actions/velvetrope/…`), because Craft resolves those on every site, including sites
// with a base path (`/fr/actions/velvetrope/…`) — plain web routes only match at the domain root.
// Craft registers this file under the site action prefix and the CP's; the site one is what templates link.
// Every checkout/gift link creates a Stripe session, hence the rate limit (a setting).
Route::get('checkout/{plan}', CheckoutController::class)->where('plan', '[a-z][a-z0-9-]{0,63}')->middleware('throttle:velvetrope-checkout');
Route::get('gift/{option}', GiftController::class)->where('option', '[a-z][a-z0-9-]{0,63}')->middleware('throttle:velvetrope-checkout');
// Both send guests to the site's login page themselves (Laravel's `auth` would send them to the CP's).
Route::get('billing', BillingPortalController::class);
Route::post('redeem', RedeemController::class)->middleware('throttle:10,1');
