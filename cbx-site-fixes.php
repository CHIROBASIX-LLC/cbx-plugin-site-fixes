<?php
/**
 * Plugin Name: CHIROBASIX Site Fixes
 * Plugin URI:  https://chirobasix.com
 * Description: Agency-wide compatibility fixes for CHIROBASIX client sites. (1) Keeps HighLevel booking calendars/forms and similar embeds out of WP Rocket LazyLoad (filter + saved option) so they render at full height. (2) Collapses RankMath's dual-typed Organization/LocalBusiness schema node to its LocalBusiness subtype so priceRange/openingHours validate (fixes SEMRush "property not recognized by Organization") + strips RankMath's malformed address-less potentialAction org-stub on symptom/service pages (fixes SEMRush "LocalBusiness address required") + derives thumbnailUrl for YouTube VideoObjects missing it (fixes SEMRush "thumbnailUrl required"). Auto-updates from GitHub.
 * Version:     1.9.1
 * Author:      CHIROBASIX
 * Author URI:  https://chirobasix.com
 * License:     GPL-2.0+
 * Text Domain: cbx-site-fixes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CBXSF_VERSION', '1.9.1' );

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
 * FIX #6 — WP Schema Pro Local Business emitted with an empty name (and empty NAP).
 *
 * On basix-core clones running the Yoast + SmartCrawl + WP Schema Pro stack, Schema Pro's
 * site-wide "Local Business" schema (type MedicalBusiness) maps its fields to the site's ACF
 * option tokens (company_name, phone_number, address, city, state, zip). Schema Pro's internal
 * token resolver returns EMPTY for those on these sites, so it emits a MedicalBusiness with
 * name=null (+ null telephone/address, empty geo/hours) on every page => SEMRush "A value for
 * the name field is required" on every URL. The site's ACF company data IS populated, so we
 * repopulate the node from it at output time via Schema Pro's own filter, and strip the empty
 * null leftovers so the node validates.
 *
 * Inert where it can't help: no `wp_schema_pro_schema_local_business` hook (Schema Pro absent),
 * name already resolved (only fills when empty), or ACF/company_name unavailable (returns the
 * node untouched — never invents data, never makes it worse). Per-site off:
 * add_filter( 'cbxsf_fix_schemapro_localbusiness', '__return_false' );
 */
add_filter(
	'wp_schema_pro_schema_local_business',
	function ( $schema ) {
		if ( ! is_array( $schema ) || ! function_exists( 'get_field' ) || ! apply_filters( 'cbxsf_fix_schemapro_localbusiness', true ) ) {
			return $schema;
		}
		$opt = function ( $k ) {
			$v = get_field( $k, 'option' );
			return ( is_string( $v ) && '' !== $v ) ? trim( wp_strip_all_tags( $v ) ) : '';
		};

		// (1) name — the required field. If we have nothing to fill it with, leave the node as-is.
		if ( empty( $schema['name'] ) ) {
			$name = $opt( 'company_name' );
			if ( '' === $name ) {
				return $schema;
			}
			$schema['name'] = $name;
		}

		// (2) telephone from ACF phone_number (else drop the null key).
		if ( empty( $schema['telephone'] ) ) {
			$tel = $opt( 'phone_number' );
			if ( '' !== $tel ) {
				$schema['telephone'] = $tel;
			} else {
				unset( $schema['telephone'] );
			}
		}

		// (3) address from ACF, when Schema Pro left it empty.
		$addr_empty = empty( $schema['address'] ) || (
			empty( $schema['address']['streetAddress'] ) && empty( $schema['address']['addressLocality'] ) &&
			empty( $schema['address']['postalCode'] ) && empty( $schema['address']['addressRegion'] )
		);
		if ( $addr_empty ) {
			$street = $opt( 'address' );
			$city   = $opt( 'city' );
			$state  = $opt( 'state' );
			$zip    = $opt( 'zip' );
			if ( $street || $city || $state || $zip ) {
				$schema['address'] = array( '@type' => 'PostalAddress' );
				if ( $street ) {
					$schema['address']['streetAddress'] = $street;
				}
				if ( $city ) {
					$schema['address']['addressLocality'] = $city;
				}
				if ( $state ) {
					$schema['address']['addressRegion'] = $state;
				}
				if ( $zip ) {
					$schema['address']['postalCode'] = $zip;
				}
				$schema['address']['addressCountry'] = 'US';
			} else {
				unset( $schema['address'] );
			}
		}

		// (4) strip the empty/null leftovers Schema Pro emits when unmapped.
		if ( array_key_exists( 'priceRange', $schema ) && empty( $schema['priceRange'] ) ) {
			unset( $schema['priceRange'] );
		}
		if ( array_key_exists( 'telephone', $schema ) && empty( $schema['telephone'] ) ) {
			unset( $schema['telephone'] );
		}
		if ( isset( $schema['geo'] ) && ( empty( $schema['geo']['latitude'] ) || empty( $schema['geo']['longitude'] ) ) ) {
			unset( $schema['geo'] );
		}
		if ( isset( $schema['openingHoursSpecification'] ) && is_array( $schema['openingHoursSpecification'] ) ) {
			$valid = array();
			foreach ( $schema['openingHoursSpecification'] as $h ) {
				$days = isset( $h['dayOfWeek'] ) ? array_filter( (array) $h['dayOfWeek'] ) : array();
				if ( ! empty( $days ) && ! empty( $h['opens'] ) && ! empty( $h['closes'] ) ) {
					$valid[] = $h;
				}
			}
			if ( $valid ) {
				$schema['openingHoursSpecification'] = array_values( $valid );
			} else {
				unset( $schema['openingHoursSpecification'] );
			}
		}

		return $schema;
	},
	20
);

/**
 * FIX #7 — UAE Business Reviews "Read More" link resolves to an internal 404.
 *
 * Ultimate Elementor builds each review's read-more href in
 * modules/business-reviews/template-blocks/skin-style.php as:
 *
 *     $user_review_url = explode( '/reviews', $value->author_url );
 *     $review_url      = $user_review_url[0] . '/place/' . $settings['place_id'];
 *
 * When Google returns a review with no author attribution URL (anonymous reviewer),
 * $value->author_url is '' and explode() yields array( '' ), so the href collapses to the
 * ROOT-RELATIVE "/place/<place_id>". Browsers and crawlers resolve that against the site, i.e.
 * https://<client>.com/place/ChIJ... => a 404 on every page the widget renders (SEMRush
 * "internal links are broken"). Which reviews are shown rotates as UAE refreshes its cached
 * feed, so the broken link appears and disappears without anyone touching the site.
 *
 * We repair only the href, through UAE's own filter, rewriting a non-absolute value to Google's
 * canonical Maps URL for the same place id so the link points where it was meant to. Link text,
 * classes and surrounding markup are untouched, so nothing renders differently.
 *
 * Inert where it can't help: absolute URLs pass through byte-for-byte, and a value we can't
 * parse a place id out of is returned as-is (never invents a destination). Per-site off:
 * add_filter( 'cbxsf_fix_uael_review_read_more', '__return_false' );
 */
add_filter(
	'uael_business_reviews_read_more',
	function ( $url ) {
		if ( ! apply_filters( 'cbxsf_fix_uael_review_read_more', true ) ) {
			return $url;
		}

		$candidate = trim( (string) $url );

		// Already a real off-site link — leave it exactly as UAE built it.
		if ( '' !== $candidate && preg_match( '#^https?://#i', $candidate ) ) {
			return $url;
		}

		// Recover the place id UAE appended and rebuild it as an absolute Google Maps URL.
		if ( preg_match( '#(?:^|/)place/([A-Za-z0-9_-]+)#', $candidate, $matches ) ) {
			return 'https://www.google.com/maps/place/?q=place_id:' . rawurlencode( $matches[1] );
		}

		return $url;
	},
	10,
	1
);

/**
 * FIX #8 — Author email addresses published in structured data.
 *
 * The basix-core-child theme builds a Person for each page/post author (rankmath_build_person_schema)
 * and copies the author's WordPress LOGIN email into it (`get_the_author_meta( 'user_email' )`). On
 * template clones that published the demo account's agency email on every service page; once pages are
 * credited to the practice's doctor it would publish the doctor's private login address instead. No
 * search feature uses a Person email. This removes `email` from every Person object anywhere in the
 * graph (top-level nodes and nested author/employee/performer objects). Business emails on
 * Organization / LocalBusiness nodes are left alone. Runs last on Rank Math (theme hooks run at
 * 150-170) and on Yoast.
 *
 * Per-site off: add_filter( 'cbxsf_strip_person_email', '__return_false' );
 */
function cbxsf_strip_person_email( $node ) {
	if ( ! is_array( $node ) ) {
		return $node;
	}
	if ( isset( $node['@type'] ) && in_array( 'Person', (array) $node['@type'], true ) ) {
		unset( $node['email'] );
	}
	foreach ( $node as $k => $v ) {
		if ( is_array( $v ) ) {
			$node[ $k ] = cbxsf_strip_person_email( $v );
		}
	}
	return $node;
}
add_filter(
	'rank_math/json_ld',
	function ( $data, $jsonld ) {
		return apply_filters( 'cbxsf_strip_person_email', true ) ? cbxsf_strip_person_email( $data ) : $data;
	},
	99999,
	2
);
add_filter(
	'wpseo_schema_graph',
	function ( $graph ) {
		return apply_filters( 'cbxsf_strip_person_email', true ) ? cbxsf_strip_person_email( $graph ) : $graph;
	},
	99999
);

/**
 * FIX #9 — Yoast breadcrumbs show raw ACF shortcodes: 'Top Chiropractor in [acf field="city" post_id="options"]'.
 *
 * Template page titles contain ACF shortcodes. Themes run them through the_title, so the page looks right, but
 * Yoast builds each breadcrumb (and the BreadcrumbList Google reads) from the raw title. This runs the shortcode on
 * each crumb, then strips tags. Crumbs without a '[' are untouched. Found on 72 of 100 Yoast sites (9/28).
 *
 * Per-site off: add_filter( 'cbxsf_fix_breadcrumb_shortcodes', '__return_false' );
 */
add_filter(
	'wpseo_breadcrumb_links',
	function ( $links ) {
		if ( ! is_array( $links ) || ! apply_filters( 'cbxsf_fix_breadcrumb_shortcodes', true ) ) {
			return $links;
		}
		foreach ( $links as $i => $link ) {
			if ( isset( $link['text'] ) && is_string( $link['text'] ) && false !== strpos( $link['text'], '[' ) ) {
				$links[ $i ]['text'] = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( do_shortcode( $link['text'] ) ) ) );
			}
		}
		return $links;
	},
	99999
);

/**
 * FIX #9b — Schema Pro prints its own BreadcrumbList (next to Yoast's) from the same raw titles, so Google still
 * read 'Top Chiropractor in [acf field="city" ...]' on 18 Schema Pro sites after FIX #9. Same treatment here.
 * Schema Pro caches its output in post meta 'wp_schema_pro_optimized_structured_data': clear it after updating.
 * Per-site off: the same 'cbxsf_fix_breadcrumb_shortcodes' filter.
 */
add_filter(
	'wp_schema_pro_global_schema_breadcrumb',
	function ( $schema ) {
		if ( ! is_array( $schema ) || empty( $schema['itemListElement'] ) || ! apply_filters( 'cbxsf_fix_breadcrumb_shortcodes', true ) ) {
			return $schema;
		}
		foreach ( $schema['itemListElement'] as $i => $item ) {
			$name = $item['item']['name'] ?? null;
			if ( is_string( $name ) && false !== strpos( $name, '[' ) ) {
				$schema['itemListElement'][ $i ]['item']['name'] = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( do_shortcode( $name ) ) ) );
			}
		}
		return $schema;
	},
	99999
);

/**
 * FIX #10 — Sitemaps list addresses that only redirect.
 *
 * (a) JetMenu's 'jet-menu' mega-menu blocks are a public post type, so Yoast and Rank Math list them in the
 *     sitemap; each one 302s to the homepage. They are never pages: excluded.
 * (b) Template post-type archives (/services/, /symptoms/ ...) 301 to the homepage on most sites but stay in the
 *     sitemap. The archive link is dropped only when the archive URL really answers with a redirect (checked with
 *     one HEAD request per post type, cached for a day; any error keeps the link).
 *
 * Per-site off: add_filter( 'cbxsf_fix_sitemap_redirects', '__return_false' );
 */
function cbxsf_archive_redirects( $post_type ) {
	$key  = 'cbxsf_arch_' . md5( $post_type );
	$code = get_transient( $key );
	if ( false === $code ) {
		$url  = get_post_type_archive_link( $post_type );
		$resp = $url ? wp_remote_head( $url, array( 'redirection' => 0, 'timeout' => 5, 'sslverify' => false ) ) : null;
		$code = ( $resp && ! is_wp_error( $resp ) ) ? (int) wp_remote_retrieve_response_code( $resp ) : 0;
		set_transient( $key, $code, DAY_IN_SECONDS );
	}
	return in_array( (int) $code, array( 301, 302, 307, 308 ), true );
}
add_filter(
	'wpseo_sitemap_exclude_post_type',
	function ( $excluded, $post_type ) {
		return ( 'jet-menu' === $post_type && apply_filters( 'cbxsf_fix_sitemap_redirects', true ) ) ? true : $excluded;
	},
	10,
	2
);
add_filter(
	'rank_math/sitemap/exclude_post_type',
	function ( $exclude, $post_type ) {
		return ( 'jet-menu' === $post_type && apply_filters( 'cbxsf_fix_sitemap_redirects', true ) ) ? true : $exclude;
	},
	10,
	2
);
foreach ( array( 'wpseo_sitemap_post_type_archive_link', 'rank_math/sitemap/post_type_archive_link' ) as $cbxsf_hook ) {
	add_filter(
		$cbxsf_hook,
		function ( $link, $post_type ) {
			if ( ! $link || ! apply_filters( 'cbxsf_fix_sitemap_redirects', true ) ) {
				return $link;
			}
			return cbxsf_archive_redirects( $post_type ) ? false : $link;
		},
		10,
		2
	);
}

/**
 * FIX #11 — Paid-ad landing pages are open to Google.
 *
 * Elementor landing pages (post type e-landing-page: lp-back-pain, lp-top-chiropractor ...) carry the same ad copy
 * on many practices' domains and compete with each site's real service pages. They stay live for the ads but get
 * noindex (Yoast, Rank Math, or WordPress core when neither is active) and leave the sitemap. Nick 9/28.
 *
 * Per-site off: add_filter( 'cbxsf_noindex_landing_pages', '__return_false' );
 */
function cbxsf_is_landing_page() {
	return apply_filters( 'cbxsf_noindex_landing_pages', true ) && is_singular( 'e-landing-page' );
}
add_filter(
	'wpseo_robots_array',
	function ( $robots ) {
		if ( is_array( $robots ) && cbxsf_is_landing_page() ) {
			$robots['index'] = 'noindex';
		}
		return $robots;
	},
	99999
);
add_filter(
	'rank_math/frontend/robots',
	function ( $robots ) {
		if ( is_array( $robots ) && cbxsf_is_landing_page() ) {
			unset( $robots['index'] );
			$robots['noindex'] = 'noindex';
		}
		return $robots;
	},
	99999
);
add_filter(
	'wp_robots',
	function ( $robots ) {
		if ( cbxsf_is_landing_page() ) {
			$robots['noindex'] = true;
			unset( $robots['index'] );
		}
		return $robots;
	},
	99999
);
add_filter(
	'wpseo_sitemap_exclude_post_type',
	function ( $excluded, $post_type ) {
		return ( 'e-landing-page' === $post_type && apply_filters( 'cbxsf_noindex_landing_pages', true ) ) ? true : $excluded;
	},
	10,
	2
);
add_filter(
	'rank_math/sitemap/exclude_post_type',
	function ( $exclude, $post_type ) {
		return ( 'e-landing-page' === $post_type && apply_filters( 'cbxsf_noindex_landing_pages', true ) ) ? true : $exclude;
	},
	10,
	2
);
add_filter(
	'wp_sitemaps_post_types',
	function ( $types ) {
		if ( apply_filters( 'cbxsf_noindex_landing_pages', true ) ) {
			unset( $types['e-landing-page'] );
		}
		return $types;
	}
);

/**
 * GitHub auto-updater (mirrors the other CHIROBASIX plugins).
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/class-cbxsf-updater.php';
if ( class_exists( 'CBXSF_Updater' ) ) {
	new CBXSF_Updater( __FILE__, 'CHIROBASIX-LLC', 'cbx-plugin-site-fixes' );
}
