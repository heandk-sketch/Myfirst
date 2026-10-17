<?php
/**
 * Progressive Web App support (installable on Android).
 *
 * @package EvChargeLog
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves the web app manifest and service worker, and adds the head tags.
 */
class ECL_Pwa {

	/**
	 * Query argument used to request PWA files.
	 *
	 * @var string
	 */
	private const QUERY_ARG = 'ecl_pwa';

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'init', array( $this, 'maybe_serve' ), 1 );
		add_action( 'wp_head', array( $this, 'render_head_tags' ), 2 );
	}

	/**
	 * Gets the URL of a PWA file.
	 *
	 * @param string $type    manifest or sw.
	 * @param int    $post_id Page containing the shortcode.
	 * @return string
	 */
	public static function url( string $type, int $post_id = 0 ): string {
		$args = array( self::QUERY_ARG => $type );
		if ( $post_id > 0 ) {
			$args['ecl_page'] = $post_id;
		}
		return add_query_arg( $args, home_url( '/' ) );
	}

	/**
	 * Gets the scope for a start URL. Falls back to the site root for plain permalinks.
	 *
	 * @param string $start_url Start URL.
	 * @return string
	 */
	public static function scope_for( string $start_url ): string {
		$parts = wp_parse_url( $start_url );
		if ( ! empty( $parts['query'] ) || empty( $parts['path'] ) ) {
			return home_url( '/' );
		}
		return trailingslashit( $start_url );
	}

	/**
	 * Gets a published page that contains the shortcode.
	 *
	 * @param int $post_id Post ID.
	 * @return WP_Post|null
	 */
	private function get_shortcode_page( int $post_id ): ?WP_Post {
		$post = $post_id > 0 ? get_post( $post_id ) : null;
		if (
			! $post instanceof WP_Post ||
			'publish' !== $post->post_status ||
			'' !== $post->post_password ||
			! has_shortcode( $post->post_content, 'ev_charge_log' )
		) {
			return null;
		}
		return $post;
	}

	/**
	 * Adds manifest and mobile web app tags on pages with the shortcode.
	 *
	 * @return void
	 */
	public function render_head_tags(): void {
		$post = get_post();
		if ( ! is_singular() || ! $post instanceof WP_Post || null === $this->get_shortcode_page( $post->ID ) ) {
			return;
		}

		echo '<link rel="manifest" href="' . esc_url( self::url( 'manifest', $post->ID ) ) . '" />' . "\n";
		echo '<meta name="theme-color" content="#1a7f37" />' . "\n";
		echo '<meta name="mobile-web-app-capable" content="yes" />' . "\n";
		echo '<link rel="apple-touch-icon" href="' . esc_url( ECL_PLUGIN_URL . 'assets/images/icon-192.png' ) . '" />' . "\n";

		$permalink = get_permalink( $post );
		if ( is_string( $permalink ) && '' !== $permalink ) {
			$sw_url = esc_url_raw( self::url( 'sw' ) );
			$scope  = esc_url_raw( self::scope_for( $permalink ) );
			echo '<script>if("serviceWorker"in navigator){window.addEventListener("load",function(){navigator.serviceWorker.register('
				. wp_json_encode( $sw_url ) . ',{scope:' . wp_json_encode( $scope ) . '}).catch(function(){});});}</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-encoded URLs.
		}
	}

	/**
	 * Outputs the manifest or service worker when requested.
	 *
	 * @return void
	 */
	public function maybe_serve(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public, read-only static files.
		$type = isset( $_GET[ self::QUERY_ARG ] ) && is_string( $_GET[ self::QUERY_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::QUERY_ARG ] ) ) : '';
		if ( 'manifest' === $type ) {
			$this->serve_manifest();
		} elseif ( 'sw' === $type ) {
			$this->serve_service_worker();
		}
	}

	/**
	 * Outputs the web app manifest.
	 *
	 * @return void
	 */
	private function serve_manifest(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public, read-only static files.
		$post_id = isset( $_GET['ecl_page'] ) ? absint( wp_unslash( $_GET['ecl_page'] ) ) : 0;
		$post    = $this->get_shortcode_page( $post_id );
		if ( null === $post ) {
			status_header( 404 );
			exit;
		}

		$start_url = get_permalink( $post );
		if ( ! is_string( $start_url ) || '' === $start_url ) {
			status_header( 404 );
			exit;
		}

		$manifest = array(
			'name'             => __( 'EV Charge Log', 'ev-charge-log' ),
			'short_name'       => __( 'EV Log', 'ev-charge-log' ),
			'description'      => __( 'Log opladninger og følg batterikapaciteten.', 'ev-charge-log' ),
			'start_url'        => $start_url,
			'scope'            => self::scope_for( $start_url ),
			'display'          => 'standalone',
			'orientation'      => 'portrait',
			'background_color' => '#ffffff',
			'theme_color'      => '#1a7f37',
			'icons'            => array(
				array(
					'src'     => ECL_PLUGIN_URL . 'assets/images/icon-192.png',
					'sizes'   => '192x192',
					'type'    => 'image/png',
					'purpose' => 'any maskable',
				),
				array(
					'src'     => ECL_PLUGIN_URL . 'assets/images/icon-512.png',
					'sizes'   => '512x512',
					'type'    => 'image/png',
					'purpose' => 'any maskable',
				),
			),
		);

		nocache_headers();
		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		echo wp_json_encode( $manifest ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON.
		exit;
	}

	/**
	 * Outputs the service worker. Pages are never cached, as they may contain private data.
	 *
	 * @return void
	 */
	private function serve_service_worker(): void {
		$offline = '<!doctype html><html lang="da"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width,initial-scale=1">'
			. '<title>' . esc_html__( 'EV Charge Log', 'ev-charge-log' ) . '</title></head>'
			. '<body style="font-family:sans-serif;padding:2rem;text-align:center">'
			. '<h1>' . esc_html__( 'Ingen forbindelse', 'ev-charge-log' ) . '</h1>'
			. '<p>' . esc_html__( 'EV Charge Log kræver internet. Prøv igen, når du har forbindelse.', 'ev-charge-log' ) . '</p>'
			. '</body></html>';

		$script = "self.addEventListener('install', function () { self.skipWaiting(); });\n"
			. "self.addEventListener('activate', function (event) { event.waitUntil(self.clients.claim()); });\n"
			. 'var OFFLINE_HTML = ' . wp_json_encode( $offline ) . ";\n"
			. "self.addEventListener('fetch', function (event) {\n"
			. "\tif (event.request.mode !== 'navigate') { return; }\n"
			. "\tevent.respondWith(fetch(event.request).catch(function () {\n"
			. "\t\treturn new Response(OFFLINE_HTML, { headers: { 'Content-Type': 'text/html; charset=utf-8' } });\n"
			. "\t}));\n"
			. "});\n";

		nocache_headers();
		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Service-Worker-Allowed: /' );
		echo $script; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static script, dynamic parts JSON-encoded.
		exit;
	}
}
