# Velvet Rope

Paid memberships for Craft users through Laravel Cashier — plans map to user groups, Stripe handles the money.

> **Status:** scaffold — nothing user-facing yet. See [PLAN.md](PLAN.md).

## Requirements

- Craft CMS 6.0.0-beta.1 or later (native Craft 6 plugin, no Yii 2 adapter)
- PHP 8.5
- Laravel integration: laravel/cashier. Cashier (Stripe) v16, Laravel 13 compatible. Paddle via laravel/cashier-paddle in Pro.

## Editions and pricing

Lite / Pro — Lite $49 / Pro $99 (provisional).

## Development

```bash
composer install
composer test        # Pest
composer phpstan     # Larastan level 6
composer check-cs    # Pint
```

Tested in the Craft 6 harness at `~/Sites/plugin-testing-craft6` (`bin/plugin-wire craft-velvetrope --install`).
