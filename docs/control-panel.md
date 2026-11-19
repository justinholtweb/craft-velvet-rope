---
title: Members and MRR
slug: control-panel
order: 50
summary: The Members screen, how MRR is counted, and the Membership tab on each user.
---

## Members

**Members** in the sidebar (needs *Access Velvet Rope* and *View members*; label and icon are
settings) lists everyone with a subscription or a gift/complimentary grant, with their plans and status,
their MRR and when they joined. Search by name, username or email; filter by status (active, trialing,
past due, cancelled, gift, complimentary).

Above the list:

- **Members** — people with access right now (any plan, including gifts).
- **MRR** — monthly recurring revenue, per currency.
- **On trial**, **Past due (in grace)**, **Gift or complimentary**.

### How MRR is counted

MRR follows Stripe's definition as far as Velvet Rope's own data allows: subscriptions that are paying and
still grant access (past-due ones inside their grace period included), normalised to a month (a year is
twelve months, a week 52/12 of one), times quantity, after *recurring* discounts. Trials, gifts and
complimentary access aren't revenue; one-time coupons are ignored.

The price comes from the subscription objects Stripe sends with each webhook, so it is exactly what Stripe
bills. A subscription Velvet Rope hasn't seen a price for yet (for example one created before you
installed the plugin) is left out and called out on the screen: run
`php artisan craft:velvetrope:sync --refresh` to read every live subscription from Stripe. Metered and
mixed-interval subscriptions have no single monthly figure and are left out. Stripe's own dashboard
remains the source of truth for revenue reporting.

## Membership tab

Every user's account gets a **Membership** tab (for people with *View members*): each plan with its
Stripe status, price, MRR, renewal/end date and Stripe subscription ID, the Stripe customer ID, and any
guest checkout that was held for review.

With *Manage memberships*:

- **Resync user groups** re-applies the groups from their subscriptions and grants.
- **Pro: Complimentary access** — give a plan for a number of months (with a note), and revoke it. Revoking
  ends the grant now rather than deleting it, so the history stays.
