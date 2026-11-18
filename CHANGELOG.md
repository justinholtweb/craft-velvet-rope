# Release Notes for Velvet Rope

## 6.0.0-beta.1 - Unreleased

### Added
- Plans that map Stripe prices to Craft user groups, with trials and a grace period for failed payments.
- Stripe Checkout (`/velvet-rope/checkout/{plan}`) and billing portal (`/velvet-rope/billing`) for logged-in users.
- Webhook-driven group sync on Laravel Cashier, plus an hourly `craft:velvetrope:sync`.
- Webhooks are refused until a signing secret is configured.
- Template API for Twig and Blade: `velvetRope` / `$velvetRope`, `{% requireMembership %}` / `@requireMembership`, `@subscribed`.
- `member:{plans}` route middleware.
- Deleting a user cancels their Stripe subscriptions.
