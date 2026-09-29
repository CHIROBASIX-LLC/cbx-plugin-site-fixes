# CHIROBASIX Site Fixes

Agency-wide WordPress compatibility fixes for CHIROBASIX client sites, delivered as a single **auto-updating** plugin. Add a fix once here, and it rolls out to every site via the GitHub self-updater.

## Fixes included

### WP Rocket LazyLoad × third-party embeds (v1.0.0)
WP Rocket lazy-loads iframes (rewrites them to `src="about:blank"` + `data-lazy-src`), which breaks the `postMessage` auto-resize handshake used by **HighLevel booking calendars / forms**, **ReviewWave**, and similar embeds — leaving them cut off at a tiny default height.

The WP Rocket **JavaScript** minify/defer/delay exclusion boxes do **not** cover iframe lazy-loading — that's a separate system, which is the usual reason "I excluded the domain but it's still broken."

This plugin excludes the embed source domains from LazyLoad (via the `rocket_lazyload_excluded_src` filter) so the iframe loads with its real `src` and resizes correctly. Harmless on sites without WP Rocket (the filter simply never runs), and it leaves ordinary iframes (e.g. Google Maps) lazy-loaded.

**Excluded by default:** `link.chiropipe.com`, `widgets.leadconnectorhq.com`, `api.leadconnectorhq.com`, `link.msgsndr.com`, `msgsndr.com`, `cdn.reviewwave.com`, `calendly.com`, `acuityscheduling.com`.

**Extend per-site:**
```php
add_filter( 'cbxsf_lazyload_excluded_src', fn( $d ) => array_merge( $d, [ 'your-embed.com' ] ) );
```

## Opt-in fixes (v1.10.0)
The plugin auto-updates on every install, including sites we have set aside, so every fix from v1.10.0 on is **off** unless
the site lists its key in the option `cbxsf_optin`. With the option absent, v1.10.0 renders exactly like v1.9.1.

| Key | Fix | What it changes |
|---|---|---|
| `acf_titles` | FIX #12 | Raw `[acf field="city" ...]` in SEO strings (title, og/twitter title, og:image:alt, Yoast WebPage name) is rendered, stripped and trimmed. Rank Math sites: also inside image `alt=`/`title=` on posts whose title contains `[acf`. Only the `[acf ...]` shortcodes found in the page's **own** `post_title` are run (each one alone), only on singular pages and never on search pages, so a visitor's `/?s=[acf ...]` or any other shortcode is never executed. `post_title` is never edited. |
| `rm_nodes` | FIX #13 | Rank Math JSON-LD clean-up at 99999, recursive: invalid `performer`/`usedToTreat`/`indication` on MedicalProcedure/Service/MedicalTherapy, their `#webpage` id -> `#service` (never on WebPage or its subtypes), "Top/Premium/Experienced Chiropractor" -> "Chiropractic Care", priceless Offers in a service node's `offers`, `&amp;` in names (and every `&amp;` that kses adds inside Rank Math's ld+json script, URLs included), blank-token descriptions, the agency "BASIX" Person (and its Article on non-blog pages, and the Slack "Written by" row), Person `gender`, empty MedicalCondition lists. |
| `cache_guards` | FIX #14 | WP Rocket cache-poisoning guards: facebookexternalhit/WhatsApp are no longer rejected UAs, and ad click ids (ttclid, ScCid, ...) are ignored query parameters. **Takes effect only after WP Rocket's config file is regenerated on that install:** call `cbxsf_regen_rocket_config( true )` (dry run: lists the config variables that would change) and then `cbxsf_regen_rocket_config()`. |
| `lrc_off` | FIX #15 | WP Rocket Lazy Render Content off (`rocket_lrc_optimization` false). |

```bash
wp option update cbxsf_optin '["rm_nodes","acf_titles"]' --format=json --autoload=yes   # enable (then purge)
wp option delete cbxsf_optin                                                               # disable all (then purge)
```
After adding or removing `cache_guards`, regenerate WP Rocket's config (`cbxsf_regen_rocket_config()`), or the old values stay in its config file.
Write the option and regenerate in **separate** commands: the guards register when the plugin loads, and the helper refuses
(status `error: ...`, nothing written) when the option and the registered filters disagree.
A site can veto one fix even when it is listed: `add_filter( 'cbxsf_fix_<key>', '__return_false' );` (the older names
`cbxsf_fix_title_shortcodes`, `cbxsf_rm_node_cleanup`, `cbxsf_cache_guards`, `cbxsf_disable_lazy_render` also work).
Put a `cache_guards` veto in a mu-plugin: rollout WP-CLI runs skip the theme, so a theme veto would be ignored there.

## Changelog
- **1.10.0** Opt-in FIX #12 to FIX #15 (above), all off by default. The once-per-version WP Rocket option sync now writes
  only when a required exclusion is really missing (a duplicate entry no longer triggers a rewrite, config regeneration
  and full cache clear; and a missing entry that 1.9.1 skipped because a duplicate hid it from its count is now added,
  with one config regeneration and cache clear on that install: 0 such installs in the 9/29 fleet probe).
  **Release note:** the 29 installs still on 1.6.0/1.7.0 also receive the always-on FIX #8 to #11 from 1.8.0/1.9.x on
  this update, including FIX #11 (noindex on Elementor `e-landing-page` posts). Several are not clinics (wmsprinkling,
  cdgage, tffj, houstonremodel, practicebroker, morganproserv, houstonac1, winchelirri, navigatorins, szymanskilaw,
  postfamilyfarm, thresholdbill, bloomwellbehav, dental installs). Not new exposure (1.9.1 is already the latest
  release), but check any of them that runs paid-ad landing pages it wants indexed.
- 1.0.0 to 1.9.1: see the commit log (LazyLoad embeds, Rank Math dual type, VideoObject thumbnails, UAE reviews,
  Schema Pro name, Person email, breadcrumb shortcodes, sitemap redirects, landing-page noindex).

## Auto-update
Ships with a GitHub self-updater (same pattern as the other CHIROBASIX plugins). It polls this repo's `releases/latest` and updates in place — no wp.org listing needed. Cut a new GitHub release (bump the `Version:` header + `CBXSF_VERSION`, attach the built zip) and all sites pull it.

## Install
Upload the release zip via **Plugins → Add New → Upload Plugin**, or `wp plugin install <release-zip-url> --activate`.
