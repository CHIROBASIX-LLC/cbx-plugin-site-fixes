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

## Opt-in fixes (v1.10.0+)
The plugin auto-updates on every install, including sites we have set aside, so every fix from v1.10.0 on is **off** unless
the site lists its key in the option `cbxsf_optin`. With the option absent, v1.10.0 renders exactly like v1.9.1 (apart from
the search-page breadcrumb change below), and v1.11.0 exactly like v1.10.0.

| Key | Fix | What it changes |
|---|---|---|
| `acf_titles` | FIX #12 | Raw `[acf field="city" ...]` in SEO strings (title, og/twitter title, og:image:alt, Yoast WebPage name) is rendered, stripped and trimmed. Rank Math sites: also inside image `alt=`/`title=` on posts whose title contains `[acf`. Only the `[acf ...]` shortcodes found in the page's **own** `post_title` are run (each one alone), only on singular pages and never on search pages, so a visitor's `/?s=[acf ...]` or any other shortcode is never executed. `post_title` is never edited. |
| `rm_nodes` | FIX #13 | Rank Math JSON-LD clean-up at 99999, recursive: invalid `performer`/`usedToTreat`/`indication` on MedicalProcedure/Service/MedicalTherapy, their `#webpage` id -> `#service` (never on WebPage or its subtypes), "Top/Premium/Experienced Chiropractor" -> "Chiropractic Care", priceless Offers in a service node's `offers`, `&amp;` in names (and every `&amp;` that kses adds inside Rank Math's ld+json script, URLs included), blank-token descriptions, the agency "BASIX" Person (and its Article on non-blog pages, and the Slack "Written by" row), Person `gender`, empty MedicalCondition lists. |
| `cache_guards` | FIX #14 | WP Rocket cache-poisoning guards: facebookexternalhit/WhatsApp are no longer rejected UAs, and ad click ids (ttclid, ScCid, ...) are ignored query parameters. **Takes effect only after WP Rocket's config file is regenerated on that install:** call `cbxsf_regen_rocket_config( true )` (dry run: lists the config variables that would change) and then `cbxsf_regen_rocket_config()`. |
| `lrc_off` | FIX #15 | WP Rocket Lazy Render Content off (`rocket_lrc_optimization` false). |
| `yoast_perma_sweep` | FIX #16 (1.11.0) | Yoast only. A post published from the schedule whose Yoast row still holds `?p=ID` (canonical, og:url and schema @id then point at `?p=ID`) is rebuilt with Yoast's own builder right after Yoast's watcher (`wp_after_insert_post`), and a daily WP-Cron sweep repairs up to 200 published blog posts still holding `?p=` (only when their real permalink is pretty). Touches only `wp_yoast_indexable`. Dry count: `cbxsf_yoast_perma_candidates()`. The cron event exists only while the key is listed; a leftover event unschedules itself. |
| `sitemap_rules` | FIX #17 (1.11.0) | FIX #10's sitemap archive-link decision asks the site's redirect rules first (enabled Redirection items and active Rank Math redirections that match the archive path exactly), then the HEAD with a 10 s timeout; a failed HEAD is cached for 10 minutes instead of a day (same `cbxsf_arch_*` transients; an old cached failure is asked again). A link is still dropped only on evidence of a redirect. |
| `yoast_agency_author` | FIX #18 (1.11.0) | Yoast only. The agency account ("BASIX", basixadmin / thinkbasix) leaves the hidden data: its Person node is removed from the graph and the Article `author` points at the site's `#organization` (removed when there is none), the "Written by BASIX" Slack/Twitter row goes, and `<meta name="author">` is not printed for agency posts. Visible bylines are untouched. |
| `drdr_author` | FIX #19 (1.11.0) | Author pages only (`is_author()`): "Dr. Dr. " becomes "Dr. " in Elementor widget output (the author template's H1 and "Recent Articles/Videos from" headings), and in titles and JSON-LD if it appears there. The template's "Dr. " prefix stays, so doctors whose display name lacks "Dr." keep it. |
| `paged_front_404` | FIX #20 (1.11.0) | `/page/2/` ... `/page/9999/` on a static front page return the 404 template with status 404 (unless the page has `<!--nextpage-->` or a paginated Elementor Posts/Loop Grid widget of its own). The blog index (`/resources/blog/page/2/`) and blog-on-homepage sites are never touched. Skip sites whose homepage embeds a paginated widget through a template. |

```bash
wp option update cbxsf_optin '["rm_nodes","acf_titles"]' --format=json --autoload=yes   # enable (then purge)
wp option delete cbxsf_optin                                                               # disable all (then purge)
```
After adding or removing `cache_guards`, regenerate WP Rocket's config (`cbxsf_regen_rocket_config()`), or the old values stay in its config file.
Write the option and regenerate in **separate** commands: the guards register when the plugin loads, and the helper refuses
(status `error: ...`, nothing written) when the option and the registered filters disagree.
With the option absent, or listing only 1.10.0 keys, v1.11.0 registers exactly the hooks v1.10.0 registers, plus the FIX #16
WP-Cron handler (inert unless its own event exists).
A site can veto one fix even when it is listed: `add_filter( 'cbxsf_fix_<key>', '__return_false' );` (the older names
`cbxsf_fix_title_shortcodes`, `cbxsf_rm_node_cleanup`, `cbxsf_cache_guards`, `cbxsf_disable_lazy_render` also work).
Put a `cache_guards` veto in a mu-plugin: rollout WP-CLI runs skip the theme, so a theme veto would be ignored there.

## Changelog
- **1.11.0** Opt-in FIX #16 to FIX #20 (above), all off by default: `yoast_perma_sweep` (Yoast `?p=ID` permalinks after
  scheduled posts publish), `sitemap_rules` (sitemap archive links decided from redirect rules, patient HEAD, short failure
  cache), `yoast_agency_author` ("BASIX" out of Yoast's Person/author data, Slack row and meta author), `drdr_author`
  ("Dr. Dr." on author pages), `paged_front_404` (404 for `/page/N/` on the static homepage). No output change with
  `cbxsf_optin` absent: FIX #10 only gains a check of the `sitemap_rules` key before its HEAD, and the FIX #16 cron handler
  is registered but runs only if its own event exists.
- **1.10.0** Opt-in FIX #12 to FIX #15 (above), all off by default. The once-per-version WP Rocket option sync now writes
  only when a required exclusion is really missing (a duplicate entry no longer triggers a rewrite, config regeneration
  and full cache clear; and a missing entry that 1.9.1 skipped because a duplicate hid it from its count is now added,
  with one config regeneration and cache clear on that install: 0 such installs in the 9/29 fleet probe).
  Always-on change (security): FIX #9 / #9b no longer run shortcodes in breadcrumbs on search-result pages, where the
  search crumb is the visitor's own query (`/?s=[acf field=city post_id=options]` printed the Company Info city).
  This is the only output change with `cbxsf_optin` absent, and only on search pages whose query contains `[`.
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
