<?php
/**
 * Plugin Name: CHIROBASIX Site Fixes
 * Plugin URI:  https://chirobasix.com
 * Description: Agency-wide compatibility fixes for CHIROBASIX client sites. (1) Keeps HighLevel booking calendars/forms and similar embeds out of WP Rocket LazyLoad (filter + saved option) so they render at full height. (2) Collapses RankMath's dual-typed Organization/LocalBusiness schema node to its LocalBusiness subtype so priceRange/openingHours validate (fixes SEMRush "property not recognized by Organization") + strips RankMath's malformed address-less potentialAction org-stub on symptom/service pages (fixes SEMRush "LocalBusiness address required") + derives thumbnailUrl for YouTube VideoObjects missing it (fixes SEMRush "thumbnailUrl required"). Auto-updates from GitHub.
 * Version:     1.5.0
 * Author:      CHIROBASIX
 * Author URI:  https://chirobasix.com
 * License:     GPL-2.0+
 * Text Domain: cbx-site-fixes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CBXSF_VERSION', '1.5.0' );

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
 * FIX #3 — RankMath dual-typed Organization/LocalBusiness node fails validation.
 *
 * When RankMath's Local SEO is enabled, it types the site's business node as BOTH a LocalBusiness
 * subtype (e.g. Chiropractor) AND Organization, and adds LocalBusiness-only properties like
 * `priceRange` and `openingHours`. Those properties are valid for the LocalBusiness subtype but
 * NOT for Organization, so strict validators (SEMRush) flag them as "not recognized by the
 * Organization vocabulary" on every page. Collapsing the node to its LocalBusiness subtype alone
 * fixes it — the subtype is still an Organization by inheritance (logo/sameAs/publisher references
 * are unaffected), but the properties are now valid. Only runs when RankMath is active; a node must
 * be BOTH Organization AND a LocalBusiness subtype to be touched (pure Organizations are left alone).
 *
 * Per-site override: add_filter( 'cbxsf_collapse_dual_type', '__return_false' );
 */
add_filter(
	'rank_math/json_ld',
	function ( $data, $jsonld ) {
		if ( ! is_array( $data ) || ! apply_filters( 'cbxsf_collapse_dual_type', true ) ) {
			return $data;
		}
		$is_addressless_biz = function ( $obj ) {
			if ( ! is_array( $obj ) || ! isset( $obj['@type'] ) ) {
				return false;
			}
			$t = (array) $obj['@type'];
			$biz = in_array( 'Organization', $t, true ) || in_array( 'LocalBusiness', $t, true ) || in_array( 'MedicalBusiness', $t, true );
			return $biz && empty( $obj['address'] );
		};
		foreach ( $data as $key => $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			// (a) Collapse a top-level dual-typed Organization + LocalBusiness-subtype node to the subtype.
			if ( isset( $node['@type'] ) && is_array( $node['@type'] ) && in_array( 'Organization', $node['@type'], true ) ) {
				foreach ( array( 'Chiropractor', 'Physician', 'Dentist', 'MedicalBusiness', 'LocalBusiness' ) as $candidate ) {
					if ( in_array( $candidate, $node['@type'], true ) ) {
						$data[ $key ]['@type'] = $candidate;
						break;
					}
				}
			}
			// (b) Drop RankMath's malformed potentialAction whose `object` is an ADDRESS-LESS
			// Organization/LocalBusiness stub. On Symptom (MedicalCondition) + Service templates RankMath
			// emits a `SeekToAction` (itself a misuse — SeekToAction is for media) whose object is a
			// duplicate #organization with no address and a broken @id (no trailing slash, so it never
			// merges with the real org) => SEMRush "LocalBusiness: a value for address is required" on
			// every symptom/service page. The action carries no rich-result value; strip only the bad ones,
			// leaving legit actions (e.g. the WebSite SearchAction) intact.
			if ( isset( $node['potentialAction'] ) && is_array( $node['potentialAction'] ) ) {
				$pa       = $node['potentialAction'];
				$was_list = array_key_exists( 0, $pa );
				$list     = $was_list ? $pa : array( $pa );
				$kept     = array();
				foreach ( $list as $act ) {
					$obj = ( is_array( $act ) && isset( $act['object'] ) ) ? $act['object'] : null;
					if ( $is_addressless_biz( $obj ) ) {
						continue; // drop this malformed action
					}
					$kept[] = $act;
				}
				if ( count( $kept ) !== count( $list ) ) {
					if ( empty( $kept ) ) {
						unset( $data[ $key ]['potentialAction'] );
					} else {
						$data[ $key ]['potentialAction'] = $was_list ? array_values( $kept ) : $kept[0];
					}
				}
			}
		}
		return $data;
	},
	99999, // run LAST — the basix-core-child theme injects its (malformed) potentialAction at prio 170
	2
);

/**
 * FIX #4 — VideoObject missing thumbnailUrl.
 *
 * RankMath auto-detects a YouTube embed on symptom/service pages and emits a VideoObject with an
 * `embedUrl` but no `thumbnailUrl`, which Google/SEMRush flag as invalid ("thumbnailUrl required").
 * YouTube thumbnails are deterministic from the video id, so we derive one from the embed/content
 * URL whenever it is missing. Recursive so it catches the node wherever RankMath places it.
 */
add_filter(
	'rank_math/json_ld',
	function ( $data, $jsonld ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		$fix = function ( &$node ) use ( &$fix ) {
			if ( ! is_array( $node ) ) {
				return;
			}
			$type = isset( $node['@type'] ) ? (array) $node['@type'] : array();
			if ( in_array( 'VideoObject', $type, true ) && empty( $node['thumbnailUrl'] ) ) {
				$src = '';
				foreach ( array( 'embedUrl', 'contentUrl', 'url' ) as $k ) {
					if ( ! empty( $node[ $k ] ) && is_string( $node[ $k ] ) ) {
						$src = $node[ $k ];
						break;
					}
				}
				if ( $src && preg_match( '#(?:youtu\.be/|youtube\.com/(?:watch\?v=|embed/|shorts/|v/))([A-Za-z0-9_-]{11})#', $src, $m ) ) {
					$node['thumbnailUrl'] = 'https://i.ytimg.com/vi/' . $m[1] . '/hqdefault.jpg';
				}
			}
			foreach ( $node as &$child ) {
				if ( is_array( $child ) ) {
					$fix( $child );
				}
			}
		};
		foreach ( $data as &$node ) {
			$fix( $node );
		}
		return $data;
	},
	99999,
	2
);

/**
 * FIX #5 — UAE (Ultimate Addons for Elementor) Business Reviews stalls admin renders.
 *
 * UAE's business-reviews widget (modules/business-reviews/template-blocks/skin-style.php)
 * BYPASSES its reviews transient for any logged-in admin and prefixes every fetch with a
 * hardcoded sleep(2):
 *     $result = get_transient( $transient_name );
 *     if ( false === $result || ( is_user_logged_in() && current_user_can( 'manage_options' ) ) ) {
 *         sleep( 2 ); ... wp_remote_get/post( ..., timeout 60 ) ...
 * So every ADMIN render of the widget pays 2s + a live Google/Yelp round trip — including the
 * Elementor editor's remote-render ajax for templates containing the widget. Those slow,
 * staggered responses widen an Elementor 4.1.5 editor race (onModelRemoteRender:
 * getContainer().document is null -> "Cannot read properties of null (reading 'id')") that
 * kills the editor with "The preview could not be loaded" on pages embedding such templates.
 *
 * Fix: UAE fires `do_action( 'uael_reviews_transient', $transient_name, $settings )`
 * IMMEDIATELY before the get_transient + admin-bypass check, and PHP short-circuit means
 * current_user_can() only runs when the transient EXISTS. So: when a cached copy exists we
 * register a ONE-SHOT user_has_cap filter that reports manage_options=false for exactly that
 * single capability check and removes itself in the same call — UAE then renders from its
 * cache like it does for visitors. When no cache exists we do nothing, so the normal fetch
 * (and freshness) still happens once, populates the transient, and later renders are instant.
 * Single-site fleet: WP_User::has_cap always applies user_has_cap (no super-admin early
 * return outside multisite), so the one-shot consumption is deterministic.
 */
add_action(
	'uael_reviews_transient',
	function ( $transient_name ) {
		if ( false === get_transient( $transient_name ) ) {
			return; // no cache yet — let UAE fetch fresh and store it
		}
		$strip = null;
		$strip = function ( $allcaps, $caps ) use ( &$strip ) {
			if ( in_array( 'manage_options', (array) $caps, true ) ) {
				remove_filter( 'user_has_cap', $strip, 99999 ); // one-shot
				$allcaps['manage_options'] = false;
			}
			return $allcaps;
		};
		add_filter( 'user_has_cap', $strip, 99999, 2 );
	},
	10,
	1
);

/**
 * GitHub auto-updater (mirrors the other CHIROBASIX plugins).
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/class-cbxsf-updater.php';
if ( class_exists( 'CBXSF_Updater' ) ) {
	new CBXSF_Updater( __FILE__, 'CHIROBASIX-LLC', 'cbx-plugin-site-fixes' );
}
