<?php
/**
 * Plugin Name: CHIROBASIX Site Fixes
 * Plugin URI:  https://chirobasix.com
 * Description: Agency-wide compatibility fixes for CHIROBASIX client sites. Currently: keeps HighLevel booking calendars/forms and similar embeds out of WP Rocket LazyLoad so they render at full height instead of being cut off. Auto-updates from GitHub.
 * Version:     1.0.0
 * Author:      CHIROBASIX
 * Author URI:  https://chirobasix.com
 * License:     GPL-2.0+
 * Text Domain: cbx-site-fixes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CBXSF_VERSION', '1.0.0' );

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
		$domains = apply_filters(
			'cbxsf_lazyload_excluded_src',
			array(
				'link.chiropipe.com',          // HighLevel white-label (CHIROBASIX): calendars + forms
				'widgets.leadconnectorhq.com', // HighLevel widgets / chat
				'api.leadconnectorhq.com',     // HighLevel booking iframe (non-white-label)
				'link.msgsndr.com',            // HighLevel form_embed.js host
				'msgsndr.com',
				'cdn.reviewwave.com',          // ReviewWave review widget
				'calendly.com',                // common booking embeds that also self-resize
				'acuityscheduling.com',
			)
		);

		return array_values( array_unique( array_merge( (array) $excluded, $domains ) ) );
	}
);

/**
 * GitHub auto-updater (mirrors the other CHIROBASIX plugins).
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/class-cbxsf-updater.php';
if ( class_exists( 'CBXSF_Updater' ) ) {
	new CBXSF_Updater( __FILE__, 'CHIROBASIX-LLC', 'cbx-plugin-site-fixes' );
}
