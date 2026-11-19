# Velvet Rope

Paid memberships for Craft users through Laravel Cashier — plans map to user groups, Stripe handles the money.

> [!WARNING]
> **Craft 6 only, and in beta.** Velvet Rope is a native **Craft CMS 6** plugin. Craft 6 is still in beta,
> and so is Velvet Rope (`6.0.0-beta.1`). It takes payments, so **use caution on production**: run every
> flow in Stripe test mode first, keep backups, and expect changes between betas. It does not run on Craft 5.

Docs: [justinholt.com/plugins/craft-velvetrope](https://justinholt.com/plugins/craft-velvetrope/docs/) (and [docs/](docs/)). See [PLAN.md](PLAN.md).

## Requirements

- Craft CMS 6.0.0-beta.1 or later, **Pro edition** (memberships are user groups)
- PHP 8.5
- A Stripe account. Laravel Cashier (Stripe) v16 is installed with the plugin. No Craft Commerce needed.

## Setup

1. `.env`: `STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET` (the settings point at them by default).
2. Stripe → Webhooks: an endpoint at `https://your-site/stripe/webhook` sending `customer.subscription.*`,
   `checkout.session.completed` and `checkout.session.async_payment_succeeded`. **Webhooks are refused until
   the signing secret is set** — Cashier alone would accept unsigned ones.
3. Settings → Velvet Rope → Plans: handle, name, Stripe price ID, user group, trial days.
4. Link to `{{ velvetRope.checkoutUrl('gold') }}`; gate pages with `{% requireMembership 'gold' %}`.
5. Run Laravel's scheduler: `craft:velvetrope:sync` runs hourly so grace periods and gifts end on time.

Members are in a plan's group while Stripe says they're paid up (active, trialing, cancelled-but-paid-to-period-end,
or past due within the grace period) and out of it otherwise. Groups Velvet Rope doesn't manage are never touched.
Deleting a Craft user cancels their Stripe subscriptions.

## Templates: Twig and Blade

One API, the same rules in every engine (both call `JustinHoltWeb\VelvetRope\VelvetRope`).

| | Twig | Blade |
|---|---|---|
| Is a member (any plan) | `{% if velvetRope.subscribed() %}` | `@subscribed … @endsubscribed` |
| Has a plan | `{% if velvetRope.subscribed('gold') %}` | `@subscribed('gold') … @else … @endsubscribed` |
| Plan status | `velvetRope.subscription('gold').status` | `$velvetRope->subscription('gold')['status']` |
| Gate a page | `{% requireMembership 'gold' %}` | `@requireMembership('gold')` |
| Links | `velvetRope.checkoutUrl('gold')`, `velvetRope.billingUrl()` | `$velvetRope->checkoutUrl('gold')`, `$velvetRope->billingUrl()` |
| Pro: gifts | `velvetRope.giftOptions()`, `velvetRope.giftUrl('gold-year')`, `velvetRope.redeemUrl()` | `$velvetRope->giftOptions()`, … |

Gates fail closed (an unset variable or unknown plan lets nobody in). App routes: `->middleware(['craft', 'craft.web', 'member:gold'])`.
Full reference: [docs/templates.md](docs/templates.md).

## Guest checkout (opt-in)

People who aren't logged in can check out; when Stripe confirms payment, Velvet Rope creates a **pending**
account for the email they paid with and sends Craft's activation email — nobody can sign in until that
inbox sets a password — or adds the membership to the existing account with that email. Paying never signs
anyone in. If that account is already a different Stripe customer, nothing is attached and the checkout is
held for review. See [docs/checkout.md](docs/checkout.md).

## Control panel

- **Members** (sidebar): everyone with a subscription or grant, plans and status, MRR per member and in total
  (paying subscriptions normalised to a month, after recurring discounts; trials and gifts excluded).
- **Membership** tab on every user: subscriptions with Stripe IDs, price, MRR and dates; resync; Pro: complimentary access.
- Permissions: *View members*, *Manage memberships*.

## Pro

Several plans at once · promotion codes (Checkout's box, or `?promo=CODE` links) · dunning emails (failed payment,
grace ending, access ended — editable per language in System Messages) · gift memberships (one-time payment →
emailed code → redeem; only a hash is stored) · complimentary access · plan gates on sections and URI patterns
(gated pages are sent `private, no-store`). Details: [docs/pro.md](docs/pro.md).

## GraphQL

`velvetRopePlans` (schema permission *View membership plans*): handle, translated name, trial days, checkout
link, Pro gift options. No price IDs, no members, and no "current user is subscribed" — a token identifies an
app, not the visitor. See [docs/graphql.md](docs/graphql.md).

## Multi-site

Checkout returns to the site the visitor started on, in its language; guest accounts are affiliated with that
site; gift emails link that site's redeem page; plan names translate with the `site` category. [docs/multisite.md](docs/multisite.md).

## Editions and pricing

Lite / Pro — prices **TBD**.

## Development

```bash
composer install
composer test        # Pest
composer phpstan     # Larastan level 6
composer check-cs    # Pint
```

Live checks against the Craft 6 harness (`~/Sites/plugin-testing-craft6`, `bin/plugin-wire craft-velvetrope --install`):
`tests/Harness/check.sh`. It needs no Stripe account: webhooks are signed locally with the harness's secret, and
API calls go to a local Stripe mock (`tests/Harness/stripe-mock-router.php`) through the loopback-only
`VELVETROPE_STRIPE_API_BASE=http://127.0.0.1:12111` in the harness `.env` (ignored in production). It also needs
the app route `Route::middleware(['craft', 'craft.web', 'member:gold'])->get('vr/route-gated', fn () => 'ROUTE-SECRET');`
in the harness `routes/web.php`. The multisite section runs when the `velvetropefr` site exists:
`bin/harness-lock ddev artisan tinker --execute "require '/var/www/sites/craft-velvetrope/tests/Harness/multisite-fixtures.php';"`
(remove it afterwards with `multisite-cleanup.php`, also under the lock).
