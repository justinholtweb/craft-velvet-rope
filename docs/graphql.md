---
title: GraphQL
slug: graphql
order: 70
summary: The velvetRopePlans query for headless pricing pages — and why there's no membership status over GraphQL.
---

For a headless front end, Velvet Rope adds one read-only query, in Lite and Pro:

```graphql
{
  velvetRopePlans(site: "default") {
    handle
    name          # translated for the site (category `site`)
    trialDays
    checkoutUrl   # send the visitor here, in a browser, to subscribe
    giftOptions { handle months url }   # Pro
  }
}
```

Arguments: `site` (a site handle the schema can read; default the primary one) and `handle` (one plan).

Turn it on per schema with **Velvet Rope → View membership plans** in the schema's permissions. Without
it the query doesn't exist for that schema. A site the schema can't read is refused.

## What isn't there, on purpose

- **Stripe price IDs, keys and secrets** — not needed to draw a pricing page, so not exposed.
- **Members, subscriptions, MRR** — personal and commercial data; that's the control panel's job.
- **"Is the current user subscribed?"** — a GraphQL token identifies a *schema or app*, not the person
  looking at your page, so over token auth the answer would describe the wrong person. Ask your
  session-authenticated site (a Twig/Blade endpoint using `velvetRope.subscribed()`) instead.

Prices themselves live in Stripe; if your pricing page needs amounts, put them in your own content (or
fetch them from Stripe server-side).
