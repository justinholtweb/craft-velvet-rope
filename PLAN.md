# Velvet Rope — plan

Paid memberships for Craft users through Laravel Cashier — plans map to user groups, Stripe handles the money.

- Package: `justinholtweb/craft-velvetrope` · handle `velvetrope` · namespace `JustinHoltWeb\VelvetRope`
- Editions: Lite / Pro · pricing: Lite $49 / Pro $99 (provisional)
- Laravel integration: laravel/cashier — Cashier (Stripe) v16, Laravel 13 compatible. Paddle via laravel/cashier-paddle in Pro.
- Craft 6 only, native Laravel (no `craftcms/yii2-adapter`, ever). Follow
  `~/Sites/plugin-testing-craft6/porting/COOKBOOK.md` for Craft 6 idioms.

## Why it exists
"Pay monthly to read this" shouldn't need Commerce. Cashier already does subscriptions, trials, proration,
invoices and the billing portal. Velvet Rope connects it to Craft users and user groups. Pairs with Bouncer
(Velvet Rope decides who has paid; Bouncer decides what they can see).

## Core idea
Cashier's `Billable` trait goes on an Eloquent model Velvet Rope owns — `Member` (table `velvetrope_members`,
`userId` FK to Craft users, Cashier's `stripe_id`/`pm_*`/`trial_ends_at` columns) — so Craft's users table is
untouched. Cashier's `subscriptions`/`subscription_items` tables are installed by `Install.php` (prefixed).

## Status
- **2026-11-17 — phase 1 (plans → groups) done.** Plans table (handle, name, price, user group, trial
  days); Stripe Checkout + billing portal routes (logged-in, `/velvet-rope/checkout/{plan}`,
  `/velvet-rope/billing`); Cashier on Velvet Rope's own `Member`/`Subscription`/`SubscriptionItem` models and
  prefixed tables; `WebhookHandled` listener tracks `past_due_since` and resyncs groups; hourly
  `craft:velvetrope:sync` ends grace periods. 17 Pest tests + 26 harness checks
  (`tests/Harness/check.sh`, locally-signed webhooks — no Stripe account needed).
- **Security decisions:** webhooks are refused (503) until a signing secret is set — Cashier alone accepts
  unsigned webhooks when it has no secret, which would let anyone grant themselves a paid group. A
  malformed `$ENV` reference is treated as unset, never as a literal (public) secret. Return paths can only
  be paths on this site. Stripe API errors become 502s, not stack traces.
- **Requires Craft Pro** (user groups): `minCmsEdition = Pro`.
- **Found:** Craft's `users.id` is a signed `int`, so `foreignId('userId')` fails; use `integer()` + `foreign()`.
- Not yet verified against real Stripe test mode (needs your test keys): Checkout session creation, the
  success redirect, the billing portal, and real webhook payloads incl. `cancel_at_period_end` (Cashier
  calls the API for that one).
- Open: when a Craft user is deleted their member row cascades away, but the Stripe subscription keeps
  billing — cancel it on user delete. Guest checkout. Twig helpers (phase 2).

## Phases
1. **Plans → groups (Lite).** Plans in the CP (Stripe price IDs, name, user group, trial days). Stripe Checkout
   for sign-up; webhook listener (Cashier's) moves users in/out of groups; grace period.
2. **Member self-service (Lite).** Twig: `velvetRope.plans`, `velvetRope.subscribed('gold')`, checkout and
   billing-portal URLs; `{% requireMembership 'gold' %}` tag.
3. **CP (Lite).** Members index (status, plan, MRR), member detail on the user edit screen.
4. **Pro.** Multiple concurrent plans, coupons/promo codes, metered prices, Paddle driver, dunning emails,
   gift memberships, Bouncer integration (gate by plan, not just group).

## Risks / open questions
- Cashier's migrations assume `users` + `user_id`; publish and adapt to `velvetrope_members`.
- Cashier registers its own webhook route — ensure it's excluded from Craft's CSRF and doesn't collide.
- Guest checkout → account creation flow (who creates the Craft user, when).

## Definition of done (every phase)
`bin/port-check craft-velvetrope` green · Pest suite green · Pint + Larastan level 6 clean · installs, uninstalls and
reinstalls cleanly in the harness · CP pages smoke-tested with `bin/cp-session`.
