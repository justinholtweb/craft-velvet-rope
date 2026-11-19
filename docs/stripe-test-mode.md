---
title: Testing with Stripe test mode
slug: stripe-test-mode
order: 85
summary: The .env keys, the Stripe objects to create, and a checklist that exercises every flow before you take real money.
---

Do all of this with **test-mode** keys (`pk_test_…`, `sk_test_…`) before switching to live keys.

## .env

```dotenv
STRIPE_KEY=pk_test_…              # Developers → API keys → Publishable key
STRIPE_SECRET=sk_test_…           # Developers → API keys → Secret key
STRIPE_WEBHOOK_SECRET=whsec_…     # the endpoint's signing secret (or the one `stripe listen` prints)
```

## In Stripe (test mode)

1. A product **Gold** with a monthly recurring price → copy `price_…`.
2. Optionally a second product **Silver** (monthly, so you can test Pro's several plans), and a one-time
   price for a gift (Pro).
3. Optionally a coupon (e.g. 25% forever) with a promotion code `SPRING` (Pro).
4. Settings → Billing → Customer portal: turn it on; allow cancelling and updating payment methods.
5. Developers → Webhooks: an endpoint at `https://your-site/stripe/webhook` with the events listed in
   [Webhooks](/plugins/craft-velvetrope/docs/webhooks/). Locally, `stripe listen --forward-to
   https://your-site.ddev.site/stripe/webhook` instead.

## In Craft

Settings → Velvet Rope: a plan row (`gold`, its price ID, a user group, 0 trial days); Success path, Cancel
path, Join path; Pro: gift option, promotion codes, dunning on.

## Checklist

Test cards: `4242 4242 4242 4242` (succeeds), `4000 0000 0000 0341` (attaches, then fails on renewal),
`4000 0025 0000 3155` (needs 3-D Secure).

1. **Sign up (logged in):** checkout → pay with 4242 → back on the success page; the user is in the Gold
   group; Members shows them with MRR.
2. **Billing portal:** `/actions/velvetrope/billing` opens Stripe's portal; cancel at period end → still in Gold,
   Membership tab shows the end date; resume.
3. **Failed renewal + grace:** in the billing portal, replace the card with 0341; then in the dashboard
   open the subscription → *Update subscription* → reset the billing cycle to now. The immediate renewal
   fails → `past_due`, still in Gold, (Pro) "Payment failed" email. To skip the wait, set the grace period
   to 0 days (or 1) and run `craft:velvetrope:sync` → out of Gold, (Pro) "ended" email. (Stripe test
   clocks also work, for a customer created on a clock.)
4. **Cancel now:** cancel in the dashboard → `customer.subscription.deleted` → out of Gold.
5. **Trial:** set 7 trial days → checkout shows the trial; Members counts them under "On trial", not MRR.
6. **Guest checkout:** switch it on, log out, checkout with a new email → a pending user and an activation
   email; with an existing member's email → attached to them.
7. **Delete a user** with a live subscription → it's cancelled in Stripe.
8. **Pro:** checkout `?promo=SPRING` shows the discount; a second plan works; buy a gift (logged out) →
   code email → redeem while logged in → Gold for N months.
9. **Webhook safety:** remove `STRIPE_WEBHOOK_SECRET` → Stripe's deliveries fail with 503 (put it back and
   resend them from the dashboard).
10. `php artisan craft:velvetrope:sync --refresh` → "Refreshed N subscriptions", MRR unchanged.
