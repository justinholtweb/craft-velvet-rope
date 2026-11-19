<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope;

use CraftCms\Cms\Cp\Icons;
use CraftCms\Cms\Cp\IconSets;
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
     * Let people who aren't logged in check out. Their account is created from the
     * email they pay with — pending until they verify it — or the subscription is
     * attached to the existing account with that email. Off by default.
     */
    public bool $guestCheckout = false;

    /**
     * Checkout and gift links a visitor may open per minute (each one creates a Stripe
     * Checkout session). 0 turns the limit off.
     */
    public int $checkoutRateLimit = 20;

    /** Show the Members section in the control panel sidebar. */
    public bool $showInCpNav = true;

    /** Sidebar label (blank: “Members”). */
    public ?string $cpNavLabel = null;

    /** Sidebar icon (blank: Velvet Rope's own). */
    public ?string $cpNavIcon = null;

    // ---- Pro ----

    /** Pro: offer a promotion-code box on Stripe Checkout. */
    public bool $allowPromotionCodes = false;

    /** Pro: email members when a payment fails, before grace ends, and when access ends. */
    public bool $dunningEmails = true;

    /**
     * Pro: gift options — rows of handle, plan, months, priceId (a one-time Stripe price).
     *
     * @var array<array-key, mixed>|null
     */
    public ?array $giftOptions = [];

    /** Pro: the site path of your “redeem a gift” page, linked from the gift email. */
    public ?string $redeemPath = '';

    /**
     * Pro: plan gates — rows of type (section|uri), match (section handle or URI pattern), plans.
     *
     * @var array<array-key, mixed>|null
     */
    public ?array $gates = [];

    /**
     * @return array<string, list<string>>
     */
    public function getRules(): array
    {
        return [
            'graceDays' => ['required', 'integer', 'min:0', 'max:60'],
            'checkoutRateLimit' => ['required', 'integer', 'min:0', 'max:1000'],
            'currency' => ['nullable', 'string', 'size:3'],
            'plans' => ['nullable', 'array'],
            'plans.*.handle' => ['required', 'regex:/^[a-z][a-z0-9-]{0,63}$/', 'distinct'],
            'plans.*.priceId' => ['required', 'starts_with:price_'],
            'plans.*.trialDays' => ['nullable', 'integer', 'min:0', 'max:730'],
            'successPath' => ['nullable', 'string', 'max:255', 'not_regex:#^[a-z][a-z0-9+.-]*:#i'],
            'cancelPath' => ['nullable', 'string', 'max:255', 'not_regex:#^[a-z][a-z0-9+.-]*:#i'],
            'joinPath' => ['nullable', 'string', 'max:255', 'not_regex:#^[a-z][a-z0-9+.-]*:#i'],
            'cpNavLabel' => ['nullable', 'string', 'max:100'],
            'cpNavIcon' => ['nullable', 'string', 'max:100', 'regex:/^([a-zA-Z][\w\-]*:)?[\w\-]+$/'],
            'redeemPath' => ['nullable', 'string', 'max:255', 'not_regex:#^[a-z][a-z0-9+.-]*:#i'],
            'giftOptions' => ['nullable', 'array'],
            'giftOptions.*.handle' => ['required', 'regex:/^[a-z][a-z0-9-]{0,63}$/', 'distinct'],
            'giftOptions.*.plan' => ['required', 'regex:/^[a-z][a-z0-9-]{0,63}$/'],
            'giftOptions.*.months' => ['required', 'integer', 'min:1', 'max:120'],
            'giftOptions.*.priceId' => ['required', 'starts_with:price_'],
            'gates' => ['nullable', 'array'],
            'gates.*.type' => ['required', 'in:section,uri'],
            'gates.*.match' => ['required', 'string', 'max:255'],
            'gates.*.plans' => ['nullable', 'string', 'max:255'],
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

    /** The sidebar label, or null for the default. */
    public function navLabel(): ?string
    {
        $label = trim((string) $this->cpNavLabel);

        return $label === '' ? null : $label;
    }

    /** The sidebar icon if it names a real Craft or icon-set icon, else null. */
    public function navIcon(): ?string
    {
        $icon = trim((string) $this->cpNavIcon);

        if ($icon === '') {
            return null;
        }

        if ($custom = IconSets::parse($icon)) {
            $svg = rescue(fn () => app(IconSets::class)->getIconSetByHandle($custom[0])?->getSvg($custom[1]), null, false);

            return is_string($svg) && stripos($svg, '<svg') !== false ? $icon : null;
        }

        return preg_match('/^[\da-z\-]+$/D', $icon) && is_file(Icons::resolveIconPath($icon)) ? $icon : null;
    }

    /** A site-relative path as an absolute URL (never another host). */
    public function url(?string $path): string
    {
        // Leading slashes go, so `//evil.example` can only ever be a path on this site.
        return site_url(ltrim((string) $path, '/'));
    }
}
