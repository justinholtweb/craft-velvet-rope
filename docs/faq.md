---
title: FAQ
slug: faq
order: 90
summary: Craft 6 and the beta, Craft Pro, Commerce, Paddle, editions and pricing.
---

## Does Velvet Rope work on Craft 5?

No. It's a native **Craft 6** plugin built on Laravel Cashier. Craft 6 is still in beta.

## Is it safe to use in production?

Craft 6 and Velvet Rope are both in beta. Velvet Rope handles payments, so **use caution on
production**: test every flow in Stripe test mode first (see [Testing with Stripe test
mode](/plugins/craft-velvetrope/docs/stripe-test-mode/)), keep backups, and expect changes between betas.

## Why does it need Craft Pro?

Memberships are user groups, and user groups are a Craft Pro feature.

## Do I need Craft Commerce?

No. Stripe Checkout takes the money and Stripe Billing renews it; Velvet Rope never sees card details.

## Does it touch Craft's users table?

No. Cashier's columns live on Velvet Rope's own `velvetrope_members` table, one row per user.

## Paddle?

Not yet. Laravel's Paddle Cashier is a separate package with its own models and webhooks; it's on the list
for a later Pro release.

## Can members switch plans?

Yes, in Stripe's billing portal (allow it in the portal settings). The webhook moves their groups.

## What happens to someone whose card fails?

They keep access for the grace period (3 days by default), then lose it. Pro emails them along the way.

## Headcount or Velvet Rope?

Both sell memberships through Stripe and keep paying members in Craft user groups. They're built for
different sites.

**[Headcount](https://craft-headcount.com)** is a membership suite for **Craft 5**. Choose it if you need any of:

- PayPal as well as Stripe
- fixed-season plans (everyone renews on the same date), with pro-rata joins
- drip content that unlocks a number of days after someone joins
- Apple and Google Wallet membership cards
- MRR, churn and trial-conversion reports, and a REST API
- gating rules for any element type, set in the control panel

**Velvet Rope** is the leaner **Craft 6** plugin, built on Laravel Cashier. Choose it if:

- you're on Craft 6, where Headcount doesn't run
- Stripe is your only payment provider
- you want Blade as well as Twig, or Laravel route middleware (`member:gold`) on your own routes
- you want guest checkout, gift memberships, promotion codes or dunning emails (the last three are Pro)

Velvet Rope doesn't run on Craft 5, and it has no PayPal, season plans, drip content or wallet cards.
There's no tool that moves Headcount's members into Velvet Rope.

## Velvet Rope or Subscribr?

[Subscribr](/plugins/craft-subscribr) sells recurring **Commerce** products: subscription boxes,
skip, pause and swap, prepaid and gift plans, on any gateway that can store a card. It needs Craft
Commerce. Velvet Rope sells **access**, not products, and doesn't need Commerce.
