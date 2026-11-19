---
title: Console commands
slug: console-commands
order: 80
summary: The hourly sync, and refreshing subscriptions from Stripe.
---

| Command | What it does |
| --- | --- |
| `craft:velvetrope:sync` | Re-applies every member's groups from their subscriptions and grants, and moves Pro dunning along. Scheduled hourly (run Laravel's scheduler), so grace periods, gifts and complimentary access end on time. |
| `craft:velvetrope:sync --refresh` | First re-reads every live subscription from the Stripe API — status, dates and price — then syncs. Use it after go-live, after an outage that lost webhooks, or to fill in prices for MRR. A subscription Stripe no longer has is marked cancelled. |
