# Plugin Store promo images

Marketing images for the Velvet Rope listing on the Craft Plugin Store, in the same theme as
[justinholt.com/plugins/craft-velvetrope](https://justinholt.com/plugins/craft-velvetrope).

```bash
./build.sh          # all slides
./build.sh "2 5"    # just slides 2 and 5
```

Output: `out/velvetrope-promo-N.jpg`, 1920×1080 (rendered at 2× in headless Chrome, downsampled).
**JPEG, never PNG.** `fonts.css` is generated and gitignored.

The cover and the closing slide carry the Craft 6 beta caution, and every footer says
"Craft CMS 6 (beta)" — keep it there until Craft 6 and Velvet Rope leave beta. Prices are TBD, so no
slide quotes a figure yet; add them to the cover badges and slide 7 once set.

| # | Slide |
| --- | --- |
| 1 | Cover — Lite & Pro, Craft 6 beta caution |
| 2 | Gate a page in one line (Twig, Blade, routes) |
| 3 | Stripe status → user groups |
| 4 | Members and MRR |
| 5 | Guest checkout, safely |
| 6 | Pro — gifts, promos, dunning, gates |
| 7 | Closing — summary, beta caution |
