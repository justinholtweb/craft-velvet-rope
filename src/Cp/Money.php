<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope\Cp;

use NumberFormatter;

/** Amounts in a currency's smallest unit, formatted for the CP. */
final class Money
{
    /** Stripe's zero-decimal currencies: amounts are already whole units. */
    private const array ZERO_DECIMAL = ['bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf'];

    public static function format(int $amount, string $currency, ?string $locale = null): string
    {
        $currency = strtolower($currency);
        $value = in_array($currency, self::ZERO_DECIMAL, true) ? $amount : $amount / 100;
        $formatter = new NumberFormatter($locale ?? app()->getLocale(), NumberFormatter::CURRENCY);
        $formatted = (string) $formatter->formatCurrency($value, strtoupper($currency));

        return $formatted !== '' ? $formatted : strtoupper($currency).' '.number_format($value, 2);
    }

    /**
     * @param  array<string, int>  $byCurrency
     */
    public static function formatAll(array $byCurrency): string
    {
        if ($byCurrency === []) {
            return '—';
        }

        ksort($byCurrency);

        return implode(' + ', array_map(fn (string $c, int $a) => self::format($a, $c), array_keys($byCurrency), $byCurrency));
    }
}
