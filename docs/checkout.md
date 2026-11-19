---
title: Plans and checkout
slug: checkout
order: 30
summary: Stripe Checkout for members and guests, the billing portal, trials, grace periods, and how guest accounts are made safely.
---

## Checkout links

`/actions/velvetrope/checkout/{plan}` sends the visitor to Stripe Checkout for that plan, on the site they're
on: Stripe returns them to your *Success path* (or *Cancel path*) on the same site, and Checkout is shown
in that site's language when Stripe supports it.

```twig
<a href="{{ velvetRope.checkoutUrl('gold') }}">Join Gold</a>
```

- A member who already has the plan goes straight to the success page.
- **Lite** is one plan at a time: a member of another plan is sent to the billing portal to switch.
  **Pro** lets people hold several plans at once.
- Trials come from the plan's *Trial days*.
- Each link creates a Stripe session, so they're rate-limited (*Checkout rate limit*, 20 a minute per visitor by default).
- If Stripe can't be reached or rejects the request, the visitor gets a 502 page and the error is logged — never a stack trace.

## Billing portal

`/actions/velvetrope/billing` (`velvetRope.billingUrl()`) opens Stripe's customer portal for the logged-in
member: change card, switch plan, cancel, download invoices. Configure what members may do there in the
Stripe dashboard (Settings → Billing → Customer portal).

## When access starts and stops

Stripe's webhooks drive everything (see [Webhooks](/plugins/craft-velvetrope/docs/webhooks/)):

| Stripe status | Access |
| --- | --- |
| `active`, `trialing` | Yes |
| Cancelled, but paid up to the end of the period | Yes, until then |
| `past_due` (a renewal failed) | Yes for the *grace period*, counted from when Stripe first said so; then no |
| `unpaid`, `canceled`, `incomplete`, `incomplete_expired`, `paused` | No |

Members are put in the plan's user group while they have access and taken out when they don't. The hourly
`craft:velvetrope:sync` ends grace periods (and Pro gifts and complimentary access) on time, even when
Stripe sends nothing.

Deleting a Craft user cancels their Stripe subscriptions immediately (queued and retried), so nobody keeps
paying for an account that's gone. A delete that's vetoed cancels nothing.

## Guest checkout

Off by default. With **Guest checkout** on, people who aren't logged in can use a checkout link. Stripe
collects their email, and when the payment completes Velvet Rope makes or finds their account:

- **No account has that email** → a new **pending** account is created and Craft's activation email is
  sent to it. Nobody can sign in until the owner of that inbox sets a password, so someone who types
  another person's email at Checkout gets nothing for their money but a receipt.
- **An account has that email** → the membership is added to that account. Paying never signs anyone in,
  so this can only ever *give* access, never take over an account.
- **That account is already a different Stripe customer** → nothing is attached. The checkout is held for
  review: the Members screen shows a warning and the member's Membership tab has the Stripe IDs, so you
  can refund or cancel in Stripe and ask them to check out while logged in.

Checkouts paid by a delayed method (bank debits) are handled when Stripe confirms the payment
(`checkout.session.async_payment_succeeded`). Every step is safe to repeat: Stripe retries webhooks, and
the same Checkout session never creates two accounts.

Tell guests on your success page to check their email, e.g.
`{% if not currentUser %}Check your inbox to activate your account.{% endif %}`
