---
title: Configuration
slug: configuration
order: 20
summary: Stripe keys, plans, grace period, return paths, guest checkout, rate limit and the sidebar.
---

Everything lives in **Settings → Plugins → Velvet Rope**. Each setting can also be set in
`config/craft/velvetrope.php`, which wins over the CP.

| Setting | Default | What it does |
| --- | --- | --- |
| Stripe publishable key | `$STRIPE_KEY` | Use an environment variable. |
| Stripe secret key | `$STRIPE_SECRET` | Use an environment variable. |
| Webhook signing secret | `$STRIPE_WEBHOOK_SECRET` | Webhooks are refused (503) until it's set. A reference to an unset variable counts as unset, never as the literal text. |
| Currency | `usd` | Cashier's default currency. Prices themselves live in Stripe. |
| Plans | — | Rows of handle, name, Stripe price ID, user group, trial days. |
| Grace period (days) | 3 | How long a member whose payment failed keeps access (Stripe status `past_due`). 0 = none. |
| Join path | — | Where non-members are sent by a gate. Blank: the plan's checkout when one plan is named, otherwise a 403. |
| Success path / Cancel path | — | Site paths Stripe Checkout returns to. Always a path on the visitor's own site, never another host. |
| Checkout rate limit | 20 | Checkout and gift links one visitor may open per minute (each creates a Stripe session). 0 = no limit. |
| Guest checkout | off | See [Plans and checkout](/plugins/craft-velvetrope/docs/checkout/). |
| Show Members in the sidebar / label / icon | on | Only people with “View members” see it. |

## Plans

A plan is a Stripe **price** plus the Craft **user group** its members are put in. The handle is what
templates, gates and checkout links use (`gold`); it's also stored on each Stripe subscription as its
type, so don't rename a plan's handle once people have subscribed.

Velvet Rope only ever adds people to, and removes them from, the groups your plans name. Other groups a
user is in are left alone.

## Permissions

- **View members** — the Members screen and the Membership tab on user accounts.
- **Manage memberships** (nested) — resync a member's groups, and in Pro grant or revoke complimentary access.

Admins have both.
