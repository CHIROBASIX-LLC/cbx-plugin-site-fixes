<?php
/**
 * Plugin Name: CHIROBASIX Site Fixes
 * Plugin URI:  https://chirobasix.com
 * Description: Agency-wide compatibility fixes for CHIROBASIX client sites. Currently: keeps HighLevel booking calendars/forms and similar embeds out of WP Rocket LazyLoad (both the filter AND the saved option, since the filter alone does not exclude iframes) so they render at full height instead of being cut off. Auto-updates from GitHub.
 * Version:     1.1.0
 * Author:      CHIROBASIX
 * Author URI:  https://chirobasix.com
 * License:     GPL-2.0+
 * Text Domain: cbx-site-fixes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CBXSF_VERSION', '1.1.0' );

/**
 * The embed hosts that must never be lazy-loaded or delayed (they self-resize via postMessage
 * or bootstrap interactive widgets). Single source of truth for both the filter (FIX #1) and the
 * saved-option sync (FIX #2). Extend per-site via the `cbxsf_lazyload_excluded_src` filter.
 */
function cbxsf_embed_hosts() {
	return array(
		'link.chiropipe.com',          // HighLevel white-label (CHIROBASIX): calendars + forms
		'widgets.leadconnectorhq.com', // HighLevel widgets / chat
		'api.leadconnectorhq.com',     // HighLevel booking/form iframe (canonical)
		'link.msgsndr.com',            // HighLevel form_embed.js host
		'msgsndr.com',
		'cdn.reviewwave.com',          // ReviewWave review widget
		'calendly.com',                // common booking embeds that also self-resize
		'acuityscheduling.com',
	);
}

/**
 * FIX #1 — Embeds render cut off under WP Rocket LazyLoad.
 *
 * WP Rocket's LazyLoad for iframes rewrites embeds to `src="about:blank"` + `data-lazy-src`,
 * which breaks the postMessage auto-resize handshake that HighLevel booking calendars, forms,
 * and similar third-party embeds rely on — so they get stuck at a tiny default height and
 * appear cut off. The JS minify/defer/delay exclusion boxes in WP Rocket do NOT cover iframe
 * lazy-loading (that is a separate system), which is why excluding the domain there alone does
 * not fix it. Excluding the source domains from LazyLoad lets the iframe load with its real
 * src and resize correctly. Harmless on sites without WP Rocket (the filter simply never runs).
 *
 * Sites can extend the list: add_filter( 'cbxsf_lazyload_excluded_src', fn( $d ) => [...$d, 'foo.com'] );
 */
add_filter(
	'rocket_lazyload_excluded_src',
	function ( $excluded ) {
		$domains = apply_filters( 'cbxsf_lazyload_excluded_src', cbxsf_embed_hosts() );
		return array_values( array_unique( array_merge( (array) $excluded, $domains ) ) );
	}
);

/**
 * FIX #2 — Ensure the embed hosts are in WP Rocket's SAVED options, not just the filter.
 *
 * The `rocket_lazyload_excluded_src` filter (FIX #1) does NOT reliably keep iframes out of
 * LazyLoad — WP Rocket still rewrites HighLevel form/booking iframes to `about:blank`, breaking
 * the resize handshake. The mechanism that actually works is the saved `exclude_lazyload`
 * option (the "Excluded images or iframes" box). This idempotently merges the embed hosts into
 * `exclude_lazyload` (and the JS delay/defer exclusion lists, so form_embed.js is never delayed),
 * then regenerates WP Rocket's config + clears the cache — ONCE per plugin version. Harmless
 * without WP Rocket (the option simply does not exist and nothing runs).
 *
 * Extend per-site: add_filter( 'cbxsf_lazyload_excluded_src', fn( $d ) => [...$d, 'foo.com'] );
 */
function cbxsf_sync_rocket_options() {
	if ( get_option( 'cbxsf_rocket_opts_synced' ) === CBXSF_VERSION ) {
		return; // already synced for this version
	}
	$slug     = defined( 'WP_ROCKET_SLUG' ) ? WP_ROCKET_SLUG : 'wp_rocket_settings';
	$settings = get_option( $slug );
	if ( ! is_array( $settings ) ) {
		// WP Rocket not installed/active — mark done so we don't re-check every request.
		update_option( 'cbxsf_rocket_opts_synced', CBXSF_VERSION, false );
		return;
	}
	$hosts   = apply_filters( 'cbxsf_lazyload_excluded_src', cbxsf_embed_hosts() );
	$scripts = array( 'form_embed.js', 'leadconnectorhq.com', 'msgsndr.com', 'chiropipe.com' );
	$changed = false;
	foreach ( array( 'exclude_lazyload' => $hosts, 'delay_js_exclusions' => $scripts, 'exclude_defer_js' => $scripts ) as $key => $adds ) {
		$cur = isset( $settings[ $key ] ) && is_array( $settings[ $key ] ) ? $settings[ $key ] : array();
		$new = array_values( array_unique( array_merge( $cur, $adds ) ) );
		if ( count( $new ) !== count( $cur ) ) {
			$settings[ $key ] = $new;
			$changed          = true;
		}
	}
	if ( $changed ) {
		update_option( $slug, $settings );
		if ( function_exists( 'rocket_generate_config_file' ) ) {
			rocket_generate_config_file();
		}
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
	}
	update_option( 'cbxsf_rocket_opts_synced', CBXSF_VERSION, false );
}
add_action( 'admin_init', 'cbxsf_sync_rocket_options' );
add_action( 'init', 'cbxsf_sync_rocket_options', 99 ); // also cover front-end/WP-CLI so it applies without an admin visit

/**
 * GitHub auto-updater (mirrors the other CHIROBASIX plugins).
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/class-cbxsf-updater.php';
if ( class_exists( 'CBXSF_Updater' ) ) {
	new CBXSF_Updater( __FILE__, 'CHIROBASIX-LLC', 'cbx-plugin-site-fixes' );
}
