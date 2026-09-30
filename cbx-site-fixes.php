<?php
/**
 * Plugin Name: CHIROBASIX Site Fixes
 * Plugin URI:  https://chirobasix.com
 * Description: Agency-wide compatibility fixes for CHIROBASIX client sites. (1) Keeps HighLevel booking calendars/forms and similar embeds out of WP Rocket LazyLoad (filter + saved option) so they render at full height. (2) Collapses RankMath's dual-typed Organization/LocalBusiness schema node to its LocalBusiness subtype so priceRange/openingHours validate (fixes SEMRush "property not recognized by Organization") + strips RankMath's malformed address-less potentialAction org-stub on symptom/service pages (fixes SEMRush "LocalBusiness address required") + derives thumbnailUrl for YouTube VideoObjects missing it (fixes SEMRush "thumbnailUrl required"). (3) Opt-in fixes (1.10.0+), OFF unless the site lists the fix in option cbxsf_optin: [acf] shortcodes in SEO titles, Rank Math service/symptom schema clean-up, WP Rocket cache-poisoning guards, WP Rocket Lazy Render off; (1.11.0+) Yoast '?p=ID' permalink repair, sitemap archive links decided from redirect rules, agency 'BASIX' author out of Yoast data, 'Dr. Dr.' on author pages, 404 for /page/N/ on the homepage. Auto-updates from GitHub.
 * Version:     1.11.0
 * Author:      CHIROBASIX
 * Author URI:  https://chirobasix.com
 * License:     GPL-2.0+
 * Text Domain: cbx-site-fixes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CBXSF_VERSION', '1.11.0' );

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
		// Write only when an entry is really missing (1.10.0): a duplicate the site already has is not a reason to
		// rewrite WP Rocket's options, regenerate its config and clear the whole cache on a version bump.
		if ( array_diff( $adds, $cur ) ) {
			$settings[ $key ] = array_values( array_unique( array_merge( $cur, $adds ) ) );
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
 * Yoast builds each breadcrumb (and the BreadcrumbList Google reads) from the raw title. Found on 72 of 100 Yoast sites
 * (9/28). Never on search results (1.10.0): the search crumb is the visitor's own query ('You searched for [acf ...]').
 *
 * Trust (1.11.0, security): up to 1.10.0 every crumb containing '[' went through do_shortcode, so a crumb built from
 * something a user controls ran shortcodes (an author crumb is 'Archives for <display name>': a display name
 * '[acf field="x" post_id="options"]' printed that option; shown in the render harness on catalystchiropracticandrehab.com).
 * Now only a crumb that belongs to a post (Yoast gives a post crumb its 'id') is resolved, and only the shortcodes written
 * in that post's own post_title, each alone: registered, self-closing ones ('[acf field="city" ...]', '[location_name]'),
 * never an enclosing one. That is FIX #12's rule widened from [acf] to the post's own title shortcodes, because location
 * pages title themselves '... in [location_name]' (advancedspines, optimizedsport, bestlifechiro, rodgerssteinch): an
 * [acf]-only rule turned their crumb 'Headache & Migraine Treatment in Conroe' into '... in [location_name]'. The theme
 * already runs those title shortcodes for the page's H1, so this adds no new input. User, term, archive and search crumbs
 * are left exactly as Yoast built them. Never on author pages.
 *
 * Per-site off: add_filter( 'cbxsf_fix_breadcrumb_shortcodes', '__return_false' );
 */
/** The registered, self-closing shortcodes written in a post's own title, e.g. '[acf field="city" post_id="options"]'. */
function cbxsf_title_shortcodes( $post_id ) {
	$post = $post_id ? get_post( (int) $post_id ) : null;
	if ( ! $post || false === strpos( (string) $post->post_title, '[' ) ) {
		return array();
	}
	$title = html_entity_decode( (string) $post->post_title, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	preg_match_all( '/\[([a-zA-Z0-9_-]+)(?=[\s\]])[^\[\]]*\]/', $title, $m, PREG_SET_ORDER );
	$tokens = array();
	foreach ( $m as $x ) {
		if ( shortcode_exists( $x[1] ) && false === strpos( $title, '[/' . $x[1] . ']' ) ) { // registered, and not enclosing
			$tokens[] = $x[0];
		}
	}
	return array_values( array_unique( $tokens ) );
}
/** A crumb of post $post_id with that post's own title shortcodes resolved, each alone (tags stripped, spaces collapsed). */
function cbxsf_crumb_resolve( $text, $post_id ) {
	if ( ! is_string( $text ) || false === strpos( $text, '[' ) || ! $post_id ) {
		return $text;
	}
	$allowed = cbxsf_title_shortcodes( $post_id );
	if ( ! $allowed ) {
		return $text;
	}
	$hit = false;
	$r   = preg_replace_callback(
		'/\[[a-zA-Z0-9_-]+(?=[\s\]])[^\[\]]*\]/',
		function ( $m ) use ( $allowed, &$hit ) {
			$tok = html_entity_decode( $m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8' ); // quotes may arrive entity-encoded
			if ( ! in_array( $tok, $allowed, true ) ) {
				return $m[0];
			}
			$hit = true;
			return wp_strip_all_tags( do_shortcode( $tok ) );
		},
		$text
	);
	if ( null === $r || ! $hit ) {
		return $text;
	}
	$r = preg_replace( '/\s+/u', ' ', $r );
	return null === $r ? $text : trim( $r ); // a regex failure (invalid UTF-8) leaves the crumb as it was
}
add_filter(
	'wpseo_breadcrumb_links',
	function ( $links ) {
		if ( ! is_array( $links ) || is_search() || is_author() || ! apply_filters( 'cbxsf_fix_breadcrumb_shortcodes', true ) ) {
			return $links;
		}
		foreach ( $links as $i => $link ) {
			if ( is_array( $link ) && isset( $link['text'], $link['id'] ) && is_string( $link['text'] ) && false !== strpos( $link['text'], '[' ) ) {
				$links[ $i ]['text'] = cbxsf_crumb_resolve( $link['text'], $link['id'] );
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
 * Never on search results (1.10.0) or author pages. Same trust rule as FIX #9 (1.11.0): Schema Pro's items carry only a
 * URL and a name, so an item belongs to a post when its URL is the permalink of the queried post or one of its ancestors
 * (the only posts in a singular page's trail); only that post's own title shortcodes are resolved. Singular pages only.
 * Per-site off: the same 'cbxsf_fix_breadcrumb_shortcodes' filter.
 */
add_filter(
	'wp_schema_pro_global_schema_breadcrumb',
	function ( $schema ) {
		if ( ! is_array( $schema ) || empty( $schema['itemListElement'] ) || ! is_array( $schema['itemListElement'] ) || ! is_singular() || is_search() || is_author() || ! apply_filters( 'cbxsf_fix_breadcrumb_shortcodes', true ) ) {
			return $schema;
		}
		$posts = null;
		foreach ( $schema['itemListElement'] as $i => $item ) {
			$name = $item['item']['name'] ?? null;
			$url  = $item['item']['@id'] ?? null;
			if ( ! is_string( $name ) || false === strpos( $name, '[' ) || ! is_string( $url ) ) {
				continue;
			}
			if ( null === $posts ) { // URL => post id for the queried post and its ancestors, built once
				$posts = array();
				$qid   = (int) get_queried_object_id();
				foreach ( $qid ? array_merge( array( $qid ), array_map( 'intval', get_post_ancestors( $qid ) ) ) : array() as $pid ) {
					$posts[ esc_url( get_permalink( $pid ) ) ] = $pid;
				}
			}
			if ( isset( $posts[ $url ] ) ) {
				$schema['itemListElement'][ $i ]['item']['name'] = cbxsf_crumb_resolve( $name, $posts[ $url ] );
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
 *     one HEAD request per post type, cached for a day; any error keeps the link). With the opt-in key sitemap_rules
 *     (1.11.0) the site's redirect rules decide first instead: FIX #17 below.
 *
 * Per-site off: add_filter( 'cbxsf_fix_sitemap_redirects', '__return_false' );
 */
function cbxsf_archive_redirects( $post_type ) {
	if ( cbxsf_fix_active( 'sitemap_rules' ) ) {
		return cbxsf_archive_redirects_by_rule( $post_type ); // FIX #17 (opt-in)
	}
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

/* cbxsf-optin-begin ===================================================================================
 * OPT-IN FIXES (1.10.0+): FIX #12 to FIX #20.
 *
 * This plugin auto-updates on every install, including set-aside and skipped ones, so every fix below is OFF
 * unless the site lists its key in the option `cbxsf_optin` (an array of keys, written by the rollout script on
 * the installs that should get it). With the option absent none of these hooks is registered: 1.10.0 behaved exactly
 * like 1.9.1, and 1.11.0 behaves exactly like 1.10.0 except for the always-on FIX #9 / #9b security change (breadcrumb
 * shortcodes: only a post's own [acf ...] title tokens, see FIX #9). Inert exceptions in 1.11.0: FIX #10 asks
 * cbxsf_fix_active( 'sitemap_rules' ) before its HEAD; FIX #16's WP-Cron handler and its deactivation hook are always
 * registered (they only act if FIX #16's own event exists, and then remove it).
 *
 *   Keys:    acf_titles (FIX #12), rm_nodes (FIX #13), cache_guards (FIX #14), lrc_off (FIX #15),
 *            1.11.0: yoast_perma_sweep (FIX #16), sitemap_rules (FIX #17), yoast_agency_author (FIX #18),
 *            drdr_author (FIX #19), paged_front_404 (FIX #20)
 *   Enable:  wp option update cbxsf_optin '["rm_nodes","acf_titles"]' --format=json --autoload=yes
 *   Undo:    remove the key from the array (or delete the option), then purge. cache_guards also needs
 *            cbxsf_regen_rocket_config() again (in a NEW process), because WP Rocket keeps the old values in its
 *            config file.
 *   Veto:    add_filter( 'cbxsf_fix_<key>', '__return_false' ) in a site mu-plugin or the theme (checked when the
 *            fix runs; a cache_guards veto only in a mu-plugin, see FIX #14). The names used in the 9/28 findings
 *            also work: cbxsf_fix_title_shortcodes, cbxsf_rm_node_cleanup, cbxsf_cache_guards, cbxsf_disable_lazy_render.
 */

/** True only when option `cbxsf_optin` is an array that lists $key. */
function cbxsf_optin( $key ) {
	$on = get_option( 'cbxsf_optin' );
	return is_array( $on ) && in_array( $key, $on, true );
}

/** Opted in AND not vetoed by the site (checked each time a fix runs, so a theme filter counts too). */
function cbxsf_fix_active( $key ) {
	if ( ! cbxsf_optin( $key ) ) {
		return false;
	}
	$aliases = array(
		'acf_titles'   => 'cbxsf_fix_title_shortcodes',
		'rm_nodes'     => 'cbxsf_rm_node_cleanup',
		'cache_guards' => 'cbxsf_cache_guards',
		'lrc_off'      => 'cbxsf_disable_lazy_render',
	);
	if ( isset( $aliases[ $key ] ) && ! apply_filters( $aliases[ $key ], true ) ) {
		return false;
	}
	return (bool) apply_filters( 'cbxsf_fix_' . $key, true );
}

/**
 * FIX #12 (key acf_titles) — Raw '[acf field="city" post_id="options"]' in SEO titles and image text.
 *
 * The /lp-top-chiropractor/ ad page's post_title is 'Top Chiropractor in [acf field="city" post_id="options"]' (the
 * template system: never edited). Yoast and Rank Math build <title>, og:title, twitter:title, og:image:alt and the
 * Yoast WebPage name from that raw title (70 sites). On Rank Math sites Image SEO also writes it into every image's
 * alt= and title= (img_alt_format '%title%'), 38 to 54 times per page, on the_content at priority 11.
 *
 * Trust: only the queried post's OWN title is trusted. SEO strings are resolved only on a singular, non-search request,
 * and only the '[acf ...]' shortcodes that appear in that post's post_title are run, each token alone through
 * do_shortcode (never the whole string), then tags stripped and whitespace trimmed/collapsed (bullchiro's Company Info
 * city starts with a space). A visitor's search phrase ('/?s=[acf ...]', which the search-results title templates
 * echo), any other '[acf ...]' and any other shortcode are left exactly as they were. Images: only inside alt= /
 * title= attribute values, only tokens from that post's own title. Anything without such a token passes through
 * byte-for-byte. rank_math/frontend/title also runs at 19, before the per-site cbx-seo-fixes template's 65-character
 * suffix rule at 20 (which would otherwise measure the raw shortcode).
 */
function cbxsf_acf_tokens( $post ) {
	$post = $post ? get_post( $post ) : null;
	if ( ! $post || false === strpos( (string) $post->post_title, '[acf' ) ) {
		return array();
	}
	preg_match_all( '/\[acf(?=[\s\]])[^\[\]]*\]/', html_entity_decode( (string) $post->post_title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $m );
	return array_values( array_unique( $m[0] ) );
}
function cbxsf_resolve_acf( $s, $allowed ) {
	if ( ! is_string( $s ) || false === strpos( $s, '[acf' ) || ! $allowed ) {
		return $s;
	}
	$hit = false;
	$r   = preg_replace_callback(
		'/\[acf(?=[\s\]])[^\[\]]*\]/',
		function ( $m ) use ( $allowed, &$hit ) {
			// Quotes inside the shortcode may arrive entity-encoded; decode this token only, so it parses.
			$tok = html_entity_decode( $m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( ! in_array( $tok, $allowed, true ) ) {
				return $m[0];
			}
			$hit = true;
			return wp_strip_all_tags( do_shortcode( $tok ) );
		},
		$s
	);
	if ( null === $r || ! $hit ) {
		return $s;
	}
	$r = preg_replace( '/\s+/u', ' ', $r );
	return null === $r ? $s : trim( $r ); // a regex failure (invalid UTF-8) leaves the string as it was
}
function cbxsf_acf_title( $s ) {
	if ( ! is_string( $s ) || false === strpos( $s, '[acf' ) || ! is_singular() || is_search() || ! cbxsf_fix_active( 'acf_titles' ) ) {
		return $s;
	}
	$id = get_queried_object_id();
	return $id ? cbxsf_resolve_acf( $s, cbxsf_acf_tokens( $id ) ) : $s;
}
function cbxsf_acf_img_attrs( $html, $post_id = null ) {
	if ( ! is_string( $html ) || false === strpos( $html, 'acf' ) ) {
		return $html;
	}
	$allowed = cbxsf_acf_tokens( null === $post_id ? get_post() : $post_id );
	if ( ! $allowed || ! cbxsf_fix_active( 'acf_titles' ) ) {
		return $html;
	}
	$out = preg_replace_callback(
		'/<[a-zA-Z][^>]*\s(?:alt|title)\s*=[^>]*>/i',
		function ( $tag ) use ( $allowed ) {
			$t = preg_replace_callback(
				'/(\s(?:alt|title)\s*=\s*)(?:"([^"]*)"|\'([^\']*)\')/i',
				function ( $m ) use ( $allowed ) {
					$val = html_entity_decode( isset( $m[3] ) ? $m[3] : $m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
					$new = cbxsf_resolve_acf( $val, $allowed );
					return $new === $val ? $m[0] : $m[1] . '"' . esc_attr( $new ) . '"';
				},
				$tag[0]
			);
			return null === $t ? $tag[0] : $t;
		},
		$html
	);
	return null === $out ? $html : $out; // never blank the content on a regex failure
}
if ( cbxsf_optin( 'acf_titles' ) ) {
	foreach ( array( 'wpseo_title', 'wpseo_opengraph_title', 'wpseo_twitter_title', 'rank_math/frontend/title', 'rank_math/opengraph/facebook/og_title', 'rank_math/opengraph/twitter/twitter_title', 'rank_math/opengraph/facebook/og_image_alt' ) as $cbxsf_hook ) {
		add_filter( $cbxsf_hook, 'cbxsf_acf_title', 99999 );
	}
	add_filter( 'rank_math/frontend/title', 'cbxsf_acf_title', 19 ); // before the per-site template's suffix rule (20)
	add_filter(
		'wpseo_schema_webpage',
		function ( $piece ) {
			if ( is_array( $piece ) && isset( $piece['name'] ) ) {
				$piece['name'] = cbxsf_acf_title( $piece['name'] );
			}
			return $piece;
		},
		99999
	);
	add_filter( 'the_content', 'cbxsf_acf_img_attrs', 99999 );            // after Rank Math add_img_attributes (11)
	add_filter( 'post_thumbnail_html', 'cbxsf_acf_img_attrs', 99999, 2 );
}

/**
 * FIX #13 (key rm_nodes) — Rank Math service/symptom schema clean-up (33 queued Rank Math clinic sites).
 *
 * rank_math/json_ld at 99999 (after the basix-core-child theme at 150-180, a per-site cbx-seo-fixes template at 999
 * and FIX #3/#4/#8 above), recursive over nested nodes. Only JSON-LD and the Slack preview rows change.
 *  (a) performer, usedToTreat, indication removed from MedicalProcedure / Service / MedicalTherapy nodes (theme
 *      rankmath_enrich_service_schema_with_author + an ACF field; not schema.org on those types).
 *  (b) the Services template gives the procedure node '%url%#webpage', the same @id as Rank Math's WebPage: on those
 *      three types only, '#webpage' becomes '#service'. WebPage and its subtypes are never renamed (charleschiro prints
 *      AboutPage/ContactPage on #webpage). A bare {"@id"} reference is re-pointed only when no other node still holds
 *      the old id (otherwise it points at the WebPage and stays).
 *  (c) procedure/service named 'Top|Premium|Experienced Chiropractor' (optionally ' in <town>') -> 'Chiropractic Care'.
 *  (d) an Offer with no price is dropped from the `offers` of those three types, or of an untyped node (castlehills'
 *      Services template prints the procedure node without @type): 'New Patient Special', relative url, no price.
 *      Offers anywhere else (makesOffer, hasOfferCatalog, a Product's offers) are left alone.
 *  (e) every `name` string is entity-decoded ('<' and '>' stay encoded, so kses never truncates a name and nothing can
 *      close the script tag), and '&amp;' in Rank Math's printed ld+json becomes '&' again (literal '&amp;' on
 *      brickrosechiro, alphaspine810, palmharbor743, pravowelln632: see cbxsf_rm_head_close()). That print pass covers
 *      every string in Rank Math's script, because kses turns every '&' into '&amp;' (URLs such as hasMap included).
 *  (f) a description with a blank token (starts ' is proud' / spaces + lowercase, or has 'in , ', 'at , ', 'in .') is
 *      replaced by the page's Rank Math meta description when that is clean, else removed. The fallback is used only
 *      on nodes that describe the page (service types, MedicalCondition, Article, WebPage types); other nodes (a
 *      Person, the business) just lose the broken description.
 *  (g) the agency account (name 'BASIX', @id/url with '/team/basixadmin', sameAs thinkbasix.com) is removed from
 *      employee / performer / author (empty lists dropped), a top-level BASIX Person node is removed, and an Article
 *      credited to it on a non-blog singular page (symptom pages) is removed. Slack preview: the 'Written by BASIX' row.
 *  (h) `gender` removed from every Person (unchecked profile field; female doctors printed 'Male'). No usermeta writes.
 *  (i) MedicalCondition code / signOrSymptom / riskFactor / differentialDiagnosis removed only when, after trimming
 *      trailing commas, the value is empty, ',', '[]' or not valid JSON. Valid populated lists stay word for word.
 * Idempotent with the per-site template (it strips performer/usedToTreat on top-level nodes at 999; this finds none left).
 */
function cbxsf_rm_type_list( $node ) {
	if ( ! is_array( $node ) || ! isset( $node['@type'] ) ) {
		return array();
	}
	return array_values( array_filter( (array) $node['@type'], 'is_string' ) );
}
function cbxsf_rm_is_list( $a ) {
	$i = 0;
	foreach ( $a as $k => $unused ) {
		if ( $k !== $i++ ) {
			return false;
		}
	}
	return true;
}
function cbxsf_rm_page_types() {
	return array( 'WebPage', 'AboutPage', 'CheckoutPage', 'CollectionPage', 'ContactPage', 'FAQPage', 'ItemPage', 'MedicalWebPage', 'ProfilePage', 'QAPage', 'RealEstateListing', 'SearchResultsPage', 'MediaGallery', 'ImageGallery', 'VideoGallery' );
}
function cbxsf_rm_is_agency( $p ) {
	if ( is_string( $p ) ) {
		return 0 === strcasecmp( trim( $p ), 'BASIX' );
	}
	if ( ! is_array( $p ) ) {
		return false;
	}
	$types = cbxsf_rm_type_list( $p );
	if ( $types && ! in_array( 'Person', $types, true ) ) {
		return false;
	}
	if ( isset( $p['name'] ) && is_string( $p['name'] ) && 0 === strcasecmp( trim( $p['name'] ), 'BASIX' ) ) {
		return true;
	}
	foreach ( array( '@id', 'url' ) as $k ) {
		if ( isset( $p[ $k ] ) && is_string( $p[ $k ] ) && false !== stripos( $p[ $k ], '/team/basixadmin' ) ) {
			return true;
		}
	}
	if ( isset( $p['sameAs'] ) ) {
		foreach ( (array) $p['sameAs'] as $s ) {
			if ( is_string( $s ) && false !== stripos( $s, 'thinkbasix.com' ) ) {
				return true;
			}
		}
	}
	return false;
}
function cbxsf_rm_is_priceless_offer( $v ) {
	if ( ! in_array( 'Offer', cbxsf_rm_type_list( $v ), true ) ) {
		return false;
	}
	if ( isset( $v['price'] ) && ( is_int( $v['price'] ) || is_float( $v['price'] ) || ( is_string( $v['price'] ) && '' !== trim( $v['price'] ) ) ) ) {
		return false;
	}
	return empty( $v['priceSpecification'] );
}
/** (d) a priceless Offer in a service (or untyped) node's `offers`; (g) the agency Person in an employee / performer / author slot. */
function cbxsf_rm_drop( $x, $agency_slot, $offer_slot = false ) {
	return ( $offer_slot && cbxsf_rm_is_priceless_offer( $x ) ) || ( $agency_slot && cbxsf_rm_is_agency( $x ) );
}
function cbxsf_rm_decode( $s ) {
	for ( $i = 0; $i < 3 && false !== strpos( $s, '&' ); $i++ ) {
		$d = html_entity_decode( $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( $d === $s ) {
			break;
		}
		$s = $d;
	}
	// '<' and '>' stay encoded: kses (at print) would cut a name at a bare '<', and no decoded name may ever end the script.
	return str_replace( array( '<', '>' ), array( '&lt;', '&gt;' ), $s );
}
function cbxsf_rm_blank_desc( $s ) {
	return is_string( $s ) && (
		preg_match( '/^\h+\p{Ll}/u', $s )                  // ' is proud to offer ...': the name at the start is blank
		|| preg_match( '/^\s*is proud\b/i', $s )
		|| preg_match( '/\b(?:in|at)\h+[,.](?=\s|$)/i', $s ) // 'At , we focus', 'in , TN', 'office in .' (x-ray pages)
	);
}
function cbxsf_rm_meta_description() {
	if ( ! class_exists( '\RankMath\Paper\Paper' ) ) {
		return '';
	}
	try {
		$d = (string) \RankMath\Paper\Paper::get()->get_description();
	} catch ( \Throwable $e ) {
		return '';
	}
	$d = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( html_entity_decode( $d, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ) );
	return ( '' !== $d && ! cbxsf_rm_blank_desc( $d ) ) ? $d : '';
}
function cbxsf_rm_junk_list( $v ) {
	if ( is_array( $v ) ) {
		return empty( $v );
	}
	if ( ! is_string( $v ) ) {
		return false;
	}
	$s = trim( (string) preg_replace( '/[\s,]+$/', '', trim( $v ) ) );
	if ( '' === $s || ',' === $s || '[]' === $s ) {
		return true;
	}
	$d = json_decode( $s, true );
	if ( JSON_ERROR_NONE !== json_last_error() ) {
		return true; // not valid JSON
	}
	return null === $d || '' === $d || array() === $d;
}
function cbxsf_rm_collect_ids( $node, &$ids ) {
	if ( ! is_array( $node ) ) {
		return;
	}
	if ( isset( $node['@id'], $node['@type'] ) && is_string( $node['@id'] ) ) {
		$ids[ $node['@id'] ] = isset( $ids[ $node['@id'] ] ) ? $ids[ $node['@id'] ] + 1 : 1;
	}
	foreach ( $node as $v ) {
		cbxsf_rm_collect_ids( $v, $ids );
	}
}
function cbxsf_rm_remap_refs( $node, $map ) {
	if ( ! is_array( $node ) ) {
		return $node;
	}
	if ( ! isset( $node['@type'] ) && isset( $node['@id'] ) && is_string( $node['@id'] ) && isset( $map[ $node['@id'] ] ) ) {
		$node['@id'] = $map[ $node['@id'] ];
	}
	foreach ( $node as $k => $v ) {
		if ( is_array( $v ) ) {
			$node[ $k ] = cbxsf_rm_remap_refs( $v, $map );
		}
	}
	return $node;
}
function cbxsf_rm_clean( $node, &$ctx ) {
	if ( ! is_array( $node ) ) {
		return $node;
	}
	$types     = cbxsf_rm_type_list( $node );
	$is_page   = (bool) array_intersect( $types, cbxsf_rm_page_types() );
	$is_svc    = (bool) array_intersect( $types, array( 'MedicalProcedure', 'Service', 'MedicalTherapy' ) );
	$describes = $is_svc || $is_page || array_intersect( $types, array( 'MedicalCondition', 'Article', 'BlogPosting', 'NewsArticle' ) );
	if ( $is_svc ) {
		unset( $node['performer'], $node['usedToTreat'], $node['indication'] );                                  // (a)
		if ( ! $is_page && isset( $node['@id'] ) && is_string( $node['@id'] ) && '#webpage' === substr( $node['@id'], -8 ) ) {
			$new = substr( $node['@id'], 0, -8 ) . '#service';                                                   // (b)
			if ( empty( $ctx['ids'][ $new ] ) ) {
				$ctx['renamed'][ $node['@id'] ] = $new;
				$ctx['ids'][ $new ]             = 1;
				$node['@id']                    = $new;
			}
		}
		if ( isset( $node['name'] ) && is_string( $node['name'] ) ) {                                            // (e) then (c)
			$node['name'] = cbxsf_rm_decode( $node['name'] );
			if ( preg_match( '/^\s*(?:Top|Premium|Experienced)\s+Chiropractor(\s+in\s+\S.*)?\s*$/iu', $node['name'], $m ) ) {
				$node['name'] = 'Chiropractic Care' . ( isset( $m[1] ) ? ' ' . trim( $m[1] ) : '' );
			}
		}
	}
	if ( in_array( 'Person', $types, true ) ) {
		unset( $node['gender'] );                                                                               // (h)
	}
	if ( in_array( 'MedicalCondition', $types, true ) ) {
		foreach ( array( 'code', 'signOrSymptom', 'riskFactor', 'differentialDiagnosis' ) as $f ) {              // (i)
			if ( array_key_exists( $f, $node ) && cbxsf_rm_junk_list( $node[ $f ] ) ) {
				unset( $node[ $f ] );
			}
		}
	}
	foreach ( $node as $k => $v ) {
		if ( 'name' === $k && is_string( $v ) ) {
			$node[ $k ] = cbxsf_rm_decode( $v );                                                                 // (e)
			continue;
		}
		if ( 'description' === $k && is_string( $v ) ) {
			if ( cbxsf_rm_blank_desc( $v ) ) {                                                                   // (f)
				$fallback = $describes ? cbxsf_rm_meta_description() : '';
				if ( '' !== $fallback ) {
					$node[ $k ] = $fallback;
				} else {
					unset( $node[ $k ] );
				}
			}
			continue;
		}
		if ( ! is_array( $v ) ) {
			continue;
		}
		$slot   = in_array( $k, array( 'employee', 'performer', 'author' ), true );                           // (g)
		$offers = 'offers' === $k && ( $is_svc || ! $types );                                                    // (d)
		if ( $v && cbxsf_rm_is_list( $v ) ) {
			$kept = array();
			foreach ( $v as $x ) {
				if ( ! cbxsf_rm_drop( $x, $slot, $offers ) ) {
					$kept[] = $x;
				}
			}
			if ( count( $kept ) !== count( $v ) ) {
				if ( ! $kept ) {
					unset( $node[ $k ] ); // now empty: drop the property
					continue;
				}
				$v = $kept;
			}
		} elseif ( cbxsf_rm_drop( $v, $slot, $offers ) ) {
			unset( $node[ $k ] );
			continue;
		}
		$node[ $k ] = cbxsf_rm_clean( $v, $ctx );
	}
	return $node;
}
function cbxsf_rm_nodes( $data, $jsonld = null ) {
	if ( ! is_array( $data ) || ! cbxsf_fix_active( 'rm_nodes' ) ) {
		return $data;
	}
	// (g) An Article credited to the agency on a non-blog singular page (symptoms): the page's own node stays.
	if ( is_singular() && 'post' !== get_post_type( get_queried_object_id() ) ) {
		foreach ( $data as $k => $node ) {
			if ( ! array_intersect( cbxsf_rm_type_list( $node ), array( 'Article', 'BlogPosting', 'NewsArticle' ) ) || empty( $node['author'] ) || ! is_array( $node['author'] ) ) {
				continue;
			}
			$authors = cbxsf_rm_is_list( $node['author'] ) ? $node['author'] : array( $node['author'] );
			if ( count( array_filter( $authors, 'cbxsf_rm_is_agency' ) ) === count( $authors ) ) {
				unset( $data[ $k ] );
			}
		}
	}
	// (g) A standalone top-level agency Person node.
	foreach ( $data as $k => $node ) {
		if ( in_array( 'Person', cbxsf_rm_type_list( $node ), true ) && cbxsf_rm_is_agency( $node ) ) {
			unset( $data[ $k ] );
		}
	}
	$ctx = array( 'ids' => array(), 'renamed' => array() );
	cbxsf_rm_collect_ids( $data, $ctx['ids'] );
	$data = cbxsf_rm_clean( $data, $ctx );
	// (b) Re-point bare references to a renamed id, unless another node (the WebPage) still carries the old id.
	if ( $ctx['renamed'] ) {
		$live = array();
		cbxsf_rm_collect_ids( $data, $live );
		$map = array();
		foreach ( $ctx['renamed'] as $old => $new ) {
			if ( empty( $live[ $old ] ) ) {
				$map[ $old ] = $new;
			}
		}
		if ( $map ) {
			$data = cbxsf_rm_remap_refs( $data, $map );
		}
	}
	return $data;
}
/**
 * (e), second half: Rank Math prints the graph through wp_kses_post_deep(), AFTER every json_ld filter, and kses turns each
 * bare '&' back into '&amp;'. Inside <script type="application/ld+json"> entities are not decoded, so Google reads
 * 'Pravo Wellness &amp; Associates' however the name was stored. Rank Math echoes the script on rank_math/head at 90:
 * buffer 89..91 and turn '&amp;' back into '&' inside Rank Math's own ld+json script only (everything else in the buffer
 * is echoed unchanged). If the buffer stack is not ours at 91 we leave it alone; PHP flushes it at shutdown.
 */
function cbxsf_rm_head_open() {
	if ( cbxsf_fix_active( 'rm_nodes' ) && ob_start() ) { // record a level only for a buffer that really opened
		$GLOBALS['cbxsf_rm_ob_level'] = ob_get_level();
	}
}
function cbxsf_rm_head_close() {
	if ( empty( $GLOBALS['cbxsf_rm_ob_level'] ) ) {
		return;
	}
	$level = $GLOBALS['cbxsf_rm_ob_level'];
	unset( $GLOBALS['cbxsf_rm_ob_level'] );
	if ( ob_get_level() !== $level ) {
		return;
	}
	$html = ob_get_clean();
	$out  = preg_replace_callback(
		'#(<script type="application/ld\+json" class="rank-math-schema[^"]*">)(.*?)(</script>)#s',
		function ( $m ) {
			return $m[1] . str_replace( '&amp;', '&', $m[2] ) . $m[3];
		},
		$html
	);
	echo null === $out ? $html : $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Rank Math's own, already escaped output
}
if ( cbxsf_optin( 'rm_nodes' ) ) {
	add_filter( 'rank_math/json_ld', 'cbxsf_rm_nodes', 99999, 2 );
	add_action( 'rank_math/head', 'cbxsf_rm_head_open', 89 );
	add_action( 'rank_math/head', 'cbxsf_rm_head_close', 91 );
	add_filter(
		'rank_math/opengraph/slack_enhanced_data',
		function ( $data ) {
			if ( ! is_array( $data ) || ! cbxsf_fix_active( 'rm_nodes' ) ) {
				return $data;
			}
			foreach ( $data as $label => $value ) {
				if ( is_string( $value ) && 0 === strcasecmp( trim( $value ), 'BASIX' ) ) {
					unset( $data[ $label ] ); // twitter:label/data 'Written by' => 'BASIX'
				}
			}
			return $data;
		},
		99999
	);
}

/**
 * FIX #14 (key cache_guards) — WP Rocket cache-poisoning guards (speed.md section 5 FIX 1).
 *
 * One crawler hit (facebookexternalhit/WhatsApp, which WP Rocket serves uncached-and-unoptimised) or one ad click with a
 * click-id parameter WP Rocket does not ignore (ttclid ...) caches the unoptimised page (~40 blocking stylesheets) for
 * every visitor for the whole page-cache TTL (1 day now). Same list as calhounspineca csc-speed-fixes.php FIX 1;
 * exact case 'ScCid'; PHP_INT_MAX.
 *
 * IMPORTANT: WP Rocket reads cache_reject_ua and cache_ignored_parameters from its per-domain config file
 * (wp-content/wp-rocket-config/<host>.php), which only rocket_generate_config_file() writes. These filters change
 * nothing on an install until that function runs there after the key is enabled (calhounspineca had the filter for
 * weeks with an old config file: inert). The rollout must regenerate it per site: cbxsf_regen_rocket_config() below
 * (dry run first: it lists the config variables that would change; expect only rocket_cache_reject_ua and
 * rocket_cache_ignored_parameters). Verify by the config file, not by this source.
 *
 * The filters are registered when the plugin loads, so the option must list 'cache_guards' BEFORE the process that
 * regenerates starts: write the option in one command, regenerate in the next (the helper refuses when the option and
 * the registered filters disagree). A site veto of cache_guards belongs in a mu-plugin, not the theme: the rollout's
 * WP-CLI runs skip the theme, so a theme veto would be ignored there but honoured when WP Rocket regenerates from
 * wp-admin, and the two config files would differ.
 */
function cbxsf_cache_guard_ua( $ua ) {
	if ( ! cbxsf_fix_active( 'cache_guards' ) ) {
		return $ua;
	}
	return array_values( array_diff( (array) $ua, array( 'facebookexternalhit', 'WhatsApp' ) ) );
}
function cbxsf_cache_guard_params( $params ) {
	if ( ! cbxsf_fix_active( 'cache_guards' ) ) {
		return $params;
	}
	$params = (array) $params;
	foreach ( array( 'ttclid', 'twclid', 'li_fat_id', 'igshid', 'yclid', 'dclid', 'rdt_cid', 'ScCid', '_hsenc', '_hsmi', 'hsa_acc', 'hsa_cam',
		'hsa_grp', 'hsa_ad', 'hsa_src', 'hsa_tgt', 'hsa_kw', 'hsa_mt', 'hsa_net', 'hsa_ver', 'mkt_tok', 'msclkid', 'epik', 'ttc' ) as $k ) {
		$params[ $k ] = 1;
	}
	return $params;
}
if ( cbxsf_optin( 'cache_guards' ) ) {
	add_filter( 'rocket_cache_reject_ua', 'cbxsf_cache_guard_ua', PHP_INT_MAX );
	add_filter( 'rocket_cache_ignored_parameters', 'cbxsf_cache_guard_params', PHP_INT_MAX );
}

/**
 * For rollout scripts (never called by the plugin itself). Regenerates WP Rocket's per-domain config file so the
 * FIX #14 guards (or any other config-level filter) take effect, ONLY when WP Rocket is active and
 * rocket_generate_config_file() exists. $dry_run = true writes nothing and lists, per config file, the `$rocket_*`
 * variables whose value would change. Returns array( 'status' => ..., 'guards' => bool, 'changed' => array( file => array( var ... ) ) ).
 * Refuses (status 'error: ...', nothing written) when option cbxsf_optin and the FIX #14 filters registered in this
 * process disagree, e.g. the option was written earlier in the same process: run it again in a new process.
 */
function cbxsf_regen_rocket_config( $dry_run = false ) {
	if ( ! defined( 'WP_ROCKET_VERSION' ) || ! function_exists( 'rocket_generate_config_file' ) ) {
		return array( 'status' => 'skipped: WP Rocket not active', 'guards' => false, 'changed' => array() );
	}
	$listed     = cbxsf_optin( 'cache_guards' );
	$registered = false !== has_filter( 'rocket_cache_reject_ua', 'cbxsf_cache_guard_ua' );
	if ( $listed !== $registered ) {
		return array(
			'status'  => 'error: cbxsf_optin ' . ( $listed ? 'lists' : 'does not list' ) . ' cache_guards but its filters are ' . ( $registered ? '' : 'not ' ) . 'registered in this process; nothing written, run again in a new process',
			'guards'  => false,
			'changed' => array(),
		);
	}
	$guards  = $listed && cbxsf_fix_active( 'cache_guards' );
	$changed = array();
	if ( function_exists( 'get_rocket_config_file' ) ) {
		$vars = function ( $php ) {
			preg_match_all( '/^\$(rocket_\w+)\s*=\s*(.*?);\s*$/ms', (string) $php, $m ); // arrays span lines
			return array_combine( $m[1], $m[2] );
		};
		list( $files, $buffer ) = get_rocket_config_file();
		$new = $vars( $buffer );
		foreach ( (array) $files as $file ) {
			$old = is_readable( $file ) ? $vars( file_get_contents( $file ) ) : array(); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			foreach ( array_unique( array_merge( array_keys( $old ), array_keys( $new ) ) ) as $var ) {
				if ( ( isset( $old[ $var ] ) ? $old[ $var ] : null ) !== ( isset( $new[ $var ] ) ? $new[ $var ] : null ) ) {
					$changed[ basename( $file ) ][] = $var;
				}
			}
		}
	}
	if ( $dry_run ) {
		return array( 'status' => 'dry run: nothing written', 'guards' => $guards, 'changed' => $changed );
	}
	rocket_generate_config_file();
	return array( 'status' => 'regenerated', 'guards' => $guards, 'changed' => $changed );
}

/**
 * FIX #15 (key lrc_off) — WP Rocket Lazy Render Content off (speed.md section 7).
 *
 * content-visibility:auto with no intrinsic size collapses below-the-fold sections; on 4 main service pages it sits on
 * the whole page wrapper and the footer jumps (desktop CLS 0.82 to 1.04). Same switch as the SEO templates'
 * disable_lazy_render (redundant where one runs). The wpr_lazy_render_content table is left alone.
 */
if ( cbxsf_optin( 'lrc_off' ) ) {
	add_filter(
		'rocket_lrc_optimization',
		function ( $enabled ) {
			return cbxsf_fix_active( 'lrc_off' ) ? false : $enabled;
		}
	);
}

/**
 * FIX #16 (key yoast_perma_sweep) — Published blog posts whose Yoast data still says '?p=ID' (plan F5, prevention).
 *
 * Yoast builds a post's indexable while it is still scheduled, when its permalink is '?p=ID'. Its watcher rebuilds the row
 * on publish (wp_insert_post at PHP_INT_MAX), but on cron-published batches it sometimes keeps '?p=ID': canonical, og:url
 * and the schema @id then point at '?p=ID', which 301s back (1,008 posts on 15 Yoast sites 9/29, rebuilt by hand;
 * swcmagnolia.com was still getting new ones).
 *  (a) A post going future -> publish is noted on transition_post_status (99999) and repaired on wp_after_insert_post
 *      (PHP_INT_MAX). transition_post_status fires BEFORE wp_insert_post, so a rebuild there would run before Yoast's own
 *      watcher and could be overwritten by it; wp_after_insert_post fires after it, both in wp_publish_post (WP-Cron) and in
 *      wp_insert_post. Any post type.
 *  (b) A daily WP-Cron sweep (event 'cbxsf_yoast_perma_sweep'), production only (wp_get_environment_type()): published
 *      posts of type post whose row still holds '?p=' (joined on wp_posts.post_status, not on the row's own stale status;
 *      so no acf-field-group rows) are repaired, at most 200 and 20 seconds per run. A post that did not heal is skipped for
 *      3 days. Each run records its time, counts and the failed ids (at most 100) in the non-autoloaded option
 *      cbxsf_yoast_perma_sweep_log. The first run is the next 04:xx site time (minute fixed per site), never at opt-in. The
 *      event is scheduled on init only while the key is listed (and not vetoed), Yoast is active, permalinks are pretty and
 *      the environment is production. When that stops being true the next run unschedules it: that run handler is the one
 *      hook this fix registers without the key, and it does nothing unless the event exists. Deactivating the plugin
 *      clears the event.
 * A repair happens only when the row holds '?p=' and get_permalink() does not. It uses Yoast's own builder
 * (Indexable_Builder::build_for_id_and_type, class_exists + catch around every Yoast call). It writes Yoast's derived data
 * only: the post's indexable row and the rows the builder rewrites with it (its hierarchy and primary-term rows). The post's
 * object cache is dropped with wp_cache_delete (posts, post_meta), not clean_post_cache(), which would fire WP Rocket's
 * whole-post purge (home, archives ...) for every row; the sweep clears only that one URL from WP Rocket's cache.
 * Dry count: cbxsf_yoast_perma_candidates() (read-only).
 */
function cbxsf_yoast_perma_ok() {
	return defined( 'WPSEO_VERSION' ) && '' !== (string) get_option( 'permalink_structure' ) && cbxsf_fix_active( 'yoast_perma_sweep' );
}
function cbxsf_yoast_sweep_ok() {
	return cbxsf_yoast_perma_ok() && 'production' === wp_get_environment_type();
}
/** Published posts (type post) whose Yoast row still holds '?p=': read-only, newest first. */
function cbxsf_yoast_perma_candidates( $limit = 200 ) {
	global $wpdb;
	$table = $wpdb->prefix . 'yoast_indexable';
	if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
		return array();
	}
	return array_map(
		'intval',
		(array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT i.object_id FROM {$table} i JOIN {$wpdb->posts} p ON p.ID = i.object_id WHERE i.object_type = 'post' AND i.permalink LIKE %s AND p.post_status = 'publish' AND p.post_type = 'post' ORDER BY i.object_id DESC LIMIT %d",
				'%' . $wpdb->esc_like( '?p=' ) . '%',
				(int) $limit
			)
		)
	);
}
/**
 * Rebuild one post's Yoast row, only when the row holds '?p=' and the post's real permalink does not.
 * Returns true = rebuilt and healed, false = rebuilt (or tried) but still '?p=', null = nothing to do.
 */
function cbxsf_yoast_perma_fix( $post_id ) {
	$post_id = (int) $post_id;
	$link    = $post_id ? get_permalink( $post_id ) : false;
	if ( ! $link || false !== strpos( $link, '?p=' ) || ! function_exists( 'YoastSEO' )
		|| ! class_exists( '\Yoast\WP\SEO\Builders\Indexable_Builder' ) || ! class_exists( '\Yoast\WP\SEO\Repositories\Indexable_Repository' ) ) {
		return null;
	}
	try {
		$row = YoastSEO()->classes->get( \Yoast\WP\SEO\Repositories\Indexable_Repository::class )->find_by_id_and_type( $post_id, 'post', false );
		if ( ! $row || false === strpos( (string) $row->permalink, '?p=' ) ) {
			return null; // healthy, or no row yet (Yoast builds it on the first view)
		}
		wp_cache_delete( $post_id, 'posts' );
		wp_cache_delete( $post_id, 'post_meta' );
		$new = YoastSEO()->classes->get( \Yoast\WP\SEO\Builders\Indexable_Builder::class )->build_for_id_and_type( $post_id, 'post', $row );
	} catch ( \Throwable $e ) {
		return false;
	}
	return is_object( $new ) && isset( $new->permalink ) && false === strpos( (string) $new->permalink, '?p=' );
}
function cbxsf_yoast_perma_mark( $new_status, $old_status, $post ) {
	if ( 'publish' === $new_status && 'future' === $old_status && is_object( $post ) && ! empty( $post->ID ) ) {
		$GLOBALS['cbxsf_yoast_published'][ (int) $post->ID ] = true;
	}
}
function cbxsf_yoast_perma_after( $post_id ) {
	if ( empty( $GLOBALS['cbxsf_yoast_published'][ (int) $post_id ] ) ) {
		return;
	}
	unset( $GLOBALS['cbxsf_yoast_published'][ (int) $post_id ] );
	if ( cbxsf_yoast_perma_ok() ) {
		cbxsf_yoast_perma_fix( $post_id );
	}
}
/** The next 04:xx in the site's time zone after $now (the minute is fixed per site, so installs do not all run at once). */
function cbxsf_yoast_perma_first_run( $now = null ) {
	$now = null === $now ? time() : (int) $now;
	$at  = ( new DateTimeImmutable( '@' . $now ) )->setTimezone( wp_timezone() )->setTime( 4, abs( crc32( (string) home_url() ) ) % 60 );
	return $at->getTimestamp() > $now ? $at->getTimestamp() : $at->modify( '+1 day' )->getTimestamp();
}
function cbxsf_yoast_perma_schedule() {
	if ( cbxsf_yoast_sweep_ok() && ! wp_next_scheduled( 'cbxsf_yoast_perma_sweep' ) ) {
		wp_schedule_event( cbxsf_yoast_perma_first_run(), 'daily', 'cbxsf_yoast_perma_sweep' );
	}
}
function cbxsf_yoast_perma_unschedule() {
	if ( wp_next_scheduled( 'cbxsf_yoast_perma_sweep' ) ) {
		wp_clear_scheduled_hook( 'cbxsf_yoast_perma_sweep' );
	}
}
function cbxsf_yoast_perma_sweep() {
	if ( ! cbxsf_yoast_sweep_ok() ) {
		cbxsf_yoast_perma_unschedule(); // key removed, vetoed, Yoast gone, plain permalinks or not production
		return;
	}
	$now    = time();
	$log    = get_option( 'cbxsf_yoast_perma_sweep_log' );
	$failed = ( is_array( $log ) && isset( $log['failed'] ) && is_array( $log['failed'] ) ) ? $log['failed'] : array();
	foreach ( $failed as $id => $when ) {
		if ( $now - (int) $when > 3 * DAY_IN_SECONDS ) {
			unset( $failed[ $id ] ); // give it another try
		}
	}
	$healed = 0;
	$tried  = 0;
	foreach ( cbxsf_yoast_perma_candidates( 200 ) as $id ) {
		if ( microtime( true ) - $now > 20 ) {
			break; // the rest waits for tomorrow's run
		}
		if ( isset( $failed[ $id ] ) ) {
			continue; // did not heal within the last 3 days
		}
		$r = cbxsf_yoast_perma_fix( $id );
		if ( null === $r ) {
			continue;
		}
		$tried++;
		if ( true === $r ) {
			$healed++;
			if ( function_exists( 'rocket_clean_files' ) ) {
				rocket_clean_files( array( get_permalink( $id ) ) ); // this URL only; the canonical in its cached copy was '?p='
			}
		} else {
			$failed[ $id ] = $now;
		}
	}
	arsort( $failed );
	update_option( 'cbxsf_yoast_perma_sweep_log', array( 'last' => $now, 'tried' => $tried, 'healed' => $healed, 'failed' => array_slice( $failed, 0, 100, true ) ), false );
}
add_action( 'cbxsf_yoast_perma_sweep', 'cbxsf_yoast_perma_sweep' ); // always: lets a leftover event unschedule itself
register_deactivation_hook( __FILE__, 'cbxsf_yoast_perma_unschedule' );
if ( cbxsf_optin( 'yoast_perma_sweep' ) ) {
	add_action( 'transition_post_status', 'cbxsf_yoast_perma_mark', 99999, 3 );
	add_action( 'wp_after_insert_post', 'cbxsf_yoast_perma_after', PHP_INT_MAX, 1 );
	add_action( 'init', 'cbxsf_yoast_perma_schedule', 99 );
}

/**
 * FIX #17 (key sitemap_rules) — CPT archive links that 301 stay in the sitemap on slow origins (plan F12 part A).
 *
 * FIX #10 (b) decides with one loopback HEAD (5 s) and caches the answer for a day, a FAILED HEAD included (code 0 = keep).
 * On slow origins the HEAD times out, so 24 archive links that 301 to the homepage stayed in the sitemaps of 16 sites
 * (brickandrose.com/services/, crystalgrovechiro.com/services/ and /symptoms/ ... 9/29). With the key listed, FIX #10
 * asks cbxsf_archive_redirects_by_rule() instead (FIX #10's own per-site off switch still comes first):
 *  (1) The site's redirect rules decide, read on every call (a sitemap build asks once per post type):
 *      - Redirection (plugin active): an enabled item in an enabled group of the WordPress module, plain URL match, no
 *        regex, no query in the source, action 'url' with 301/302/303/307/308, whose source equals the archive path under
 *        that item's own case / trailing-slash flags (the site's Redirection defaults when the item has none);
 *      - Rank Math (Redirections module on): an active 301/302/307 redirection with an 'exact' source equal to the archive
 *        path, slashes trimmed (Rank Math's own comparison; its 'ignore case' too).
 *      A match means the archive redirects: the link is dropped. Only for WordPress at the domain root.
 *  (2) No matching rule: the HEAD. One at a time: a 60-second sentinel (cbxsf_arch_<md5>_lock) is written before it is
 *      sent, and a sitemap build that finds the sentinel keeps the link without asking. A real answer is cached for a day as
 *      before. No answer, 429 or 5xx backs off: cached as -n for 10 minutes, then 1 hour, then 6 hours (the count n lives a
 *      day in cbxsf_arch_<md5>_fails and is cleared by the next real answer). Same transient names as 1.10.0: a 1.10.0 value
 *      0 or 5xx is not trusted (asked again and overwritten), and 1.10.0 reads -n as "keep". Timeout 10 s (1.10.0: 5 s):
 *      rules now settle most archives with no request at all, the sentinel and the backoff cap how often one is sent, and
 *      the render harness caught 1.10.0's 5 s HEAD timing out on all 5 of brickandrose.com's archives in one render (the
 *      same HEAD answered in 0.07 s minutes later), so a slow moment gets more room before it counts as a failure.
 * Nothing found either way still means keep: a link is dropped only on evidence of a redirect. Database errors are
 * suppressed around the rule queries, so a missing table can never print into the XML.
 */
function cbxsf_red_matches( $url, $match_data, $defaults, $path ) {
	if ( ! is_string( $url ) || false !== strpos( $url, '?' ) ) {
		return false; // a rule with a query string is not a plain path rule
	}
	$json  = is_string( $match_data ) ? json_decode( $match_data, true ) : null;
	$flags = ( is_array( $json ) && isset( $json['source'] ) && is_array( $json['source'] ) ) ? $json['source'] : array();
	if ( ! empty( $flags['flag_regex'] ) ) {
		return false;
	}
	$flag  = function ( $k ) use ( $flags, $defaults ) {
		return isset( $flags[ $k ] ) && is_bool( $flags[ $k ] ) ? $flags[ $k ] : ! empty( $defaults[ $k ] );
	};
	$a = urldecode( $url );
	$b = urldecode( $path );
	if ( $flag( 'flag_trailing' ) ) { // ignore trailing slashes
		$a = untrailingslashit( $a );
		$b = untrailingslashit( $b );
	}
	if ( $flag( 'flag_case' ) ) { // ignore case
		$a = strtolower( $a );
		$b = strtolower( $b );
	}
	return $a === $b;
}
function cbxsf_rm_redirect_matches( $sources, $path ) {
	if ( ! is_array( $sources ) ) {
		return false;
	}
	$uri = trim( $path, '/' );
	foreach ( $sources as $src ) {
		if ( ! is_array( $src ) || ! isset( $src['pattern'], $src['comparison'] ) || 'exact' !== $src['comparison'] || ! is_string( $src['pattern'] ) ) {
			continue;
		}
		$pat = trim( $src['pattern'], '/' );
		if ( $pat === $uri || ( isset( $src['ignore'] ) && 'case' === $src['ignore'] && strtolower( $pat ) === strtolower( $uri ) ) ) {
			return true;
		}
	}
	return false;
}
/** The redirect rule that sends this post type's archive elsewhere ('redirection #12', 'rank math #4'), or ''. Read-only. */
function cbxsf_archive_rule( $post_type ) {
	global $wpdb;
	$link = get_post_type_archive_link( $post_type );
	$path = $link ? (string) wp_parse_url( $link, PHP_URL_PATH ) : '';
	if ( '' === trim( $path, '/' ) || '' !== trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' ) ) {
		return ''; // no archive path, or WordPress in a sub-directory
	}
	$quiet = $wpdb->suppress_errors( true ); // nothing may print into the sitemap XML
	$rule  = '';
	if ( defined( 'REDIRECTION_VERSION' ) ) {
		$norm = strtolower( untrailingslashit( $path ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT i.id, i.url, i.match_data FROM {$wpdb->prefix}redirection_items i JOIN {$wpdb->prefix}redirection_groups g ON g.id = i.group_id
				WHERE i.match_url = %s AND i.regex = 0 AND i.status = 'enabled' AND g.status = 'enabled' AND g.module_id = 1
				AND i.match_type = 'url' AND i.action_type = 'url' AND i.action_code IN (301, 302, 303, 307, 308) ORDER BY i.id",
				'' === $norm ? '/' : $norm
			)
		);
		$defaults = function_exists( 'red_get_options' ) ? (array) red_get_options() : array();
		foreach ( (array) $rows as $r ) {
			if ( cbxsf_red_matches( $r->url, $r->match_data, $defaults, $path ) ) {
				$rule = 'redirection #' . (int) $r->id;
				break;
			}
		}
	}
	$rm_on = false;
	if ( class_exists( '\RankMath\Helper' ) ) {
		try {
			$rm_on = (bool) \RankMath\Helper::is_module_active( 'redirections' );
		} catch ( \Throwable $e ) {
			$rm_on = false;
		}
	}
	if ( '' === $rule && $rm_on ) {
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, sources FROM {$wpdb->prefix}rank_math_redirections WHERE status = 'active' AND header_code IN (301, 302, 307) AND sources LIKE %s ORDER BY id",
				'%' . $wpdb->esc_like( trim( $path, '/' ) ) . '%'
			)
		);
		foreach ( (array) $rows as $r ) {
			$src = ( is_string( $r->sources ) && is_serialized( $r->sources ) ) ? @unserialize( trim( $r->sources ), array( 'allowed_classes' => false ) ) : false; // phpcs:ignore
			if ( cbxsf_rm_redirect_matches( $src, $path ) ) {
				$rule = 'rank math #' . (int) $r->id;
				break;
			}
		}
	}
	$wpdb->suppress_errors( $quiet );
	return $rule;
}
/** FIX #10's decision under sitemap_rules: true = the archive redirects (drop it from the sitemap). */
function cbxsf_archive_redirects_by_rule( $post_type ) {
	if ( '' !== cbxsf_archive_rule( $post_type ) ) {
		return true;
	}
	$redirects = array( 301, 302, 303, 307, 308 );
	$key       = 'cbxsf_arch_' . md5( $post_type );
	$code      = get_transient( $key );
	$c         = (int) $code;
	if ( false !== $code && ( $c < 0 || ( $c >= 100 && 429 !== $c && $c < 500 ) ) ) {
		return in_array( $c, $redirects, true ); // a real answer, or a failure still backing off (-n)
	}
	if ( false !== get_transient( $key . '_lock' ) ) {
		return false; // another sitemap build is asking right now: keep the link
	}
	set_transient( $key . '_lock', 1, MINUTE_IN_SECONDS );
	$url  = get_post_type_archive_link( $post_type );
	$resp = $url ? wp_remote_head( $url, array( 'redirection' => 0, 'timeout' => 10, 'sslverify' => false ) ) : null;
	$c    = ( $resp && ! is_wp_error( $resp ) ) ? (int) wp_remote_retrieve_response_code( $resp ) : 0;
	if ( $c < 100 || 429 === $c || $c >= 500 ) {
		$n = min( 3, (int) get_transient( $key . '_fails' ) + 1 );
		set_transient( $key . '_fails', $n, DAY_IN_SECONDS );
		set_transient( $key, -$n, array( 1 => 10 * MINUTE_IN_SECONDS, 2 => HOUR_IN_SECONDS, 3 => 6 * HOUR_IN_SECONDS )[ $n ] );
		return false;
	}
	if ( false !== get_transient( $key . '_fails' ) ) {
		delete_transient( $key . '_fails' );
	}
	set_transient( $key, $c, DAY_IN_SECONDS );
	return in_array( $c, $redirects, true );
}

/**
 * FIX #18 (key yoast_agency_author) — "BASIX" named as the author in Yoast's hidden data (plan F16).
 *
 * On 35 Yoast sites the agency account (display name 'BASIX', login basixadmin) authors the blog posts (31,235 posts 9/29).
 * Yoast prints it as the Article's author Person, as <meta name="author" content="BASIX"> and as the Slack/Twitter row
 * 'Written by: BASIX' (catalystchiropracticandrehab.com, 920chiro.com ...). All at 99999, never on author pages (a Yoast
 * ProfilePage whose Person was removed would be invalid; these sites redirect author archives anyway):
 *  - wpseo_schema_graph: agency Person nodes (name exactly 'BASIX', an @id / url whose path is the basixadmin author
 *    archive, or the Yoast person @id of an agency account) are removed. An `author` that pointed only at them is re-pointed
 *    at the site's own Organization ('#organization' on Yoast's context site_url, else home_url('/')) when that node is in
 *    the graph, else removed; a co-author list keeps its real authors; any other reference to a removed Person is removed.
 *    A graph without an agency Person is returned untouched. When Yoast represents a person (company_or_person = person)
 *    who is an agency account, the graph is never touched (the site's publisher is that Person): skipped, logged once a day.
 *  - wpseo_enhanced_slack_data: the row whose value is 'BASIX' (or, on a post by an agency account, its display name).
 *  - wpseo_meta_author: empty for a post by an agency account or a name 'BASIX' (Yoast then prints no author tag).
 * Agency accounts, the plan's literal rule only: display_name exactly 'BASIX', or user_login / user_nicename 'basixadmin'.
 * Never by email, url or 'thinkbasix': agency logins were reused and renamed to real doctors (brickandrose.com user 6,
 * login nick@chirobasix.com, is now 'Dr. Matthew Bynum'). The ids are cached for an hour (transient cbxsf_agency_users).
 * The visible byline is not touched (question 13).
 */
function cbxsf_agency_user_ids() {
	global $wpdb;
	static $ids = null;
	if ( null !== $ids ) {
		return $ids;
	}
	$cached = get_transient( 'cbxsf_agency_users' );
	if ( is_array( $cached ) ) {
		$ids = array_map( 'intval', $cached );
		return $ids;
	}
	$ids = array();
	foreach ( (array) $wpdb->get_results( "SELECT ID, user_login, user_nicename, display_name FROM {$wpdb->users} WHERE display_name = 'BASIX' OR user_login = 'basixadmin' OR user_nicename = 'basixadmin'" ) as $u ) {
		if ( is_object( $u ) && isset( $u->ID ) && ( 'BASIX' === $u->display_name || 'basixadmin' === $u->user_login || 'basixadmin' === $u->user_nicename ) ) { // exact (the column collation is not)
			$ids[] = (int) $u->ID;
		}
	}
	set_transient( 'cbxsf_agency_users', $ids, HOUR_IN_SECONDS );
	return $ids;
}
/** An agency Person object: $person_ids are the Yoast person @ids of the agency accounts. Pure. */
function cbxsf_is_agency_person( $n, $person_ids = array() ) {
	if ( ! is_array( $n ) || ! isset( $n['@type'] ) || ! in_array( 'Person', (array) $n['@type'], true ) ) {
		return false;
	}
	if ( isset( $n['@id'] ) && is_string( $n['@id'] ) && in_array( $n['@id'], (array) $person_ids, true ) ) {
		return true;
	}
	if ( isset( $n['name'] ) && is_string( $n['name'] ) && 'BASIX' === trim( $n['name'] ) ) {
		return true;
	}
	foreach ( array( '@id', 'url' ) as $k ) { // the basixadmin author archive: /author/basixadmin/, /team/basixadmin/
		if ( isset( $n[ $k ] ) && is_string( $n[ $k ] ) && false !== strpos( (string) wp_parse_url( $n[ $k ], PHP_URL_PATH ) . '/', '/basixadmin/' ) ) {
			return true;
		}
	}
	return false;
}
/** Walk a node: references to a removed Person go ($gone = @id => true); `author` falls back to $org (a {"@id"} or null). */
function cbxsf_agency_refs( $node, $gone, $org, $person_ids = array() ) {
	$is_gone = function ( $v ) use ( $gone, $person_ids ) {
		return is_array( $v ) && ( ( ! isset( $v['@type'] ) && isset( $v['@id'] ) && is_string( $v['@id'] ) && isset( $gone[ $v['@id'] ] ) ) || cbxsf_is_agency_person( $v, $person_ids ) );
	};
	foreach ( $node as $k => $v ) {
		if ( ! is_array( $v ) ) {
			continue;
		}
		if ( $is_gone( $v ) ) {
			if ( 'author' === $k && $org ) {
				$node[ $k ] = $org;
			} else {
				unset( $node[ $k ] );
			}
			continue;
		}
		if ( $v && cbxsf_rm_is_list( $v ) ) {
			$kept = array_values( array_filter( $v, function ( $x ) use ( $is_gone ) {
				return ! $is_gone( $x );
			} ) );
			if ( count( $kept ) !== count( $v ) ) {
				if ( ! $kept ) {
					if ( 'author' === $k && $org ) {
						$node[ $k ] = $org;
					} else {
						unset( $node[ $k ] );
					}
					continue;
				}
				$v = $kept;
			}
		}
		$node[ $k ] = cbxsf_agency_refs( $v, $gone, $org, $person_ids );
	}
	return $node;
}
/** Remove agency Persons from a Yoast @graph list and fix every reference to them. Pure. */
function cbxsf_agency_graph( $graph, $person_ids, $org_id ) {
	if ( ! is_array( $graph ) ) {
		return $graph;
	}
	$gone    = array();
	$removed = false;
	$list    = cbxsf_rm_is_list( $graph );
	foreach ( $graph as $k => $node ) {
		if ( cbxsf_is_agency_person( $node, $person_ids ) ) {
			if ( isset( $node['@id'] ) && is_string( $node['@id'] ) ) {
				$gone[ $node['@id'] ] = true;
			}
			unset( $graph[ $k ] );
			$removed = true;
		}
	}
	if ( ! $removed && ! cbxsf_agency_has_nested( $graph, $person_ids ) ) {
		return $graph; // nothing of the agency's here: untouched
	}
	$org = null;
	foreach ( $graph as $node ) {
		if ( is_array( $node ) && isset( $node['@id'] ) && $node['@id'] === $org_id ) {
			$org = array( '@id' => $org_id );
		}
	}
	foreach ( $graph as $k => $node ) {
		if ( is_array( $node ) ) {
			$graph[ $k ] = cbxsf_agency_refs( $node, $gone, $org, $person_ids );
		}
	}
	return $list ? array_values( $graph ) : $graph; // a list must stay a JSON array
}
function cbxsf_agency_has_nested( $v, $person_ids ) {
	if ( ! is_array( $v ) ) {
		return false;
	}
	foreach ( $v as $x ) {
		if ( is_array( $x ) && ( cbxsf_is_agency_person( $x, $person_ids ) || cbxsf_agency_has_nested( $x, $person_ids ) ) ) {
			return true;
		}
	}
	return false;
}
/** True when Yoast says the site represents a person who is an agency account (then the graph is left alone). */
function cbxsf_yoast_represents_agency() {
	$t = get_option( 'wpseo_titles' );
	return is_array( $t ) && isset( $t['company_or_person'] ) && 'person' === $t['company_or_person']
		&& ! empty( $t['company_or_person_user_id'] ) && in_array( (int) $t['company_or_person_user_id'], cbxsf_agency_user_ids(), true );
}
function cbxsf_yoast_agency_schema( $graph, $context = null ) {
	if ( ! is_array( $graph ) || is_author() || ! cbxsf_fix_active( 'yoast_agency_author' ) ) {
		return $graph;
	}
	if ( cbxsf_yoast_represents_agency() ) {
		if ( false === get_transient( 'cbxsf_agency_person_site' ) ) {
			set_transient( 'cbxsf_agency_person_site', 1, DAY_IN_SECONDS );
			error_log( 'cbx-site-fixes FIX #18: Yoast represents an agency account as the site person; schema graph left untouched.' ); // phpcs:ignore
		}
		return $graph;
	}
	$person_ids = array();
	if ( is_object( $context ) && function_exists( 'YoastSEO' ) ) {
		foreach ( cbxsf_agency_user_ids() as $uid ) {
			try {
				$pid = YoastSEO()->helpers->schema->id->get_user_schema_id( $uid, $context );
				if ( is_string( $pid ) && '' !== $pid ) {
					$person_ids[] = $pid;
				}
			} catch ( \Throwable $e ) {
				continue;
			}
		}
	}
	$base = '';
	if ( is_object( $context ) && isset( $context->site_url ) ) {
		try {
			$base = is_string( $context->site_url ) ? $context->site_url : '';
		} catch ( \Throwable $e ) {
			$base = '';
		}
	}
	return cbxsf_agency_graph( $graph, $person_ids, trailingslashit( '' !== $base ? $base : home_url( '/' ) ) . '#organization' );
}
/** The author id of the post a Yoast presentation is about, or 0. */
function cbxsf_yoast_presentation_author( $presentation ) {
	return ( is_object( $presentation ) && isset( $presentation->context ) && is_object( $presentation->context ) && isset( $presentation->context->post ) && is_object( $presentation->context->post ) && isset( $presentation->context->post->post_author ) )
		? (int) $presentation->context->post->post_author : 0;
}
function cbxsf_yoast_agency_slack( $data, $presentation = null ) {
	if ( ! is_array( $data ) || is_author() || ! cbxsf_fix_active( 'yoast_agency_author' ) ) {
		return $data;
	}
	$author = cbxsf_yoast_presentation_author( $presentation );
	$name   = ( $author && in_array( $author, cbxsf_agency_user_ids(), true ) ) ? trim( (string) get_the_author_meta( 'display_name', $author ) ) : '';
	foreach ( $data as $label => $value ) {
		if ( is_string( $value ) && ( 'BASIX' === trim( $value ) || ( '' !== $name && trim( $value ) === $name ) ) ) {
			unset( $data[ $label ] ); // twitter:label/data 'Written by' => 'BASIX'
		}
	}
	return $data;
}
function cbxsf_yoast_agency_meta_author( $name, $presentation = null ) {
	if ( ! is_string( $name ) || '' === $name || is_author() || ! cbxsf_fix_active( 'yoast_agency_author' ) ) {
		return $name;
	}
	$author = cbxsf_yoast_presentation_author( $presentation );
	return ( 'BASIX' === trim( $name ) || ( $author && in_array( $author, cbxsf_agency_user_ids(), true ) ) ) ? '' : $name;
}
if ( cbxsf_optin( 'yoast_agency_author' ) ) {
	add_filter( 'wpseo_schema_graph', 'cbxsf_yoast_agency_schema', 99999, 2 );
	add_filter( 'wpseo_enhanced_slack_data', 'cbxsf_yoast_agency_slack', 99999, 2 );
	add_filter( 'wpseo_meta_author', 'cbxsf_yoast_agency_meta_author', 99999, 2 );
}

/**
 * FIX #19 (key drdr_author) — "Dr. Dr. Matthew Bynum" on doctors' author pages (plan F17 part B).
 *
 * The author-page template (Elementor theme builder, /team/<user>/) puts a fixed "Dr. " before the author's display name in
 * its H1 and in the "Recent Articles / Recent Videos from ..." headings. A doctor whose display name already starts with
 * "Dr." gets "Dr. Dr." (10 Rank Math sites, 26 pages 9/29). The template's prefix stays: 16 real doctors' display names
 * lack "Dr." and would lose it (affilatedchirocenter.com/team/gammon1016att-net/ reads "Dr. Karin Gammon").
 * On author archives only (is_author()), a repeated "Dr. " (spaces, tabs or &nbsp; on one line) becomes one, in each Elementor widget's HTML
 * (elementor/widget/render_content: where the template prints it), and, in case a site's title template adds it too, in the
 * Rank Math / Yoast / WordPress titles and the JSON-LD strings. Everything without "Dr. Dr." passes through byte-for-byte.
 * No output buffer. Filtering the display name itself (the_author) cannot help: the name holds one "Dr." and the template
 * adds the other.
 */
function cbxsf_drdr( $s ) {
	if ( ! is_string( $s ) || false === strpos( $s, 'Dr.' ) ) {
		return $s;
	}
	$r = preg_replace( '/\bDr\.((?:\h|&nbsp;|&#160;)+)(?:Dr\.(?:\h|&nbsp;|&#160;)+)+/u', 'Dr.$1', $s ); // same line only
	return null === $r ? $s : $r; // invalid UTF-8: leave it as it was
}
function cbxsf_drdr_deep( $v ) {
	if ( is_string( $v ) ) {
		return cbxsf_drdr( $v );
	}
	if ( is_array( $v ) ) {
		foreach ( $v as $k => $x ) {
			$v[ $k ] = cbxsf_drdr_deep( $x );
		}
	}
	return $v;
}
function cbxsf_drdr_author( $v ) {
	return ( is_author() && cbxsf_fix_active( 'drdr_author' ) ) ? cbxsf_drdr_deep( $v ) : $v;
}
if ( cbxsf_optin( 'drdr_author' ) ) {
	foreach ( array( 'elementor/widget/render_content', 'rank_math/frontend/title', 'rank_math/opengraph/facebook/og_title', 'rank_math/opengraph/twitter/twitter_title',
		'rank_math/json_ld', 'wpseo_title', 'wpseo_opengraph_title', 'wpseo_twitter_title', 'wpseo_schema_graph', 'document_title_parts' ) as $cbxsf_hook ) {
		add_filter( $cbxsf_hook, 'cbxsf_drdr_author', 99999 );
	}
}

/**
 * FIX #20 (key paged_front_404) — /page/2/ ... /page/9999/ on the homepage answer 200 with the homepage (plan F24).
 *
 * With a static front page WordPress maps /page/N/ onto the front page (WP_Query moves 'paged' into 'page') and renders the
 * homepage for any N: WP::handle_404() only looks at the request's own 'page' var, which /page/N/ does not set (129 sites
 * 9/29; the canonical already points home, so low impact). On the static front page with 'page' (or 'paged') above 1 the
 * request becomes a 404 (set_404, status 404, no-cache headers) and the theme's 404 template renders, UNLESS the homepage
 * may really be paginated (then it stays exactly as it is):
 *  - it holds <!--nextpage-->;
 *  - its Elementor data has a pagination switch set to anything but "" or "none" (a key ending in "pagination",
 *    "pagination_type" or "pagination_mode": Elementor Posts / Loop Grid 'pagination_type', UAEL 'classic_pagination' /
 *    'classic_pagination_type', a bare 'pagination'), or so does a template it embeds
 *    (template widget, global widget, [elementor-template] shortcode), followed up to 3 levels / 20 templates; an embedded
 *    template that cannot be followed counts as paginated. The basix-core homepage is built from embedded section
 *    templates (the review's first rule, "any template widget keeps 200", matched 15 of the first 16 homepages scanned),
 *    so they are read rather than assumed. Label / limit keys alone (pagination_prev_label, pagination_page_limit ...) do
 *    not count: the cloned Loop Grid and "Blog - Single" templates carry them with pagination off (no pagination_type),
 *    and counting them switched the fix off on 13 of the first 33 sites scanned;
 *  - it has no page template of its own (default) and Elementor Pro renders it through a theme-builder 'single' template
 *    (Elementor Pro does that only when the page's own template is default) that paginates, by the same rule;
 *  - an earlier pre_handle_404 callback already handled the request (Elementor Pro's own pagination checks at 10).
 * How: pre_handle_404 (core's own 404 decision inside WP::main()) at priority 11, after Elementor Pro's two callbacks at 10
 * (Posts\Module::allow_posts_widget_pagination and Locations_Manager::should_allow_pagination_on_single_templates). Not
 * template_redirect: the render harness showed that by then Rank Math has built the head for the homepage (its title,
 * canonical, robots index) and the homepage's H1 shows inside the 404 template. The query's posts are emptied like a real
 * 404's before WordPress sets up the globals. Elementor Pro's second callback caches the 'single' templates it found for
 * the homepage, which would render the site's single-post template instead of its 404 template: that cache is cleared
 * (Conditions_Manager::clear_location_cache(), guarded). For that request only redirect_canonical is told not to redirect:
 * WP_Query sets page_id to the front page for /page/N/, and on a 404 core sends any request carrying a page_id to that
 * post's permalink (a 301 to the homepage, seen in the harness on a Yoast and a Rank Math site). A homepage that lists the
 * blog (show_on_front = posts) and the posts page (/resources/blog/page/2/) are never touched.
 */
/**
 * True when Elementor data (the JSON in _elementor_data, or post content) may paginate: a pagination switch set to anything
 * but "" / "none" (a key ending in "pagination", "pagination_type" or "pagination_mode": Elementor Posts / Loop Grid
 * 'pagination_type', UAEL 'classic_pagination' / 'classic_pagination_type', a bare 'pagination'), or an embedded template that may (template widget "template_id", global widget
 * "templateID", [elementor-template id=N] shortcode), followed up to 3 levels deep and 20 templates. A reference that cannot
 * be followed (no id, too deep, too many) counts as "may paginate". A referenced template that no longer exists renders
 * nothing and is ignored. $seen is shared across one check.
 */
function cbxsf_elementor_may_paginate( $data, $depth = 0, &$seen = array() ) {
	if ( is_array( $data ) ) {
		$data = (string) wp_json_encode( $data );
	}
	if ( ! is_string( $data ) || '' === $data ) {
		return false;
	}
	if ( preg_match( '/"[a-z0-9_]*pagination(?:_type|_mode)?":"(?!(?:none)?")/i', $data ) ) { // a pagination switch that is on
		return true;
	}
	$tpl = preg_match_all( '/"template_id":"?(\d+)/', $data, $a );
	$glb = preg_match_all( '/"templateID":"?(\d+)/', $data, $b );
	$sc  = preg_match_all( '/\[elementor-template[^\]]*?\bid=\\\\?["\']?(\d+)/', $data, $c );
	if ( preg_match_all( '/"widgetType":"template"/', $data ) > $tpl || preg_match_all( '/"widgetType":"global"/', $data ) > $glb || substr_count( $data, '[elementor-template' ) > $sc ) {
		return true; // a template we cannot identify
	}
	foreach ( array_unique( array_map( 'intval', array_merge( $a[1], $b[1], $c[1] ) ) ) as $id ) {
		if ( isset( $seen[ $id ] ) ) {
			continue;
		}
		if ( $depth >= 3 || count( $seen ) >= 20 ) {
			return true; // too deep or too many to tell
		}
		$seen[ $id ] = true;
		if ( cbxsf_elementor_may_paginate( get_post_meta( $id, '_elementor_data', true ), $depth + 1, $seen ) ) {
			return true;
		}
	}
	return false;
}
/** True when the static front page may really be paginated (then FIX #20 leaves the request alone). */
function cbxsf_front_may_paginate( $post ) {
	$content = is_string( $post->post_content ) ? $post->post_content : '';
	if ( false !== strpos( $content, '<!--nextpage-->' ) ) {
		return true;
	}
	$seen = array();
	if ( cbxsf_elementor_may_paginate( $content, 0, $seen ) || cbxsf_elementor_may_paginate( get_post_meta( $post->ID, '_elementor_data', true ), 0, $seen ) ) {
		return true;
	}
	$tpl = get_post_meta( $post->ID, '_wp_page_template', true );
	if ( ( ! is_string( $tpl ) || '' === $tpl || 'default' === $tpl ) && class_exists( '\ElementorPro\Modules\ThemeBuilder\Module' ) ) {
		try { // Elementor Pro renders this page through its theme-builder 'single' template(s): check those too
			foreach ( (array) \ElementorPro\Modules\ThemeBuilder\Module::instance()->get_conditions_manager()->get_documents_for_location( 'single' ) as $id => $doc ) {
				if ( ! isset( $seen[ (int) $id ] ) && cbxsf_elementor_may_paginate( get_post_meta( (int) $id, '_elementor_data', true ), 1, $seen ) ) {
					return true;
				}
			}
		} catch ( \Throwable $e ) {
			return true; // cannot tell: keep the page as it is
		}
	}
	return false;
}
function cbxsf_paged_front_404( $preempt, $query = null ) {
	if ( $preempt || ! $query instanceof WP_Query || $query->is_404() || ! $query->is_front_page() || ! $query->is_page() || 'page' !== get_option( 'show_on_front' ) ) {
		return $preempt;
	}
	if ( max( (int) $query->get( 'page' ), (int) $query->get( 'paged' ) ) < 2 || ! cbxsf_fix_active( 'paged_front_404' ) ) {
		return $preempt;
	}
	$post = $query->get_queried_object();
	if ( ! $post instanceof WP_Post || cbxsf_front_may_paginate( $post ) ) {
		return $preempt;
	}
	$query->set_404();
	$query->posts      = array(); // a real 404 has no posts: the homepage must not reach the 404 template or the SEO head
	$query->post_count = 0;
	$query->post       = null;
	status_header( 404 );
	nocache_headers();
	add_filter( 'redirect_canonical', '__return_false', PHP_INT_MAX ); // this request only (see above)
	if ( class_exists( '\ElementorPro\Modules\ThemeBuilder\Module' ) ) {
		try {
			$cm = \ElementorPro\Modules\ThemeBuilder\Module::instance()->get_conditions_manager();
			if ( is_object( $cm ) && method_exists( $cm, 'clear_location_cache' ) ) {
				$cm->clear_location_cache(); // it holds the homepage's 'single' templates; the 404 template must be found again
			}
		} catch ( \Throwable $e ) {
			unset( $e ); // Elementor Pro changed: its 404 rendering may then use the cached template (cosmetic only)
		}
	}
	return true; // handled: core's handle_404() would send 200
}
if ( cbxsf_optin( 'paged_front_404' ) ) {
	add_filter( 'pre_handle_404', 'cbxsf_paged_front_404', 11, 2 ); // after Elementor Pro's pagination callbacks (10)
}
/* cbxsf-optin-end ===================================================================================== */

/**
 * GitHub auto-updater (mirrors the other CHIROBASIX plugins).
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/class-cbxsf-updater.php';
if ( class_exists( 'CBXSF_Updater' ) ) {
	new CBXSF_Updater( __FILE__, 'CHIROBASIX-LLC', 'cbx-plugin-site-fixes' );
}
