<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use JustinHoltWeb\VelvetRope\Http\Controllers\BillingPortalController;
use JustinHoltWeb\VelvetRope\Http\Controllers\CheckoutController;

Route::middleware('auth')->prefix('velvet-rope')->name('velvetrope.')->group(function () {
    Route::get('checkout/{plan}', CheckoutController::class)->where('plan', '[a-z][a-z0-9-]{0,63}')->name('checkout');
    Route::get('billing', BillingPortalController::class)->name('billing');
});
