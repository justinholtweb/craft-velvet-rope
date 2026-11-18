<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope;

use CraftCms\Cms\Edition;
use CraftCms\Cms\Plugin\Plugin as BasePlugin;
use CraftCms\Cms\Support\Facades\UserGroups;
use CraftCms\Cms\Ui\Controls\Number;
use CraftCms\Cms\Ui\Controls\Table;
use CraftCms\Cms\Ui\Controls\Text;
use CraftCms\Cms\Ui\Nodes\Field;
use CraftCms\Cms\Ui\Ui;
use CraftCms\Cms\Ui\UiContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\View\Compilers\BladeCompiler;
use JustinHoltWeb\VelvetRope\Http\Middleware\RequireWebhookSecret;
use JustinHoltWeb\VelvetRope\Models\Member;
use JustinHoltWeb\VelvetRope\Models\Subscription;
use JustinHoltWeb\VelvetRope\Models\SubscriptionItem;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookHandled;
use Override;

use function CraftCms\Cms\t;

/**
 * Velvet Rope — paid memberships for Craft users through Laravel Cashier.
 * Plans map Stripe prices to user groups; Stripe's webhooks keep them in sync.
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const string EDITION_LITE = 'lite';

    public const string EDITION_PRO = 'pro';

    public bool $hasCpSettings = true;

    /** Plans grant access through user groups, which need Craft Pro. */
    public Edition $minCmsEdition = Edition::Pro;

    protected array $events = [
        WebhookHandled::class => Listeners\SyncFromWebhook::class,
    ];

    protected array $commands = [
        Commands\SyncCommand::class,
    ];

    #[Override]
    public static function editions(): array
    {
        return [self::EDITION_LITE, self::EDITION_PRO];
    }

    public function boot(): void
    {
        $this->app->bind(Settings::class, fn (): Settings => $this->getSettings());
        $this->app->scoped(VelvetRope::class);
        $this->app->singleton(Listeners\CancelOnUserDelete::class);
        Event::subscribe(Listeners\CancelOnUserDelete::class);
        Event::subscribe(Listeners\RegisterTemplateApi::class);
        $this->registerBlade();
        $settings = $this->getSettings();

        config([
            'cashier.key' => Settings::secret($settings->stripeKey),
            'cashier.secret' => Settings::secret($settings->stripeSecret),
            'cashier.webhook.secret' => Settings::secret($settings->webhookSecret),
            'cashier.currency' => strtolower($settings->currency ?: 'usd'),
        ]);

        Cashier::useCustomerModel(Member::class);
        Cashier::useSubscriptionModel(Subscription::class);
        Cashier::useSubscriptionItemModel(SubscriptionItem::class);

        // Cashier registered its webhook route before plugins load; guard it.
        /** @var Router $router */
        $router = $this->app->make('router');
        $router->aliasMiddleware('member', Http\Middleware\RequireMembership::class);
        foreach ($router->getRoutes()->getRoutes() as $route) {
            if ($route->getName() === 'cashier.webhook') {
                $route->middleware(RequireWebhookSecret::class);
            }
        }
    }

    /**
     * Blade mirrors of the Twig API (both call VelvetRope, so they can't drift):
     * `@subscribed('gold') … @else … @endsubscribed` and `@requireMembership('gold')`.
     */
    private function registerBlade(): void
    {
        $this->callAfterResolving('blade.compiler', function (BladeCompiler $blade): void {
            $blade->if('subscribed', fn (string ...$plans): bool => app(VelvetRope::class)->subscribed(...$plans));
            $blade->directive('requireMembership', fn (string $expression): string => '<?php app(\\'.VelvetRope::class.'::class)->requireMembership('.($expression !== '' ? $expression : '[]').'); ?>');
        });
    }

    #[Override]
    protected function schedule(Schedule $schedule): void
    {
        // Grace periods end on their own; Stripe may send nothing when they do.
        $schedule->command('craft:velvetrope:sync')->hourly()->withoutOverlapping();
    }

    #[Override]
    protected static function createSettings(): Settings
    {
        return new Settings;
    }

    #[Override]
    public function settingsUi(UiContext $context = new UiContext): Ui
    {
        $groups = [['label' => t('— None —', category: 'velvetrope'), 'value' => '']];
        foreach (UserGroups::getAllGroups() as $group) {
            $groups[] = ['label' => $group->name, 'value' => $group->uid];
        }

        return Ui::make([
            Field::make(t('Stripe publishable key', category: 'velvetrope'), Text::make('stripeKey')->placeholder('$STRIPE_KEY'))
                ->instructions(t('Use an environment variable.', category: 'velvetrope')),
            Field::make(t('Stripe secret key', category: 'velvetrope'), Text::make('stripeSecret')->placeholder('$STRIPE_SECRET'))
                ->instructions(t('Use an environment variable.', category: 'velvetrope')),
            Field::make(t('Webhook signing secret', category: 'velvetrope'), Text::make('webhookSecret')->placeholder('$STRIPE_WEBHOOK_SECRET'))
                ->instructions(t('Point a Stripe webhook at /stripe/webhook. Webhooks are refused until this is set.', category: 'velvetrope')),
            Field::make(t('Currency', category: 'velvetrope'), Text::make('currency')->placeholder('usd')),
            Field::make(t('Plans', category: 'velvetrope'), Table::make('plans')
                ->columns([
                    'handle' => ['heading' => t('Handle', category: 'velvetrope'), 'type' => 'singleline', 'code' => true, 'placeholder' => 'gold'],
                    'name' => ['heading' => t('Name', category: 'velvetrope'), 'type' => 'singleline'],
                    'priceId' => ['heading' => t('Stripe price ID', category: 'velvetrope'), 'type' => 'singleline', 'code' => true, 'placeholder' => 'price_…'],
                    'userGroup' => ['heading' => t('User group', category: 'velvetrope'), 'type' => 'select', 'options' => $groups],
                    'trialDays' => ['heading' => t('Trial days', category: 'velvetrope'), 'type' => 'number', 'min' => 0],
                ])
                ->allowAdd()->allowDelete()->allowReorder()
                ->addRowLabel(t('Add a plan', category: 'velvetrope')))
                ->instructions(t('Members of a plan are put in its user group while their subscription is paid up, and taken out when it isn’t.', category: 'velvetrope')),
            Field::make(t('Grace period (days)', category: 'velvetrope'), Number::make('graceDays')->min(0)->max(60))
                ->instructions(t('How long a member keeps access after a failed payment.', category: 'velvetrope')),
            Field::make(t('Join path', category: 'velvetrope'), Text::make('joinPath')->placeholder('join'))
                ->instructions(t('Where non-members are sent when a page requires membership (e.g. your plans page).', category: 'velvetrope')),
            Field::make(t('Success path', category: 'velvetrope'), Text::make('successPath')->placeholder('members/welcome'))
                ->instructions(t('Site path Stripe returns to after checkout.', category: 'velvetrope')),
            Field::make(t('Cancel path', category: 'velvetrope'), Text::make('cancelPath')->placeholder('join'))
                ->instructions(t('Site path Stripe returns to when checkout is cancelled.', category: 'velvetrope')),
        ]);
    }
}
