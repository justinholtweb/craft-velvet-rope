---
title: Multi-site and translations
slug: multisite
order: 75
summary: Checkout, emails and accounts follow the site the visitor is on; every string is translatable.
---

Plans and their user groups are global (a membership is to your whole Craft install), but everything a
visitor sees follows their site:

- **Checkout links** are on the current site, and Stripe returns visitors to the *Success*/*Cancel path*
  on that same site.
- **Stripe Checkout's language** is the site's language when Stripe supports it (`fr-FR` → French,
  `fr-CA` → Canadian French), else Stripe picks from the browser.
- **Guest checkout** remembers the site the guest started on: their new account is affiliated with it, so
  Craft's activation email uses that site's URLs and language. A site handle Velvet Rope doesn't know is
  ignored — it never invents one.
- **Gift emails** link the redeem page on the site the gift was bought on, in that site's language.
- **Dunning emails** use the member's preferred language.
- **Plan gates** apply on every site; URI patterns are matched against the path *within* each site.
- **Plan names** go through Craft's translations with the `site` category, like your other site strings:
  add `'Gold' => 'Or'` to `translations/fr/site.php` (or `lang/fr/site.php`).
- **GraphQL** takes a `site` argument for translated names and that site's links.

Email wording is edited per language in Utilities → System Messages. Control-panel strings use the
`velvetrope` translation category (`lang/en/velvetrope.php`).
