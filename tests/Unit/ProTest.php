<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use JustinHoltWeb\VelvetRope\Checkout\CheckoutSessions;
use JustinHoltWeb\VelvetRope\Gifts\Gifts;
use JustinHoltWeb\VelvetRope\Membership\Dunning;
use JustinHoltWeb\VelvetRope\Models\Gift;
use JustinHoltWeb\VelvetRope\Plans\Gate;
use JustinHoltWeb\VelvetRope\Plans\GiftOption;

$now = Carbon::parse('2026-11-17 12:00:00');

it('walks dunning forward once per lapse and resets on payment', function () use ($now) {
    $graceEnds = $now->copy()->addDays(3);
    $endsSoon = $now->copy()->addHours(20);

    expect(Dunning::nextStage('past_due', Dunning::NONE, true, $graceEnds, $now))->toBe(Dunning::FAILED)
        ->and(Dunning::nextStage('past_due', Dunning::FAILED, true, $graceEnds, $now))->toBe(Dunning::FAILED)
        ->and(Dunning::nextStage('past_due', Dunning::FAILED, true, $endsSoon, $now))->toBe(Dunning::GRACE_ENDING)
        // A failure first noticed inside the last day still gets the "payment failed" email first.
        ->and(Dunning::nextStage('past_due', Dunning::NONE, true, $endsSoon, $now))->toBe(Dunning::FAILED)
        ->and(Dunning::nextStage('past_due', Dunning::GRACE_ENDING, false, $endsSoon, $now))->toBe(Dunning::ENDED)
        ->and(Dunning::nextStage('unpaid', Dunning::FAILED, false, null, $now))->toBe(Dunning::ENDED)
        ->and(Dunning::nextStage('active', Dunning::ENDED, true, null, $now))->toBe(Dunning::NONE)
        ->and(Dunning::nextStage('trialing', Dunning::FAILED, true, null, $now))->toBe(Dunning::NONE);
});

it('never sends an access-ended email to someone who simply cancelled', function () use ($now) {
    expect(Dunning::nextStage('canceled', Dunning::NONE, false, null, $now))->toBe(Dunning::NONE)
        ->and(Dunning::nextStage('incomplete_expired', Dunning::NONE, false, null, $now))->toBe(Dunning::NONE);
});

it('sends just the access-ended email when there is no grace period', function () use ($now) {
    expect(Dunning::nextStage('past_due', Dunning::NONE, false, null, $now))->toBe(Dunning::ENDED)
        ->and(Dunning::nextStage('past_due', Dunning::ENDED, false, null, $now))->toBe(Dunning::ENDED);
});

it('parses plan gates and refuses unreadable rows', function () {
    $uri = Gate::fromRow(['type' => 'uri', 'match' => '/members/*', 'plans' => 'gold, silver ,']);
    expect($uri?->plans)->toBe(['gold', 'silver'])
        ->and($uri?->matchesUri('members/news/1'))->toBeTrue()
        ->and($uri?->matchesUri('/members'))->toBeFalse()
        ->and($uri?->matchesUri('membership'))->toBeFalse()
        ->and(Gate::fromRow(['type' => 'section', 'match' => 'news', 'plans' => ''])?->plans)->toBe([])
        ->and(Gate::fromRow(['type' => 'section', 'match' => 'news/*']))->toBeNull()
        ->and(Gate::fromRow(['type' => 'entry', 'match' => 'x']))->toBeNull()
        ->and(Gate::fromRow(['type' => 'uri', 'match' => ' / ']))->toBeNull();
});

it('parses gift options', function () {
    expect(GiftOption::fromRow(['handle' => 'gold-year', 'plan' => 'gold', 'months' => '12', 'priceId' => 'price_1'])?->months)->toBe(12)
        ->and(GiftOption::fromRow(['handle' => 'gold-year', 'plan' => 'gold', 'months' => 0, 'priceId' => 'price_1']))->toBeNull()
        ->and(GiftOption::fromRow(['handle' => 'gold-year', 'plan' => 'gold', 'months' => 12, 'priceId' => 'prod_1']))->toBeNull()
        ->and(GiftOption::fromRow(['handle' => 'Gold Year', 'plan' => 'gold', 'months' => 12, 'priceId' => 'price_1']))->toBeNull();
});

it('makes unguessable gift codes that survive retyping', function () {
    $code = Gifts::newCode();
    expect($code)->toMatch('/^[A-HJ-NP-Z2-9]{4}(-[A-HJ-NP-Z2-9]{4}){3}$/')
        ->and(Gifts::newCode())->not->toBe($code)
        ->and(Gift::hash(strtolower(str_replace('-', ' ', $code))))->toBe(Gift::hash($code))
        ->and(Gift::hash($code))->toHaveLength(64)
        ->and(Gift::hash($code))->not->toContain(Gift::normalise($code));
});

it('maps site languages onto Stripe Checkout locales', function (string $language, string $locale) {
    expect(CheckoutSessions::stripeLocale($language))->toBe($locale);
})->with([
    ['en-US', 'en'],
    ['en-GB', 'en-GB'],
    ['fr-FR', 'fr'],
    ['fr-CA', 'fr-CA'],
    ['pt_BR', 'pt-BR'],
    ['de', 'de'],
    ['cy-GB', 'auto'],
    ['', 'auto'],
]);
