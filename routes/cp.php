<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use JustinHoltWeb\VelvetRope\Http\Controllers\Cp\MemberActionsController;
use JustinHoltWeb\VelvetRope\Http\Controllers\Cp\MembersController;
use JustinHoltWeb\VelvetRope\Http\Controllers\Cp\MemberScreenController;
use JustinHoltWeb\VelvetRope\Plugin;

// The cp group already applies auth + can:accessCp (+ CSRF from `web`).
Route::middleware('can:'.Plugin::PERMISSION_VIEW)->group(function () {
    Route::get('velvetrope', [MembersController::class, 'index'])->name('velvetrope.members');
    Route::get('users/{userId}/membership', [MemberScreenController::class, 'show'])->whereNumber('userId');
    Route::get('myaccount/membership', [MemberScreenController::class, 'show']);
});

Route::middleware('can:'.Plugin::PERMISSION_MANAGE)->prefix('velvetrope/members/{userId}')->whereNumber('userId')->group(function () {
    Route::post('resync', [MemberActionsController::class, 'resync']);
    Route::post('grants', [MemberActionsController::class, 'grant']);
    Route::delete('grants/{grantId}', [MemberActionsController::class, 'revoke'])->whereNumber('grantId');
});
