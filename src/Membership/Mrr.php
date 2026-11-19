<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope\Membership;

/**
 * Monthly recurring revenue, in the currency's smallest unit. Pure, so it's
 * unit-tested without Stripe. Follows Stripe's own normalisation: a year is
 * twelve months, a week 52/12 of a month, a day 365/12 of one.
 */
final class Mrr
{
    public static function monthly(
        ?int $amount,
        ?string $interval,
        ?int $intervalCount = 1,
        ?int $quantity = 1,
        string|float|null $discountPercent = null,
        ?int $discountAmount = null,
    ): ?int {
        if ($amount === null || $interval === null) {
            return null;
        }

        $perInterval = $amount * max(1, $quantity ?? 1);

        if ($discountPercent !== null && (float) $discountPercent > 0) {
            $perInterval *= 1 - min(100.0, (float) $discountPercent) / 100;
        } elseif ($discountAmount !== null && $discountAmount > 0) {
            $perInterval = max(0, $perInterval - $discountAmount);
        }

        $count = max(1, $intervalCount ?? 1);

        $monthly = match ($interval) {
            'day' => $perInterval * 365 / 12 / $count,
            'week' => $perInterval * 52 / 12 / $count,
            'month' => $perInterval / $count,
            'year' => $perInterval / 12 / $count,
            default => null,
        };

        return $monthly === null ? null : (int) round($monthly);
    }

    /**
     * The price fields Velvet Rope keeps from a Stripe subscription object.
     * `amount` is the whole subscription per interval: every item, times its quantity.
     *
     * @param  array<string, mixed>  $subscription
     * @return array{amount: ?int, currency: ?string, interval: ?string, interval_count: ?int, discount_percent: ?float, discount_amount: ?int}
     */
    public static function priceFields(array $subscription): array
    {
        $items = $subscription['items']['data'] ?? [];
        $amount = 0;
        $currency = $interval = $count = null;

        foreach (is_array($items) ? $items : [] as $item) {
            $price = is_array($item['price'] ?? null) ? $item['price'] : [];
            $unit = $price['unit_amount'] ?? null;
            $recurring = is_array($price['recurring'] ?? null) ? $price['recurring'] : [];

            // Metered, tiered or mixed-interval items have no single monthly figure.
            if (! is_int($unit) || ! isset($recurring['interval']) || ($recurring['usage_type'] ?? 'licensed') !== 'licensed'
                || ($interval !== null && ($interval !== $recurring['interval'] || $count !== (int) ($recurring['interval_count'] ?? 1)))) {
                return ['amount' => null, 'currency' => null, 'interval' => null, 'interval_count' => null, 'discount_percent' => null, 'discount_amount' => null];
            }

            $amount += $unit * max(1, (int) ($item['quantity'] ?? 1));
            $currency = strtolower((string) ($price['currency'] ?? ''));
            $interval = (string) $recurring['interval'];
            $count = (int) ($recurring['interval_count'] ?? 1);
        }

        if ($interval === null) {
            return ['amount' => null, 'currency' => null, 'interval' => null, 'interval_count' => null, 'discount_percent' => null, 'discount_amount' => null];
        }

        // `discount` (older API versions) or an expanded `discounts[0]`; coupons that apply once don't recur.
        $discount = $subscription['discount'] ?? null;
        if (! is_array($discount) && is_array($subscription['discounts'][0] ?? null)) {
            $discount = $subscription['discounts'][0];
        }
        $coupon = is_array($discount) && is_array($discount['coupon'] ?? null) ? $discount['coupon'] : null;
        $recurs = $coupon !== null && ($coupon['duration'] ?? 'forever') !== 'once';

        return [
            'amount' => $amount,
            'currency' => $currency !== '' ? $currency : null,
            'interval' => $interval,
            'interval_count' => $count,
            'discount_percent' => $recurs && is_numeric($coupon['percent_off'] ?? null) ? (float) $coupon['percent_off'] : null,
            'discount_amount' => $recurs && is_int($coupon['amount_off'] ?? null) && strtolower((string) ($coupon['currency'] ?? '')) === $currency ? $coupon['amount_off'] : null,
        ];
    }
}
