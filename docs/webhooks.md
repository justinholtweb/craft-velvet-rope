---
title: Webhooks
slug: webhooks
order: 35
summary: The Stripe endpoint, the events to send, signature checks, and what Velvet Rope does with each.
---

Point a Stripe webhook endpoint at:

```
https://your-site.example/stripe/webhook
```

and send these events:

| Event | Why |
| --- | --- |
| `customer.subscription.created` | A member joined: record it, add them to the plan's group. |
| `customer.subscription.updated` | Renewals, failed payments (`past_due`), plan switches, scheduled cancellations, trial changes. |
| `customer.subscription.deleted` | The subscription ended: remove the group. |
| `checkout.session.completed` | Guest checkouts (make or find the account) and Pro gift purchases. |
| `checkout.session.async_payment_succeeded` | The same, for payment methods that confirm later. |
| `customer.updated`, `customer.deleted`, `invoice.payment_action_required` | Handled by Laravel Cashier itself. |

Copy the endpoint's **signing secret** (`whsec_…`) into `STRIPE_WEBHOOK_SECRET`.

## Security

- **No secret, no webhooks.** Laravel Cashier on its own accepts *unsigned* webhooks when no signing secret
  is configured, which would let anyone post a fake "subscription created" and put themselves in a paid
  group. Velvet Rope refuses every webhook with a 503 until the secret is set. A setting that names an
  environment variable that isn't set counts as no secret.
- Signatures are checked by Cashier (Stripe's `t=…,v1=…` HMAC with a five-minute tolerance), so replayed
  or altered events get a 403.
- Every handler is idempotent: Stripe retries, and the same event twice changes nothing more.
- A handler that fails returns a 500, so Stripe retries it later.

## Local development

Use the Stripe CLI to forward test-mode events to your local site:

```sh
stripe listen --forward-to https://your-site.ddev.site/stripe/webhook
```

It prints a `whsec_…` for that session; put it in `STRIPE_WEBHOOK_SECRET` while it runs.
