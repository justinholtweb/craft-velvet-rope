<?php

declare(strict_types=1);

namespace JustinHoltWeb\VelvetRope\Checkout;

use CraftCms\Cms\Support\Facades\Sites;
use Illuminate\Support\Facades\Log;
use JustinHoltWeb\VelvetRope\Models\Member;
use JustinHoltWeb\VelvetRope\Plans\GiftOption;
use JustinHoltWeb\VelvetRope\Plans\Plan;
use JustinHoltWeb\VelvetRope\Plugin;
use JustinHoltWeb\VelvetRope\Settings;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Checkout;
use Stripe\Exception\ExceptionInterface as StripeException;

/**
 * Builds Stripe Checkout sessions: subscriptions for logged-in members (through
 * Cashier) and for guests, and one-time payments for gifts (Pro). Return URLs are
 * on the site the visitor is on, and Checkout speaks that site's language.
 */
class CheckoutSessions
{
    /** Locales Stripe Checkout supports (besides `auto`). */
    private const array STRIPE_LOCALES = ['bg', 'cs', 'da', 'de', 'el', 'en', 'en-GB', 'es', 'es-419', 'et', 'fi', 'fil', 'fr', 'fr-CA', 'hr', 'hu', 'id', 'it', 'ja', 'ko', 'lt', 'lv', 'ms', 'mt', 'nb', 'nl', 'pl', 'pt', 'pt-BR', 'ro', 'ru', 'sk', 'sl', 'sv', 'th', 'tr', 'vi', 'zh', 'zh-HK', 'zh-TW'];

    public function __construct(private readonly Settings $settings) {}

    public function forMember(Member $member, Plan $plan, ?string $promo = null): Checkout
    {
        $builder = $member->newSubscription($plan->handle, $plan->priceId);

        if ($plan->trialDays > 0) {
            $builder->trialDays($plan->trialDays);
        }

        return $builder->checkout([...$this->common(), ...$this->discounts($promo)]);
    }

    /** A subscription checkout with no Craft user yet; the webhook makes or finds one. */
    public function forGuest(Plan $plan, ?string $promo = null): string
    {
        $site = Sites::getCurrentSite();
        $session = Cashier::stripe()->checkout->sessions->create([
            ...$this->common(),
            ...$this->discounts($promo),
            'mode' => 'subscription',
            'line_items' => [['price' => $plan->priceId, 'quantity' => 1]],
            'subscription_data' => array_filter([
                'metadata' => ['type' => $plan->handle, 'name' => $plan->handle],
                'trial_period_days' => $plan->trialDays > 0 ? $plan->trialDays : null,
            ]),
            'metadata' => ['velvetrope' => 'guest', 'plan' => $plan->handle, 'site' => $site->handle],
        ]);

        return (string) $session->url;
    }

    /** Pro: a one-time payment for a gift; the webhook issues the code. */
    public function forGift(GiftOption $option, ?string $email = null): string
    {
        $site = Sites::getCurrentSite();
        $session = Cashier::stripe()->checkout->sessions->create(array_filter([
            ...$this->common(),
            'mode' => 'payment',
            'line_items' => [['price' => $option->priceId, 'quantity' => 1]],
            'customer_email' => $email,
            'metadata' => [
                'velvetrope' => 'gift',
                'option' => $option->handle,
                'plan' => $option->plan,
                'months' => (string) $option->months,
                'site' => $site->handle,
            ],
        ], fn ($v) => $v !== null));

        return (string) $session->url;
    }

    /** @return array<string, mixed> */
    private function common(): array
    {
        return [
            'success_url' => $this->settings->url($this->settings->successPath),
            'cancel_url' => $this->settings->url($this->settings->cancelPath),
            'locale' => self::stripeLocale(Sites::getCurrentSite()->language),
        ];
    }

    /**
     * Pro: a promotion code from the link (`?promo=SPRING`) is applied when Stripe
     * knows it; otherwise Checkout shows its own code box if that's switched on.
     *
     * @return array<string, mixed>
     */
    private function discounts(?string $promo): array
    {
        if (! Plugin::isPro()) {
            return [];
        }

        if ($promo !== null && preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $promo)) {
            try {
                $codes = Cashier::stripe()->promotionCodes->all(['code' => $promo, 'active' => true, 'limit' => 1]);
                $id = $codes->data[0]->id ?? null;
            } catch (StripeException $e) {
                // A failed lookup shouldn't stop someone paying; they can still type the code.
                Log::warning("Velvet Rope: looking up promotion code failed: {$e->getMessage()}");
                $id = null;
            }

            if (is_string($id)) {
                return ['discounts' => [['promotion_code' => $id]]];
            }
        }

        return $this->settings->allowPromotionCodes ? ['allow_promotion_codes' => true] : [];
    }

    public static function stripeLocale(?string $language): string
    {
        $language = str_replace('_', '-', (string) $language);

        foreach ([$language, strtok($language, '-')] as $candidate) {
            foreach (self::STRIPE_LOCALES as $locale) {
                if ($candidate !== false && strcasecmp($candidate, $locale) === 0) {
                    return $locale;
                }
            }
        }

        return 'auto';
    }
}
