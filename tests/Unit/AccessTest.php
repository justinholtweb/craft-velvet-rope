<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use JustinHoltWeb\VelvetRope\Membership\Access;

$now = Carbon::parse('2026-11-17 12:00:00');

it('grants access by Stripe status', function (string $status, bool $grants) use ($now) {
    expect(Access::grants($status, null, null, null, 3, $now))->toBe($grants);
})->with([
    ['active', true],
    ['trialing', true],
    ['incomplete', false],
    ['incomplete_expired', false],
    ['unpaid', false],
    ['canceled', false],
    ['paused', false],
]);

it('keeps past_due members in for the grace period only', function () use ($now) {
    expect(Access::grants('past_due', null, null, $now->copy()->subDays(2), 3, $now))->toBeTrue()
        ->and(Access::grants('past_due', null, null, $now->copy()->subDays(3), 3, $now))->toBeFalse()
        ->and(Access::grants('past_due', null, null, $now->copy()->subHour(), 0, $now))->toBeFalse()
        ->and(Access::grants('past_due', null, null, null, 3, $now))->toBeFalse();
});

it('honours a scheduled cancellation until it takes effect', function () use ($now) {
    expect(Access::grants('active', $now->copy()->addDay(), null, null, 3, $now))->toBeTrue()
        ->and(Access::grants('active', $now->copy()->subSecond(), null, null, 3, $now))->toBeFalse();
});

it('honours a running trial', function () use ($now) {
    expect(Access::grants('incomplete', null, $now->copy()->addDay(), null, 3, $now))->toBeTrue()
        ->and(Access::grants('unpaid', null, $now->copy()->addDay(), null, 3, $now))->toBeFalse();
});

it('only touches the groups it manages', function () {
    // user in Staff(2) + Gold(1); Gold & Silver(3) managed; Silver granted
    expect(Access::groupIds([2, 1], [1, 3], [3]))->toBe([2, 3])
        ->and(Access::groupIds([2], [1, 3], []))->toBe([2])
        ->and(Access::groupIds([], [1], [1, 1]))->toBe([1])
        // a grant for a group that isn't managed is ignored
        ->and(Access::groupIds([2], [1], [9]))->toBe([2]);
});
