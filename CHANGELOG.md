# Release Notes for Velvet Rope

## 6.0.0-beta.2 - 2026-11-21

### Changed
- The docs FAQ now compares Velvet Rope with Headcount (Craft 5) and Subscribr.

## 6.0.0-beta.1 - 2026-11-19

> Craft 6 only. Craft 6 is in beta, and so is Velvet Rope: use caution on production.

### Added
- Plans that map Stripe prices to Craft user groups, with trials and a grace period for failed payments.
- Stripe Checkout (`/actions/velvetrope/checkout/{plan}`) and the Stripe billing portal (`/actions/velvetrope/billing`).
- Webhook-driven group sync on Laravel Cashier, plus an hourly `craft:velvetrope:sync`; `--refresh` re-reads live subscriptions from Stripe.
- Webhooks are refused until a signing secret is configured (Cashier alone accepts unsigned webhooks).
- Template API for Twig and Blade: `velvetRope` / `$velvetRope`, `{% requireMembership %}` / `@requireMembership`, `@subscribed`; gates fail closed.
- `member:{plans}` route middleware.
- Deleting a user cancels their Stripe subscriptions.
- Guest checkout (off by default): a pending account is created from the Checkout email and sent Craft's activation email, or the membership is added to the existing account with that email; never signs anyone in; conflicting Stripe customers are held for review. Handles delayed payment methods; idempotent per Checkout session.
- Members screen in the CP: members, plans and status, MRR per member and in total, search and status filters; a review warning for held guest checkouts.
- A Membership tab on user accounts: subscriptions with Stripe IDs, price, MRR and dates, and a resync action.
- Permissions: View members, Manage memberships. Sidebar label and icon settings.
- MRR from the prices Stripe sends with each subscription (normalised to a month, recurring discounts applied; trials, gifts and metered prices excluded).
- Checkout and gift links are rate-limited per visitor (setting).
- GraphQL: `velvetRopePlans` (handle, translated name, trial days, checkout URL, Pro gift options) behind a schema permission. Nothing personal and no price IDs.
- Multi-site: checkout returns to the visitor's site in its language; guest accounts are affiliated with the site they started on; gift emails link that site's redeem page; plan names translate with the `site` category.
- Pro: several plans at once (Lite is one plan at a time).
- Pro: promotion codes — Stripe Checkout's code box, and `?promo=CODE` links.
- Pro: dunning emails (payment failed, grace ending, access ended), once per lapse, editable per language in System Messages.
- Pro: gift memberships — one-time Stripe payments issue a code (stored only as a hash) emailed to the buyer; redeeming grants the plan for N months.
- Pro: complimentary access granted and revoked from a user's Membership tab.
- Pro: plan gates on sections and URI patterns (case-insensitive, checked against both the requested path and the matched entry's URI); every matching gate applies, and pages let through are sent `Cache-Control: private, no-store`.
