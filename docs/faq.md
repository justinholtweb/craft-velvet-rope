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
