<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope;

use CraftCms\Cms\Plugin\PluginSettings;

/**
 * Blank-able properties are nullable: Laravel's ConvertEmptyStringsToNull middleware
 * turns an empty field into null before Craft assigns it.
 */
class Settings extends PluginSettings
{
    /** Stripe publishable key. Use an environment variable, e.g. $STRIPE_KEY. */
    public ?string $stripeKey = null;

    /** Stripe secret key. Use an environment variable, e.g. $STRIPE_SECRET. */
    public ?string $stripeSecret = null;

    /** Webhook signing secret. Use an environment variable, e.g. $STRIPE_WEBHOOK_SECRET. */
    public ?string $webhookSecret = null;

    /** Grace period (days). How long a member keeps access after a failed payment before leaving their group. */
    public int $graceDays = 3;
}
