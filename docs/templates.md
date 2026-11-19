---
title: Templates (Twig and Blade)
slug: templates
order: 40
summary: The velvetRope API, gating pages, and route middleware — the same rules in every engine.
---

Craft 6 picks Twig or Blade by file extension. Velvet Rope gives both the same API (they call the same
PHP service, so they can't drift apart): `velvetRope` in Twig, `$velvetRope` in Blade.

| | Twig | Blade |
| --- | --- | --- |
| A member of any plan? | `velvetRope.subscribed()` | `@subscribed … @endsubscribed` |
| A member of a plan? | `velvetRope.subscribed('gold')` | `@subscribed('gold') … @else … @endsubscribed` |
| Plans (names translated per site) | `velvetRope.plans()` | `$velvetRope->plans()` |
| One plan | `velvetRope.plan('gold')` | `$velvetRope->plan('gold')` |
| Subscription status | `velvetRope.subscription('gold')` | `$velvetRope->subscription('gold')` |
| Gift / complimentary access (Pro) | `velvetRope.grant('gold')` | `$velvetRope->grant('gold')` |
| Checkout link | `velvetRope.checkoutUrl('gold')` | `$velvetRope->checkoutUrl('gold')` |
| …with a promotion code (Pro) | `velvetRope.checkoutUrl('gold', 'SPRING')` | `$velvetRope->checkoutUrl('gold', 'SPRING')` |
| Billing portal | `velvetRope.billingUrl()` | `$velvetRope->billingUrl()` |
| Gift options (Pro) | `velvetRope.giftOptions()` | `$velvetRope->giftOptions()` |
| Gift link (Pro) | `velvetRope.giftUrl('gold-year')` | `$velvetRope->giftUrl('gold-year')` |
| Redeem form action | `velvetRope.redeemUrl()` | `$velvetRope->redeemUrl()` |
| Last redeem result | `velvetRope.notice()` | `$velvetRope->notice()` |
| Gate the page | `{% requireMembership 'gold' %}` | `@requireMembership('gold')` |

`subscription()` returns `status`, `active`, `onTrial`, `pastDue`, `cancelled`, `endsAt`, `trialEndsAt`
(or null if they never had the plan). `grant()` returns `source` (`gift` or `comp`), `startsAt`, `endsAt`.

## Gating a page

```twig
{% requireMembership 'gold' %}
{% requireMembership ['gold', 'silver'] redirect 'plans' %}
{% requireMembership %}  {# any plan #}
```

```blade
@requireMembership('gold')
@requireMembership(['gold', 'silver'], 'plans')
```

Guests are sent to the login page. Logged-in non-members go to `redirect`, else the *Join path*, else
the plan's checkout when exactly one plan is named, else a 403.

A gate **fails closed**: given anything but plan handles (an unset variable, an ID), it throws instead of
quietly letting every member in, and a plan handle that doesn't exist lets nobody in (and is logged).

## Route middleware

For your own Laravel routes:

```php
Route::get('members/video', VideoController::class)->middleware(['craft', 'craft.web', 'member:gold']);
```

## Redeeming a gift (Pro)

```twig
{% set notice = velvetRope.notice() %}
{% if notice %}<p class="{{ notice.ok ? 'ok' : 'error' }}">{{ notice.message }}</p>{% endif %}
<form method="post" action="{{ velvetRope.redeemUrl() }}">
  {{ csrfInput() }}
  <input name="code" placeholder="XXXX-XXXX-XXXX-XXXX" required>
  <button>Redeem</button>
</form>
```

```blade
@php($notice = $velvetRope->notice())
<form method="post" action="{{ $velvetRope->redeemUrl() }}">@csrf <input name="code" required> <button>Redeem</button></form>
```

Redeeming needs a logged-in user; guests are sent to log in. Add a `redirect` field (a site path) to
choose where they land; otherwise it's the *Success path*.
