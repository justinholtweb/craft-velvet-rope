<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope;

use CraftCms\Cms\Plugin\Plugin as BasePlugin;
use CraftCms\Cms\Ui\Controls\Number;
use CraftCms\Cms\Ui\Controls\Text;
use CraftCms\Cms\Ui\Nodes\Field;
use CraftCms\Cms\Ui\Ui;
use CraftCms\Cms\Ui\UiContext;
use Override;

use function CraftCms\Cms\t;

/**
 * Velvet Rope — Paid memberships for Craft users through Laravel Cashier — plans map to user groups, Stripe handles the money.
 *
 * See PLAN.md for the build phases.
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public bool $hasCpSettings = true;

    public const string EDITION_LITE = 'lite';
    public const string EDITION_PRO = 'pro';

    #[Override]
    public static function editions(): array
    {
        return [self::EDITION_LITE, self::EDITION_PRO];
    }

    #[Override]
    protected static function createSettings(): Settings
    {
        return new Settings;
    }

    #[Override]
    public function settingsUi(UiContext $context = new UiContext): Ui
    {
        return Ui::make([
            Field::make(t('Stripe publishable key', category: 'velvetrope'), Text::make('stripeKey'))
                ->instructions(t('Use an environment variable, e.g. $STRIPE_KEY.', category: 'velvetrope')),
            Field::make(t('Stripe secret key', category: 'velvetrope'), Text::make('stripeSecret'))
                ->instructions(t('Use an environment variable, e.g. $STRIPE_SECRET.', category: 'velvetrope')),
            Field::make(t('Webhook signing secret', category: 'velvetrope'), Text::make('webhookSecret'))
                ->instructions(t('Use an environment variable, e.g. $STRIPE_WEBHOOK_SECRET.', category: 'velvetrope')),
            Field::make(t('Grace period (days)', category: 'velvetrope'), Number::make('graceDays'))
                ->instructions(t('How long a member keeps access after a failed payment before leaving their group.', category: 'velvetrope')),
        ]);
    }
}
