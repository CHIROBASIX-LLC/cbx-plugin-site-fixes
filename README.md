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

## Auto-update
Ships with a GitHub self-updater (same pattern as the other CHIROBASIX plugins). It polls this repo's `releases/latest` and updates in place — no wp.org listing needed. Cut a new GitHub release (bump the `Version:` header + `CBXSF_VERSION`, attach the built zip) and all sites pull it.

## Install
Upload the release zip via **Plugins → Add New → Upload Plugin**, or `wp plugin install <release-zip-url> --activate`.
