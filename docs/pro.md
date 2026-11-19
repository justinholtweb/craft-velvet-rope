---
title: Pro features
slug: pro
order: 60
summary: Several plans at once, promotion codes, dunning emails, gift memberships, complimentary access and plan gates.
---

## Several plans at once

Lite keeps each member on one plan (switching happens in Stripe's billing portal). Pro lets a member check
out any number of plans; each puts them in its own group.

## Promotion codes

Create coupons and promotion codes in Stripe. Then either:

- switch on **Promotion codes** to show Stripe's own code box on Checkout, or
- hand out links that carry a code: `velvetRope.checkoutUrl('gold', 'SPRING')` →
  `/actions/velvetrope/checkout/gold?promo=SPRING`. A code Stripe knows is applied; an unknown one falls back
  to the code box (when it's on). Codes are letters, digits, `-` and `_`; anything else is ignored.

Recurring discounts are reflected in MRR.

## Dunning emails

With **Dunning emails** on, a member whose renewal fails gets:

1. **Payment failed** — with a link to update their card and the date access ends (end of the grace period).
2. **Access ends tomorrow** — a day before the grace period runs out.
3. **Membership ended** — when access goes.

Each is sent once per lapse; paying again resets them. Edit the wording, per language, in **Utilities →
System Messages** (`velvetrope_payment_failed`, `velvetrope_grace_ending`, `velvetrope_access_ended`).
Emails go out on the queue, in the member's preferred language. Stripe can send its own failed-payment
emails too (Settings → Billing → Subscriptions and emails); you probably want one or the other.

## Gift memberships

1. In Stripe, create a **one-time** price for the gift (e.g. "Gold, 12 months" for $99).
2. Settings → **Gift options**: handle, plan, months, the one-time price ID. Set **Redeem path** to your
   redeem page.
3. Link to `velvetRope.giftUrl('gold-year')` — anyone can buy, logged in or not.

When Stripe confirms the payment, the buyer is emailed a code (`velvetrope_gift_purchased`). Only a hash of
the code is stored, so it can't be shown again or read from the database. Whoever redeems it (logged in)
gets the plan for that many months, starting when any gift they already have ends. Codes work once.
Gifts aren't subscriptions: they don't renew and aren't MRR.

## Complimentary access

On a user's **Membership** tab, people with *Manage memberships* can grant a plan for some months, with a
note, and revoke it.

## Plan gates

Bouncer-style rules for front-end pages, in **Plan gates**:

| Gate | Matches |
| --- | --- |
| Entries in section | Pages for entries of that section (by handle), on every site. |
| URI pattern | Site paths, ignoring case; `*` matches anything, so `members/*` covers everything under `/members/`. Checked against the requested path and against the matched entry's own URI. |

with the plans that may pass (blank: any plan). **Every** gate that matches a page applies, and the visitor must pass all of them — so a broad URI gate can't open up an entry whose section gate is stricter. Non-members get the same
treatment as `{% requireMembership %}`: guests to log in, members of other plans to your join page (or the
plan's checkout, or a 403). Pages a gate lets through are sent `Cache-Control: private, no-store`, so a
CDN never hands them to the next visitor. A gate naming a plan that doesn't exist lets nobody in.

Gates protect **pages**. Content that appears elsewhere — another template's element query, a search
results page, GraphQL — isn't covered; keep gated content out of those, or gate them too. Preview links
(drafts shared from the CP) aren't gated.

## After a downgrade to Lite

Gates, gift sales, promotion codes, dunning emails and complimentary grants stop. Gifts already bought
can still be issued and redeemed, and grants already made run to their end date — people paid for those.
