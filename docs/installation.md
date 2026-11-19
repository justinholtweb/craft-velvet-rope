---
title: Installation
slug: installation
order: 10
summary: Requirements, the Craft 6 beta caution, editions, install, and your first plan.
---

> **Craft 6 only, and still in beta.** Velvet Rope is a native **Craft CMS 6** plugin. Craft 6 is
> still in beta, and so is Velvet Rope (`6.0.0-beta.1`). Try it on development and staging sites
> first, and **use caution before running it on a production site** — it takes people's money, so
> test the whole sign-up, renewal and cancellation flow in Stripe test mode first, keep backups, and
> expect things to change between betas. It does not run on Craft 5.

## Requirements

- Craft CMS **6.0.0-beta.1** or later, **Pro edition** (memberships are user groups, which need Craft Pro)
- PHP 8.5
- A Stripe account. Laravel Cashier 16 (Stripe) is installed with the plugin.

## Editions

| | Lite | Pro |
| --- | --- | --- |
| Price | TBD | TBD |
| Plans → user groups, Stripe Checkout, billing portal | ✓ | ✓ |
| Trials, grace period for failed payments | ✓ | ✓ |
| Twig + Blade API, `{% requireMembership %}`, route middleware | ✓ | ✓ |
| Guest checkout (account made from the Checkout email) | ✓ | ✓ |
| Members screen with MRR, Membership tab on users | ✓ | ✓ |
| GraphQL `velvetRopePlans` | ✓ | ✓ |
| Several plans at once (Lite: one at a time) | | ✓ |
| Promotion codes | | ✓ |
| Dunning emails | | ✓ |
| Gift memberships | | ✓ |
| Complimentary access from the CP | | ✓ |
| Plan gates on sections and URIs | | ✓ |

## Install

```sh
composer require justinholtweb/craft-velvetrope
php artisan craft:plugin:install velvetrope
```

## Connect Stripe

Add your keys to `.env` and leave the settings pointing at them (the defaults already do):

```dotenv
STRIPE_KEY=pk_test_…
STRIPE_SECRET=sk_test_…
STRIPE_WEBHOOK_SECRET=whsec_…
```

Then in Stripe, add a webhook endpoint at `https://your-site.example/stripe/webhook` — see
[Webhooks](/plugins/craft-velvetrope/docs/webhooks/) for the events to send. **Webhooks are refused
until the signing secret is set**, so nobody can fake a payment.

## Your first plan

1. In Stripe, create a product with a recurring price. Copy the price ID (`price_…`).
2. In Craft, create a user group for members (Settings → Users → User Groups), e.g. *Gold Members*.
3. Settings → Plugins → Velvet Rope → **Plans**: add a row — handle `gold`, name, the price ID, the
   group, and trial days if you want a trial.
4. Link to `/actions/velvetrope/checkout/gold` (or `{{ velvetRope.checkoutUrl('gold') }}`), and gate a page with
   `{% requireMembership 'gold' %}`.

Schedule Laravel's scheduler (`php artisan schedule:run` every minute) so `craft:velvetrope:sync` runs
hourly: grace periods and gifts end on time even when Stripe sends nothing.
