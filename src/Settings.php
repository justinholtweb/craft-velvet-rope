<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope;

use CraftCms\Cms\Plugin\PluginSettings;
use CraftCms\Cms\Support\Env;

use function CraftCms\Cms\site_url;

/**
 * Blank-able properties are nullable: Laravel's ConvertEmptyStringsToNull middleware
 * turns an empty field into null before Craft assigns it.
 */
class Settings extends PluginSettings
{
    /** Stripe publishable key, ideally an env reference ($STRIPE_KEY). */
    public ?string $stripeKey = '$STRIPE_KEY';

    /** Stripe secret key ($STRIPE_SECRET). */
    public ?string $stripeSecret = '$STRIPE_SECRET';

    /** Stripe webhook signing secret ($STRIPE_WEBHOOK_SECRET). Webhooks are refused without it. */
    public ?string $webhookSecret = '$STRIPE_WEBHOOK_SECRET';

    /** Three-letter currency code. */
    public ?string $currency = 'usd';

    /** Days a member keeps access after a failed payment (Stripe status past_due). */
    public int $graceDays = 3;

    /**
     * Plans: rows of handle, name, priceId (price_…), userGroup (UID), trialDays.
     *
     * @var array<array-key, mixed>|null
     */
    public ?array $plans = [];

    /** Where non-members are sent by requireMembership (site path, e.g. a plans page). */
    public ?string $joinPath = '';

    /** Where Stripe Checkout returns on success (site path). */
    public ?string $successPath = '';

    /** Where Stripe Checkout returns when cancelled (site path). */
    public ?string $cancelPath = '';

    /**
     * @return array<string, list<string>>
     */
    public function getRules(): array
    {
        return [
            'graceDays' => ['required', 'integer', 'min:0', 'max:60'],
            'currency' => ['nullable', 'string', 'size:3'],
            'plans' => ['nullable', 'array'],
            'plans.*.handle' => ['required', 'regex:/^[a-z][a-z0-9-]{0,63}$/', 'distinct'],
            'plans.*.priceId' => ['required', 'starts_with:price_'],
            'plans.*.trialDays' => ['nullable', 'integer', 'min:0', 'max:730'],
            'successPath' => ['nullable', 'string', 'max:255', 'not_regex:#^[a-z][a-z0-9+.-]*:#i'],
            'cancelPath' => ['nullable', 'string', 'max:255', 'not_regex:#^[a-z][a-z0-9+.-]*:#i'],
            'joinPath' => ['nullable', 'string', 'max:255', 'not_regex:#^[a-z][a-z0-9+.-]*:#i'],
        ];
    }

    /**
     * An env-referencing setting, resolved. Anything still starting with `$` after
     * parsing is a typo'd or malformed reference: treat it as unset, never as the
     * literal (and therefore public) string.
     */
    public static function secret(?string $value): ?string
    {
        $parsed = Env::parse($value === null ? null : trim($value));

        return $parsed === null || $parsed === '' || str_starts_with($parsed, '$') ? null : $parsed;
    }

    /** A site-relative path as an absolute URL (never another host). */
    public function url(?string $path): string
    {
        // Leading slashes go, so `//evil.example` can only ever be a path on this site.
        return site_url(ltrim((string) $path, '/'));
    }
}
