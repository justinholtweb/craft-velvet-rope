<?php

declare(strict_types=1);

use JustinHoltWeb\VelvetRope\Plans\Plan;
use JustinHoltWeb\VelvetRope\VelvetRope;

it('builds plans from settings rows', function () {
    $plan = Plan::fromRow(['handle' => 'gold', 'name' => ' Gold ', 'priceId' => ' price_123 ', 'userGroup' => 'uid-1', 'trialDays' => '14']);

    expect($plan)->not->toBeNull()
        ->and($plan->name)->toBe('Gold')
        ->and($plan->priceId)->toBe('price_123')
        ->and($plan->userGroupUid)->toBe('uid-1')
        ->and($plan->trialDays)->toBe(14);
});

it('rejects malformed plan rows', function (array $row) {
    expect(Plan::fromRow($row))->toBeNull();
})->with([
    [['handle' => 'Gold', 'priceId' => 'price_1']],
    [['handle' => 'gold', 'priceId' => 'prod_1']],
    [['handle' => '', 'priceId' => 'price_1']],
    [['handle' => 'gold plan', 'priceId' => 'price_1']],
]);

it('falls back to the handle for a blank name and clamps trial days', function () {
    $plan = Plan::fromRow(['handle' => 'gold', 'priceId' => 'price_1', 'trialDays' => -4]);

    expect($plan?->name)->toBe('gold')->and($plan?->trialDays)->toBe(0)->and($plan?->userGroupUid)->toBeNull();
});

it('fails closed when a gate is given anything but plan handles', function (mixed $plans) {
    VelvetRope::planList($plans);
})->with([
    [[null]],
    [[123]],
    [['gold', null]],
    [['']],
    [' '],
])->throws(InvalidArgumentException::class);

it('treats only a truly empty argument as "any plan"', function () {
    expect(VelvetRope::planList([]))->toBe([])
        ->and(VelvetRope::planList(' gold '))->toBe(['gold'])
        ->and(VelvetRope::planList(['gold', 'silver']))->toBe(['gold', 'silver']);
});
