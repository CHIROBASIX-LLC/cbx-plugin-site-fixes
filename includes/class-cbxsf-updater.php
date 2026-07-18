<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GitHub-based auto-updater for the CHIROBASIX Site Fixes plugin.
 */
class CBXSF_Updater {

	private $slug;
	private $plugin_file;
	private $github_owner;
	private $github_repo;
	private $current_version;
	private $github_response;

	public function __construct( $plugin_file, $github_owner, $github_repo ) {
		$this->plugin_file  = $plugin_file;
		$this->slug         = plugin_basename( $plugin_file );
		$this->github_owner = $github_owner;
		$this->github_repo  = $github_repo;

		$plugin_data           = get_file_data( $plugin_file, array( 'Version' => 'Version' ) );
		$this->current_version = $plugin_data['Version'];

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_post_install', array( $this, 'post_install' ), 10, 3 );
	}

	/**
	 * Fetch the latest GitHub release, heavily guarded so an update check can
	 * never noticeably stall a latency-sensitive request (Elementor editor
	 * loads/saves, admin-ajax, heartbeats):
	 *  - successes are cached in a transient (as before);
	 *  - FAILURES set a 15-min backoff transient, so a rate-limited GitHub
	 *    (60 unauth req/hr/IP — shared WPE egress IPs burn this fast) is not
	 *    re-paid on every transient refresh;
	 *  - the HTTP timeout is 3s (was 10s);
	 *  - when $allow_network is false, only cached data is returned.
	 */
	private function get_github_release( $allow_network = true ) {
		if ( null !== $this->github_response ) {
			return $this->github_response;
		}

		$cache_key = 'cbxsf_github_release';
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			$this->github_response = $cached;
			return $cached;
		}

		if ( ! $allow_network || false !== get_transient( 'cbxsf_github_backoff' ) ) {
			// No cached release and either network is disallowed for this
			// request or we recently failed — skip silently; cron/next
			// eligible request will retry.
			return false;
		}

		$url = sprintf(
			'https://api.github.com/repos/%s/%s/releases/latest',
			$this->github_owner,
			$this->github_repo
		);

		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'Accept'     => 'application/vnd.github.v3+json',
					'User-Agent' => 'CBX-Site-Fixes-Updater',
				),
				'timeout' => 3,
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			set_transient( 'cbxsf_github_backoff', 1, 15 * MINUTE_IN_SECONDS );
			$this->github_response = false;
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['tag_name'] ) ) {
			set_transient( 'cbxsf_github_backoff', 1, 15 * MINUTE_IN_SECONDS );
			$this->github_response = false;
			return false;
		}

		$this->github_response = $body;

		$remote_version = ltrim( $body['tag_name'], 'vV' );
		$cache_ttl      = version_compare( $remote_version, $this->current_version, '>' )
			? 6 * HOUR_IN_SECONDS
			: 1 * HOUR_IN_SECONDS;

		set_transient( $cache_key, $body, $cache_ttl );

		return $body;
	}

	public function check_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		// Never perform network I/O during latency-sensitive requests: any
		// admin-ajax call (Elementor autosaves, heartbeats) or the Elementor
		// editor page itself (post.php?action=elementor). WP core's
		// _maybe_update_plugins() piggybacks on admin_init for these requests,
		// which is how a slow GitHub response used to stall the editor.
		// Cached data is still used; cron + normal admin screens do the fetch.
		$latency_sensitive = wp_doing_ajax()
			|| ( isset( $_GET['action'] ) && 'elementor' === $_GET['action'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$release = $this->get_github_release( ! $latency_sensitive );
		if ( ! $release ) {
			return $transient;
		}

		$remote_version = ltrim( $release['tag_name'], 'vV' );

		if ( version_compare( $remote_version, $this->current_version, '>' ) ) {
			$transient->response[ $this->slug ] = (object) array(
				'slug'        => dirname( $this->slug ),
				'plugin'      => $this->slug,
				'new_version' => $remote_version,
				'url'         => $release['html_url'],
				'package'     => $this->get_download_url( $release ),
			);
		}

		return $transient;
	}

	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( dirname( $this->slug ) !== $args->slug ) {
			return $result;
		}

		$release = $this->get_github_release();
		if ( ! $release ) {
			return $result;
		}

		$remote_version = ltrim( $release['tag_name'], 'vV' );

		$info                = new stdClass();
		$info->name          = 'CHIROBASIX Site Fixes';
		$info->slug          = dirname( $this->slug );
		$info->version       = $remote_version;
		$info->author        = '<a href="https://chirobasix.com">CHIROBASIX</a>';
		$info->homepage      = $release['html_url'];
		$info->download_link = $this->get_download_url( $release );
		$info->sections      = array(
			'description' => 'Agency-wide compatibility fixes for CHIROBASIX client sites (WP Rocket LazyLoad embed compatibility, etc.).',
			'changelog'   => nl2br( esc_html( $release['body'] ?? 'See GitHub for details.' ) ),
		);
		$info->tested        = '6.8';
		$info->requires      = '5.6';
		$info->requires_php  = '7.4';

		return $info;
	}

	public function post_install( $response, $hook_extra, $result ) {
		if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->slug ) {
			return $result;
		}

		global $wp_filesystem;

		$plugin_dir = WP_PLUGIN_DIR . '/' . dirname( $this->slug );
		$wp_filesystem->move( $result['destination'], $plugin_dir );
		$result['destination'] = $plugin_dir;

		activate_plugin( $this->slug );

		return $result;
	}

	private function get_download_url( $release ) {
		if ( ! empty( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				if ( 'application/zip' === $asset['content_type'] || str_ends_with( $asset['name'], '.zip' ) ) {
					return $asset['browser_download_url'];
				}
			}
		}

		return $release['zipball_url'];
	}
}
