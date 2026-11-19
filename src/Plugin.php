<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope;

use CraftCms\Cms\Cp\Data\NavItem;
use CraftCms\Cms\Edition;
use CraftCms\Cms\Gql\Events\GqlSchemaComponentsResolving;
use CraftCms\Cms\Gql\GqlQueries;
use CraftCms\Cms\Http\Middleware\HandleTemplateRequest;
use CraftCms\Cms\Plugin\Plugin as BasePlugin;
use CraftCms\Cms\Support\Facades\Plugins;
use CraftCms\Cms\Support\Facades\UserGroups;
use CraftCms\Cms\SystemMessage\Models\SystemMessage;
use CraftCms\Cms\SystemMessage\SystemMessages;
use CraftCms\Cms\Ui\Controls\IconPicker;
use CraftCms\Cms\Ui\Controls\Lightswitch;
use CraftCms\Cms\Ui\Controls\Number;
use CraftCms\Cms\Ui\Controls\Table;
use CraftCms\Cms\Ui\Controls\Text;
use CraftCms\Cms\Ui\Nodes\Field;
use CraftCms\Cms\Ui\Ui;
use CraftCms\Cms\Ui\UiContext;
use CraftCms\Cms\User\Data\Permission;
use CraftCms\Cms\User\Events\EditUserScreensResolving;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\Compilers\BladeCompiler;
use JustinHoltWeb\VelvetRope\Http\Middleware\RequireWebhookSecret;
use JustinHoltWeb\VelvetRope\Models\Member;
use JustinHoltWeb\VelvetRope\Models\Subscription;
use JustinHoltWeb\VelvetRope\Models\SubscriptionItem;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookHandled;
use Laravel\Cashier\Events\WebhookReceived;
use Override;

use function CraftCms\Cms\currentUserElement;
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

    public const string PERMISSION_VIEW = 'velvetrope:viewMembers';

    public const string PERMISSION_MANAGE = 'velvetrope:manageMembers';

    public bool $hasCpSettings = true;

    public static function isPro(): bool
    {
        return Plugins::getPlugin('velvetrope')?->is(self::EDITION_PRO) ?? false;
    }

    /** Plans grant access through user groups, which need Craft Pro. */
    public Edition $minCmsEdition = Edition::Pro;

    protected array $events = [
        WebhookHandled::class => Listeners\SyncFromWebhook::class,
        WebhookReceived::class => Listeners\HandleCheckoutWebhooks::class,
        EditUserScreensResolving::class => Listeners\AddMembershipScreen::class,
        GqlSchemaComponentsResolving::class => Listeners\RegisterGqlComponents::class,
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
        $this->hasCpSection = $this->getSettings()->showInCpNav;
        $this->app->make(GqlQueries::class)->register(Gql\PlansQuery::class);
        $this->registerSystemMessages();
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

        // Test seam for the harness's local Stripe mock: loopback only, never in production.
        $apiBase = Env::get('VELVETROPE_STRIPE_API_BASE');
        if (is_string($apiBase) && ! $this->app->isProduction() && preg_match('#^http://(127\.0\.0\.1|localhost):\d{2,5}$#D', $apiBase)) {
            Cashier::$apiBaseUrl = $apiBase;
        }

        Cashier::useCustomerModel(Member::class);
        Cashier::useSubscriptionModel(Subscription::class);
        Cashier::useSubscriptionItemModel(SubscriptionItem::class);

        // Cashier registered its webhook route before plugins load; guard it.
        /** @var Router $router */
        $router = $this->app->make('router');
        $router->aliasMiddleware('member', Http\Middleware\RequireMembership::class);
        $this->registerPlanGates($router);

        // Every checkout or gift link creates a Stripe session; cap them per visitor.
        RateLimiter::for('velvetrope-checkout', function (Request $request): Limit {
            $limit = $this->getSettings()->checkoutRateLimit;

            return $limit > 0 ? Limit::perMinute($limit)->by('velvetrope:'.(currentUserElement()->id ?? $request->ip())) : Limit::none();
        });
        foreach ($router->getRoutes()->getRoutes() as $route) {
            if ($route->getName() === 'cashier.webhook') {
                $route->middleware(RequireWebhookSecret::class);
            }
        }
    }

    /**
     * Pro plan gates run on front-end requests after the session is known (so the
     * current user is) and before Craft renders a template route — i.e. just
     * before `HandleTemplateRequest` in the `craft.web` group.
     */
    private function registerPlanGates(Router $router): void
    {
        $group = $router->getMiddlewareGroups()['craft.web'] ?? [];

        if (in_array(Http\Middleware\EnforcePlanGates::class, $group, true)) {
            return;
        }

        $at = array_search(HandleTemplateRequest::class, $group, true);
        array_splice($group, $at === false ? count($group) : (int) $at, 0, [Http\Middleware\EnforcePlanGates::class]);
        $router->middlewareGroup('craft.web', $group);
    }

    /** Emails members get, editable per language in Utilities → System Messages. */
    private function registerSystemMessages(): void
    {
        $messages = $this->app->make(SystemMessages::class);
        $variables = [
            'velvetrope_payment_failed' => ['planName' => 'Plan name', 'billingUrl' => 'Billing page URL', 'graceEndsAt' => 'Date access ends unless paid'],
            'velvetrope_grace_ending' => ['planName' => 'Plan name', 'billingUrl' => 'Billing page URL', 'graceEndsAt' => 'Date access ends unless paid'],
            'velvetrope_access_ended' => ['planName' => 'Plan name', 'billingUrl' => 'Billing page URL'],
            'velvetrope_gift_purchased' => ['code' => 'Gift code', 'planName' => 'Plan name', 'months' => 'Months', 'redeemUrl' => 'Redeem page URL'],
        ];

        foreach ($variables as $key => $vars) {
            $messages->register($key, fn () => new SystemMessage([
                'key' => $key,
                'heading' => t("{$key}_heading", category: 'velvetrope'),
                'subject' => t("{$key}_subject", category: 'velvetrope'),
                'body' => t("{$key}_body", category: 'velvetrope'),
                'variables' => array_map(fn (string $label) => t($label, category: 'velvetrope'), $vars),
            ]));
        }
    }

    /** Permissions: see members; manage them (resync, complimentary access). */
    #[Override]
    protected function getPermissions(): array
    {
        return [
            new Permission(
                key: self::PERMISSION_VIEW,
                label: t('View members', category: 'velvetrope'),
                nested: collect([
                    new Permission(self::PERMISSION_MANAGE, t('Manage memberships', category: 'velvetrope'), t('Resync user groups and (Pro) grant or revoke complimentary access.', category: 'velvetrope')),
                ]),
            ),
        ];
    }

    #[Override]
    public function getCpNavItem(): NavItem|array|null
    {
        if (! Gate::check(self::PERMISSION_VIEW)) {
            return null;
        }

        $settings = $this->getSettings();

        return new NavItem()
            ->label($settings->navLabel() ?? t('Members', category: 'velvetrope'))
            ->href('velvetrope')
            ->icon($settings->navIcon() ?? $this->cpNavIconPath());
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
            Field::make(t('Checkout rate limit', category: 'velvetrope'), Number::make('checkoutRateLimit')->min(0)->max(1000)->suffix(t('per minute', category: 'velvetrope')))
                ->instructions(t('How many checkout or gift links one visitor may open per minute (each creates a Stripe Checkout session). 0 for no limit.', category: 'velvetrope')),
            Field::make(t('Guest checkout', category: 'velvetrope'), Lightswitch::make('guestCheckout'))
                ->instructions(t('Let people check out without logging in. A pending account is created from the email they pay with and sent Craft’s activation email; nobody can sign in to it until that inbox sets a password. If an account already has that email, the membership is added to it. Off: guests are sent to log in first.', category: 'velvetrope')),
            Field::make(t('Show Members in the sidebar', category: 'velvetrope'), Lightswitch::make('showInCpNav'))
                ->instructions(t('Only people with the “View members” permission see it.', category: 'velvetrope')),
            Field::make(t('Sidebar label', category: 'velvetrope'), Text::make('cpNavLabel')->placeholder(t('Members', category: 'velvetrope'))),
            Field::make(t('Sidebar icon', category: 'velvetrope'), IconPicker::make('cpNavIcon')->sets('*'))
                ->instructions(t('Leave blank for Velvet Rope’s own icon.', category: 'velvetrope')),
            ...($this->is(self::EDITION_PRO) ? [
                Field::make(t('Promotion codes', category: 'velvetrope'), Lightswitch::make('allowPromotionCodes'))
                    ->instructions(t('Show a promotion-code box on Stripe Checkout. Codes are created in Stripe. A link can also carry one: `velvetRope.checkoutUrl(\'gold\', \'SPRING\')`.', category: 'velvetrope')),
                Field::make(t('Dunning emails', category: 'velvetrope'), Lightswitch::make('dunningEmails'))
                    ->instructions(t('Email members when a payment fails, a day before their grace period ends, and when access ends. Edit the wording in Utilities → System Messages.', category: 'velvetrope')),
                Field::make(t('Gift options', category: 'velvetrope'), Table::make('giftOptions')
                    ->columns([
                        'handle' => ['heading' => t('Handle', category: 'velvetrope'), 'type' => 'singleline', 'code' => true, 'placeholder' => 'gold-year'],
                        'plan' => ['heading' => t('Plan handle', category: 'velvetrope'), 'type' => 'singleline', 'code' => true, 'placeholder' => 'gold'],
                        'months' => ['heading' => t('Months', category: 'velvetrope'), 'type' => 'number', 'min' => 1, 'max' => 120],
                        'priceId' => ['heading' => t('One-time Stripe price ID', category: 'velvetrope'), 'type' => 'singleline', 'code' => true, 'placeholder' => 'price_…'],
                    ])
                    ->allowAdd()->allowDelete()->allowReorder()
                    ->addRowLabel(t('Add a gift option', category: 'velvetrope')))
                    ->instructions(t('Gifts are paid once; the buyer is emailed a code that gives whoever redeems it the plan for that many months.', category: 'velvetrope')),
                Field::make(t('Redeem path', category: 'velvetrope'), Text::make('redeemPath')->placeholder('gift'))
                    ->instructions(t('Site path of your page with the gift-code form (linked from the gift email).', category: 'velvetrope')),
                Field::make(t('Plan gates', category: 'velvetrope'), Table::make('gates')
                    ->columns([
                        'type' => ['heading' => t('Gate', category: 'velvetrope'), 'type' => 'select', 'options' => [
                            ['label' => t('Entries in section', category: 'velvetrope'), 'value' => 'section'],
                            ['label' => t('URI pattern', category: 'velvetrope'), 'value' => 'uri'],
                        ]],
                        'match' => ['heading' => t('Section handle or URI', category: 'velvetrope'), 'type' => 'singleline', 'code' => true, 'placeholder' => 'members/*'],
                        'plans' => ['heading' => t('Plans (blank: any plan)', category: 'velvetrope'), 'type' => 'singleline', 'code' => true, 'placeholder' => 'gold, silver'],
                    ])
                    ->allowAdd()->allowDelete()->allowReorder()
                    ->addRowLabel(t('Add a gate', category: 'velvetrope')))
                    ->instructions(t('Front-end pages only members of these plans may see; everyone else is sent to log in or to your join page. The first matching gate wins. Gates protect pages, not data: content shown elsewhere (another template, GraphQL) is not covered.', category: 'velvetrope')),
            ] : []),
        ]);
    }
}
