---
title: Troubleshooting
slug: troubleshooting
order: 95
summary: Webhooks refused, members not in their group, MRR blank, checkout errors.
---

**Stripe shows webhook deliveries failing with 503.** The signing secret isn't set (or names an
environment variable that's empty). Set `STRIPE_WEBHOOK_SECRET` to the endpoint's `whsec_…`.

**…with 403.** The signature doesn't match: the secret belongs to another endpoint (or to an old
`stripe listen` session), or the server clock is off by more than five minutes.

**A member paid but isn't in the group.** Check the plan row's price ID matches the price they bought,
and that the plan has a user group. Then open their Membership tab and *Resync user groups*, or run
`craft:velvetrope:sync --refresh`.

**MRR is blank or low.** Subscriptions created before Velvet Rope saw them have no price yet; run
`craft:velvetrope:sync --refresh`. Trials and gifts are never in MRR.

**Checkout shows "Payment provider error" (502).** Stripe rejected the request — usually a wrong price ID
or a live/test key mix-up. The reason is in the log.

**"Too Many Requests" on checkout.** Raise *Checkout rate limit* (visitors behind one office proxy share
an IP), or set it to 0.

**A guest paid but has no access.** Their email matched an account that's already a different Stripe
customer; see the warning on Members and the Stripe IDs on that user's Membership tab.

**Sidebar item missing for an editor.** They need *Access Velvet Rope* and *View members*.
