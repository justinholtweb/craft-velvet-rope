<?php

declare(strict_types=1);

use JustinHoltWeb\VelvetRope\Membership\Mrr;

it('normalises prices to a month the way Stripe does', function (int $amount, string $interval, int $count, int $monthly) {
    expect(Mrr::monthly($amount, $interval, $count))->toBe($monthly);
})->with([
    [1500, 'month', 1, 1500],
    [12000, 'year', 1, 1000],
    [4500, 'month', 3, 1500],
    [1000, 'week', 1, 4333],
    [100, 'day', 1, 3042],
]);

it('applies recurring discounts and quantities', function () {
    expect(Mrr::monthly(12000, 'year', 1, 2, 50))->toBe(1000)
        ->and(Mrr::monthly(1500, 'month', 1, 1, null, 500))->toBe(1000)
        ->and(Mrr::monthly(1500, 'month', 1, 1, null, 9999))->toBe(0)
        ->and(Mrr::monthly(1500, 'month', 1, 1, '0.00'))->toBe(1500)
        ->and(Mrr::monthly(null, 'month'))->toBeNull()
        ->and(Mrr::monthly(1500, 'fortnight'))->toBeNull();
});

it('reads the price from a Stripe subscription object', function () {
    $sub = ['items' => ['data' => [
        ['quantity' => 2, 'price' => ['unit_amount' => 1000, 'currency' => 'USD', 'recurring' => ['interval' => 'month', 'interval_count' => 1, 'usage_type' => 'licensed']]],
        ['quantity' => 1, 'price' => ['unit_amount' => 500, 'currency' => 'usd', 'recurring' => ['interval' => 'month']]],
    ]], 'discount' => ['coupon' => ['percent_off' => 25, 'duration' => 'forever']]];

    expect(Mrr::priceFields($sub))->toBe([
        'amount' => 2500, 'currency' => 'usd', 'interval' => 'month', 'interval_count' => 1,
        'discount_percent' => 25.0, 'discount_amount' => null,
    ]);
});

it('gives no figure for metered or mixed-interval subscriptions, and ignores one-off coupons', function () {
    $metered = ['items' => ['data' => [['price' => ['unit_amount' => 10, 'currency' => 'usd', 'recurring' => ['interval' => 'month', 'usage_type' => 'metered']]]]]];
    $mixed = ['items' => ['data' => [
        ['price' => ['unit_amount' => 10, 'currency' => 'usd', 'recurring' => ['interval' => 'month']]],
        ['price' => ['unit_amount' => 10, 'currency' => 'usd', 'recurring' => ['interval' => 'year']]],
    ]]];
    $once = ['items' => ['data' => [['price' => ['unit_amount' => 1000, 'currency' => 'usd', 'recurring' => ['interval' => 'month']]]]],
        'discount' => ['coupon' => ['amount_off' => 500, 'currency' => 'usd', 'duration' => 'once']]];

    expect(Mrr::priceFields($metered)['amount'])->toBeNull()
        ->and(Mrr::priceFields($mixed)['amount'])->toBeNull()
        ->and(Mrr::priceFields($once)['discount_amount'])->toBeNull()
        ->and(Mrr::priceFields([])['interval'])->toBeNull();
});
