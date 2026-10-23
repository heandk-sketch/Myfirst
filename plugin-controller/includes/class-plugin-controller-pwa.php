<?php
/**
 * Shared Progressive Web App registry.
 *
 * @package PluginController
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lets several plugins be installable as separate apps without their manifests clashing.
 *
 * Each page outputs at most one manifest and one service worker: the one of the app whose
 * shortcode is on the page. Hooks of other PWA plugins are removed on that page.
 */
class PC_Plugin_Controller_Pwa {

	/**
	 * Query argument used to request PWA files.
	 *
	 * @var string
	 */
	private const QUERY_ARG = 'pc_pwa';

	/**
	 * Registered apps keyed by slug.
	 *
	 * @var array<string, array<string, string>>
	 */
	private static array $apps = array();

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'init', array( $this, 'maybe_serve' ), 1 );
		add_action( 'wp', array( $this, 'isolate_current_app' ), 20 );
		add_action( 'wp_head', array( $this, 'render_head_tags' ), 2 );
	}

	/**
	 * Registers an installable app.
	 *
	 * Required: slug, name, shortcode. Optional: short_name, description, theme_color,
	 * background_color, icon_192, icon_512, orientation.
	 *
	 * @param array<string, mixed> $args App configuration.
	 * @return bool Whether the app was registered.
	 */
	public static function register_app( array $args ): bool {
		$slug      = isset( $args['slug'] ) ? sanitize_key( (string) $args['slug'] ) : '';
		$name      = isset( $args['name'] ) ? sanitize_text_field( (string) $args['name'] ) : '';
		$shortcode = isset( $args['shortcode'] ) ? sanitize_key( (string) $args['shortcode'] ) : '';
		if ( '' === $slug || '' === $name || '' === $shortcode ) {
			return false;
		}

		$color = static function ( $value, string $fallback ): string {
			$clean = sanitize_hex_color( is_string( $value ) ? $value : '' );
			return $clean ? $clean : $fallback;
		};

		self::$apps[ $slug ] = array(
			'slug'             => $slug,
			'name'             => $name,
			'short_name'       => isset( $args['short_name'] ) ? sanitize_text_field( (string) $args['short_name'] ) : $name,
			'description'      => isset( $args['description'] ) ? sanitize_text_field( (string) $args['description'] ) : '',
			'shortcode'        => $shortcode,
			'theme_color'      => $color( $args['theme_color'] ?? '', '#1a7f37' ),
			'background_color' => $color( $args['background_color'] ?? '', '#ffffff' ),
			'icon_192'         => isset( $args['icon_192'] ) ? esc_url_raw( (string) $args['icon_192'] ) : '',
			'icon_512'         => isset( $args['icon_512'] ) ? esc_url_raw( (string) $args['icon_512'] ) : '',
			'orientation'      => isset( $args['orientation'] ) ? sanitize_key( (string) $args['orientation'] ) : 'portrait',
		);
		return true;
	}

	/**
	 * Gets the URL of a PWA file.
	 *
	 * @param string $type    manifest or sw.
	 * @param string $slug    App slug.
	 * @param int    $post_id Page ID.
	 * @return string
	 */
	private function url( string $type, string $slug, int $post_id ): string {
		return add_query_arg(
			array(
				self::QUERY_ARG => $type,
				'pc_app'        => $slug,
				'pc_page'       => $post_id,
			),
			home_url( '/' )
		);
	}

	/**
	 * Gets the scope for a start URL. Falls back to the site root for plain permalinks.
	 *
	 * @param string $start_url Start URL.
	 * @return string
	 */
	private function scope_for( string $start_url ): string {
		$parts = wp_parse_url( $start_url );
		if ( ! empty( $parts['query'] ) || empty( $parts['path'] ) ) {
			return home_url( '/' );
		}
		return trailingslashit( $start_url );
	}

	/**
	 * Finds the registered app whose shortcode is on a published, unprotected page.
	 *
	 * @param int $post_id Post ID.
	 * @return array{app: array<string, string>, post: WP_Post}|null
	 */
	private function find_app_for_post( int $post_id ): ?array {
		$post = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || '' !== $post->post_password ) {
			return null;
		}

		foreach ( self::$apps as $app ) {
			if ( has_shortcode( $post->post_content, $app['shortcode'] ) ) {
				return array(
					'app'  => $app,
					'post' => $post,
				);
			}
		}
		return null;
	}

	/**
	 * Finds the app for the current request.
	 *
	 * @return array{app: array<string, string>, post: WP_Post}|null
	 */
	private function find_current_app(): ?array {
		$post = get_post();
		if ( ! is_singular() || ! $post instanceof WP_Post ) {
			return null;
		}
		return $this->find_app_for_post( $post->ID );
	}

	/**
	 * Removes other plugins' PWA output on a registered app page.
	 *
	 * @return void
	 */
	public function isolate_current_app(): void {
		if ( null === $this->find_current_app() ) {
			return;
		}

		/**
		 * Filters the PWA callbacks removed on app pages, as hook => callbacks.
		 *
		 * @param array<string, array<int, string>> $hooks Hooks and callback names.
		 */
		$hooks = apply_filters(
			'pc_pwa_conflicting_hooks',
			array(
				'wp_head'            => array( 'golfscore_add_pwa_head_tags' ),
				'wp_enqueue_scripts' => array( 'golfscore_enqueue_pwa_assets' ),
			)
		);

		foreach ( $hooks as $hook => $callbacks ) {
			foreach ( (array) $callbacks as $callback ) {
				if ( is_string( $callback ) && function_exists( $callback ) ) {
					remove_action( (string) $hook, $callback );
				}
			}
		}
	}

	/**
	 * Outputs the manifest link and service worker registration for the current app page.
	 *
	 * @return void
	 */
	public function render_head_tags(): void {
		$current = $this->find_current_app();
		if ( null === $current ) {
			return;
		}

		$app       = $current['app'];
		$post      = $current['post'];
		$permalink = get_permalink( $post );

		echo '<link rel="manifest" href="' . esc_url( $this->url( 'manifest', $app['slug'], $post->ID ) ) . '" />' . "\n";
		echo '<meta name="theme-color" content="' . esc_attr( $app['theme_color'] ) . '" />' . "\n";
		echo '<meta name="mobile-web-app-capable" content="yes" />' . "\n";
		echo '<meta name="application-name" content="' . esc_attr( $app['short_name'] ) . '" />' . "\n";
		if ( '' !== $app['icon_192'] ) {
			echo '<link rel="apple-touch-icon" href="' . esc_url( $app['icon_192'] ) . '" />' . "\n";
		}

		if ( ! is_string( $permalink ) || '' === $permalink ) {
			return;
		}

		$sw_url = esc_url_raw( $this->url( 'sw', $app['slug'], $post->ID ) );
		$scope  = esc_url_raw( $this->scope_for( $permalink ) );
		// Unregisters other service workers on this scope first so only this app's worker controls the page.
		echo '<script>if("serviceWorker"in navigator){window.addEventListener("load",function(){'
			. 'var s=' . wp_json_encode( $scope ) . ',w=' . wp_json_encode( $sw_url ) . ';'
			. 'navigator.serviceWorker.getRegistrations().then(function(rs){rs.forEach(function(r){'
			. 'var u=r.active&&r.active.scriptURL?r.active.scriptURL:"";'
			. 'if(u&&u!==w&&r.scope===s&&u.indexOf("pc_pwa=sw")===-1){r.unregister();}});})'
			. '.catch(function(){}).finally(function(){navigator.serviceWorker.register(w,{scope:s}).catch(function(){});});});}</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-encoded URLs.
	}

	/**
	 * Outputs the manifest or service worker when requested.
	 *
	 * @return void
	 */
	public function maybe_serve(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public, read-only static files.
		$type = isset( $_GET[ self::QUERY_ARG ] ) && is_string( $_GET[ self::QUERY_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::QUERY_ARG ] ) ) : '';
		if ( '' === $type ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Public, read-only static files.
		$slug    = isset( $_GET['pc_app'] ) && is_string( $_GET['pc_app'] ) ? sanitize_key( wp_unslash( $_GET['pc_app'] ) ) : '';
		$post_id = isset( $_GET['pc_page'] ) ? absint( wp_unslash( $_GET['pc_page'] ) ) : 0;
		// phpcs:enable

		$found = $this->find_app_for_post( $post_id );
		if ( null === $found || $found['app']['slug'] !== $slug ) {
			status_header( 404 );
			exit;
		}

		if ( 'manifest' === $type ) {
			$this->serve_manifest( $found['app'], $found['post'] );
		} elseif ( 'sw' === $type ) {
			$this->serve_service_worker();
		}
	}

	/**
	 * Outputs the web app manifest.
	 *
	 * @param array<string, string> $app  App configuration.
	 * @param WP_Post               $post Page containing the shortcode.
	 * @return void
	 */
	private function serve_manifest( array $app, WP_Post $post ): void {
		$start_url = get_permalink( $post );
		if ( ! is_string( $start_url ) || '' === $start_url ) {
			status_header( 404 );
			exit;
		}

		$icons = array();
		foreach ( array( '192x192' => 'icon_192', '512x512' => 'icon_512' ) as $size => $key ) {
			if ( '' !== $app[ $key ] ) {
				$icons[] = array(
					'src'     => $app[ $key ],
					'sizes'   => $size,
					'type'    => 'image/png',
					'purpose' => 'any maskable',
				);
			}
		}

		$manifest = array(
			'name'             => $app['name'],
			'short_name'       => $app['short_name'],
			'description'      => $app['description'],
			'id'               => $start_url,
			'start_url'        => $start_url,
			'scope'            => $this->scope_for( $start_url ),
			'display'          => 'standalone',
			'orientation'      => $app['orientation'],
			'background_color' => $app['background_color'],
			'theme_color'      => $app['theme_color'],
			'icons'            => $icons,
		);

		nocache_headers();
		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		echo wp_json_encode( $manifest ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON.
		exit;
	}

	/**
	 * Outputs a minimal service worker. Pages are never cached, as they may contain private data.
	 *
	 * @return void
	 */
	private function serve_service_worker(): void {
		$offline = '<!doctype html><html lang="da"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width,initial-scale=1">'
			. '<title>' . esc_html( get_bloginfo( 'name' ) ) . '</title></head>'
			. '<body style="font-family:sans-serif;padding:2rem;text-align:center">'
			. '<h1>' . esc_html__( 'Ingen forbindelse', 'plugin-controller' ) . '</h1>'
			. '<p>' . esc_html__( 'Appen kræver internet. Prøv igen, når du har forbindelse.', 'plugin-controller' ) . '</p>'
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
