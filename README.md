# Velvet Rope

Paid memberships for Craft users through Laravel Cashier — plans map to user groups, Stripe handles the money.

> **Status:** phases 1–2 working in the Craft 6 harness (plans → user groups, Twig + Blade API, cancel-on-delete). Requires Craft Pro. See [PLAN.md](PLAN.md).

## Requirements

- Craft CMS 6.0.0-beta.1 or later (native Craft 6 plugin, no Yii 2 adapter)
- PHP 8.5
- Laravel integration: laravel/cashier. Cashier (Stripe) v16, Laravel 13 compatible. Paddle via laravel/cashier-paddle in Pro.

## Templates: Twig and Blade

One API, the same rules in every engine (both call `JustinHoltWeb\VelvetRope\VelvetRope`).

| | Twig | Blade |
|---|---|---|
| Is a member (any plan) | `{% if velvetRope.subscribed() %}` | `@subscribed … @endsubscribed` |
| Has a plan | `{% if velvetRope.subscribed('gold') %}` | `@subscribed('gold') … @else … @endsubscribed` |
| Plan status | `velvetRope.subscription('gold').status` | `$velvetRope->subscription('gold')['status']` |
| Gate a page | `{% requireMembership 'gold' %}` | `@requireMembership('gold')` |
| Links | `velvetRope.checkoutUrl('gold')`, `velvetRope.billingUrl()` | `$velvetRope->checkoutUrl('gold')`, `$velvetRope->billingUrl()` |

`subscription()` returns `status`, `active`, `onTrial`, `pastDue`, `cancelled`, `endsAt`, `trialEndsAt`.
Gating sends guests to the login page and non-members to *Join path* (or the plan's checkout when one
plan is named, else a 403). `{% requireMembership ['gold', 'silver'] redirect 'plans' %}` /
`@requireMembership(['gold', 'silver'], 'plans')` override that.

App routes: `Route::get(...)->middleware(['craft', 'craft.web', 'member:gold'])`.

Deleting a Craft user cancels their Stripe subscriptions (queued, retried).

## Editions and pricing

Lite / Pro — Lite $49 / Pro $99 (provisional).

## Development

```bash
composer install
composer test        # Pest
composer phpstan     # Larastan level 6
composer check-cs    # Pint
```

Tested in the Craft 6 harness at `~/Sites/plugin-testing-craft6` (`bin/plugin-wire craft-velvetrope --install`).
