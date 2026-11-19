# Velvet Rope — plan

Paid memberships for Craft users through Laravel Cashier — plans map to user groups, Stripe handles the money.

- Package: `justinholtweb/craft-velvetrope` · handle `velvetrope` · namespace `JustinHoltWeb\VelvetRope`
- Editions: Lite / Pro · pricing: **TBD** (was provisionally Lite $49 / Pro $99 — see Questions)
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
- **2026-11-17 — phase 2 done.** One template API (`VelvetRope` service) behind three front doors: Twig
  (`velvetRope` global + `{% requireMembership %}` tag), Blade (`$velvetRope` global, `@subscribed`,
  `@requireMembership`) and route middleware (`member:gold`). Globals come from Craft's
  `TemplateGlobalsResolving`, which feeds both engines. Deleting a user (soft or hard) queues
  `CancelStripeSubscriptions` — IDs captured on `ElementDeleting`, dispatched on `ElementDeleted` (after
  commit), so a vetoed delete cancels nothing. 23 Pest + 46 harness checks (incl. a local Stripe mock).
- **2026-11-18 — phase 3, guest checkout, Pro (phase 4 minus Paddle/metered), GraphQL, multisite done.**
  - Phase 3: CP **Members** (CpScreenResponse + core Ui table: search, status filter, totals incl. MRR per
    currency) and a **Membership** screen on user accounts (core `EditUserScreensResolving`, reusing core's
    `EditUserTrait` for the account nav). Permissions *View members* / *Manage memberships*. MRR comes from the
    price on the subscription objects Stripe sends (new columns on `velvetrope_subscriptions`); `sync --refresh`
    backfills from the API.
  - Guest checkout — **design (question 1):** opt-in; Checkout session with no customer; on
    `checkout.session.completed`/`async_payment_succeeded` (signed) create a **pending** user for the Stripe email
    + Craft's activation email, or attach to the existing account with that email; never sign anyone in; an
    account that's already a different Stripe customer → nothing attached, held for review (CP warning).
    Idempotent per session (`velvetrope_guest_checkouts`); the subscription is fetched from Stripe because
    Cashier ignores its webhook when it arrives before the customer is linked.
  - Pro: concurrent plans (Lite: one at a time → billing portal), promotion codes (box + `?promo=`), dunning
    emails (system messages, stage column claimed atomically), gift memberships (`velvetrope_gifts`, code hash
    only; `velvetrope_grants` also holds CP complimentary access), plan gates (section/URI) as `craft.web`
    middleware inserted before `HandleTemplateRequest`; let-through pages get `private, no-store`.
  - GraphQL `velvetRopePlans` (public plan fields only). Multisite: return URLs, Stripe locale, guest affiliation,
    gift email links per site. Rate limiter for checkout/gift links (setting).
  - Checks: 58 Pest; harness `check.sh` ~175 checks incl. a local Stripe mock and Mailpit (see README).
- **Deferred:** Paddle driver (laravel/cashier-paddle is a separate Billable/models/webhooks stack — a
  driver abstraction is a project of its own, not cheap); metered prices (no Craft-side meaning yet).
- **Unverified without a real Stripe account** (mocked locally): real Checkout/portal sessions, real webhook
  payload shapes on Justin's account API version (esp. `discounts` vs `discount`, `current_period_end` on items),
  promotion-code lookup, 3-D Secure/async payments. Test plan: docs/stripe-test-mode.md.

## Phases
1. **Plans → groups (Lite).** Plans in the CP (Stripe price IDs, name, user group, trial days). Stripe Checkout
   for sign-up; webhook listener (Cashier's) moves users in/out of groups; grace period.
2. **Member self-service (Lite).** Twig: `velvetRope.plans`, `velvetRope.subscribed('gold')`, checkout and
   billing-portal URLs; `{% requireMembership 'gold' %}` tag.
3. **CP (Lite).** Members index (status, plan, MRR), member detail on the user edit screen.
4. **Pro.** Multiple concurrent plans, coupons/promo codes, metered prices, Paddle driver, dunning emails,
   gift memberships, Bouncer integration (gate by plan, not just group).

## Risks / open questions
- ~~Cashier's migrations assume `users` + `user_id`~~ — done: own `velvetrope_members` table.
- ~~Cashier's webhook route vs CSRF~~ — it's outside Craft's groups; guarded by RequireWebhookSecret.
- ~~Guest checkout account creation~~ — designed and built (see Status); confirm the design (question 1).
- Gates protect pages, not data (element queries elsewhere, GraphQL). Documented.

## Questions for Justin
1. **Guest checkout design OK?** Pending account + activation email for a new email; attach to the existing
   account when the email matches (adds access, never signs in); hold for review when that account is already
   another Stripe customer. Alternative: never attach to existing accounts (always review). Default is off.
2. **Prices?** Lite and Pro are TBD (the old provisional $49/$99 is not used anywhere public).
3. **Repo name:** GitHub is `craft-velvet-rope`, dir/composer `craft-velvetrope`. Rename one so they match
   (which?), or keep as is? Nothing was renamed.
4. **Stripe test account:** see the .env list and checklist in docs/stripe-test-mode.md; also tell me the
   account's API version (Dashboard → Developers) so we can confirm the webhook payload shape for MRR.
5. **Paddle:** deferred (not cheap). Still wanted for Pro, or drop it from the plan?
6. **Downgrade policy:** after Pro → Lite, existing gifts/complimentary grants keep running to their end date and
   paid-for gift codes can still be redeemed; gates, promos and dunning stop. OK?
7. **Members sidebar** needs Craft's "Access Velvet Rope" plus "View members". Fine, or should "View members"
   alone show it?
8. **Dunning vs Stripe's own emails:** Stripe can also send failed-payment emails. Leave both available
   (documented) or recommend one?


## Definition of done (every phase)
`bin/port-check craft-velvetrope` green · Pest suite green · Pint + Larastan level 6 clean · installs, uninstalls and
reinstalls cleanly in the harness · CP pages smoke-tested with `bin/cp-session`.
