<?php
/**
 * Shared local access management and authentication.
 *
 * @package PluginController
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages local user/passcode access for frontend plugin features.
 */
class PC_Plugin_Controller_Access {

	/**
	 * WordPress option containing configured local users.
	 *
	 * @var string
	 */
	private const OPTION_NAME = 'pc_local_access_entries';

	/**
	 * Signed local authentication cookie name.
	 *
	 * @var string
	 */
	private const COOKIE_NAME = 'pc_local_auth';

	/**
	 * Frontend plugins that can be assigned to local users.
	 *
	 * @var array<string, array{title: string, shortcode: string, icon_192: string, icon_512: string}>
	 */
	private static array $plugins = array();

	/**
	 * Registers a plugin so local users can be granted access to it.
	 *
	 * @param string               $slug      Unique plugin key.
	 * @param string               $title     Name shown in the admin and launcher.
	 * @param string               $shortcode Shortcode rendered by the launcher.
	 * @param array<string, string> $icons     Optional icon URLs with 192 and 512 keys.
	 * @return bool Whether the plugin was registered.
	 */
	public static function register_plugin( string $slug, string $title, string $shortcode = '', array $icons = array() ): bool {
		$slug      = sanitize_key( $slug );
		$shortcode = sanitize_key( $shortcode );
		if ( '' === $slug || '' === trim( $title ) ) {
			return false;
		}

		self::$plugins[ $slug ] = array(
			'title'     => sanitize_text_field( $title ),
			'shortcode' => $shortcode,
			'icon_192'  => isset( $icons['192'] ) ? esc_url_raw( $icons['192'] ) : '',
			'icon_512'  => isset( $icons['512'] ) ? esc_url_raw( $icons['512'] ) : '',
		);
		return true;
	}

	/**
	 * Checks whether the logged-in local user may use a registered plugin.
	 *
	 * @param string $slug Plugin key.
	 * @return bool
	 */
	public static function user_can_access( string $slug ): bool {
		$user_id = self::get_authenticated_user_id();
		$entry   = $user_id ? self::get_entry_for_user( $user_id ) : null;

		return null !== $entry && in_array( sanitize_key( $slug ), $entry['plugins'], true );
	}

	/**
	 * Registers access management and frontend authentication hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_admin_page' ), 100 );
		add_action( 'admin_post_pc_save_local_access', array( $this, 'save_settings' ) );
		add_action( 'init', array( $this, 'handle_frontend_request' ), 5 );
		add_shortcode( 'plugin_controller_apps', array( $this, 'render_app_launcher' ) );
		wp_register_style(
			'pc-app-launcher',
			PC_PLUGIN_URL . 'assets/css/plugin-controller-apps.css',
			array(),
			PC_PLUGIN_VERSION
		);
	}

	/**
	 * Renders the shared frontend launcher and a selected plugin.
	 *
	 * @return string
	 */
	public function render_app_launcher(): string {
		wp_enqueue_style( 'pc-app-launcher' );

		if ( 0 === self::get_authenticated_user_id() ) {
			return '<div class="pc-app-shell pc-app-shell--login">' . self::render_login_form() . '</div>';
		}

		$requested_slug = isset( $_GET['pc_app'] ) && is_string( $_GET['pc_app'] )
			? sanitize_key( wp_unslash( $_GET['pc_app'] ) )
			: '';

		if ( '' !== $requested_slug ) {
			return $this->render_selected_app( $requested_slug );
		}

		$available_plugins = array_filter(
			self::$plugins,
			static function ( array $plugin, string $slug ): bool {
				return self::user_can_access( $slug ) && '' !== $plugin['shortcode'];
			},
			ARRAY_FILTER_USE_BOTH
		);

		if ( empty( $available_plugins ) ) {
			return '<div class="pc-app-shell"><p class="pc-app-empty">' . esc_html__( 'Du har ingen tilgængelige apps endnu.', 'plugin-controller' ) . '</p></div>';
		}

		ob_start();
		?>
		<section class="pc-app-shell pc-app-launcher" aria-labelledby="pc-app-launcher-title">
			<div class="pc-app-heading">
				<p class="pc-app-kicker"><?php esc_html_e( 'Plugin Controller', 'plugin-controller' ); ?></p>
				<h2 id="pc-app-launcher-title"><?php esc_html_e( 'Dine apps', 'plugin-controller' ); ?></h2>
				<p><?php esc_html_e( 'Vælg et værktøj for at komme i gang.', 'plugin-controller' ); ?></p>
			</div>
			<ul class="pc-app-grid">
				<?php foreach ( $available_plugins as $slug => $plugin ) : ?>
					<li>
						<a class="pc-app-card" href="<?php echo esc_url( add_query_arg( 'pc_app', $slug ) ); ?>">
							<?php if ( '' !== $plugin['icon_192'] ) : ?>
								<span class="pc-app-card__icon">
									<img
										src="<?php echo esc_url( $plugin['icon_192'] ); ?>"
										<?php if ( '' !== $plugin['icon_512'] ) : ?>
											srcset="<?php echo esc_url( $plugin['icon_192'] ); ?> 192w, <?php echo esc_url( $plugin['icon_512'] ); ?> 512w"
											sizes="64px"
										<?php endif; ?>
										alt=""
										width="64"
										height="64"
										loading="lazy"
									/>
								</span>
							<?php endif; ?>
							<span class="pc-app-card__content">
								<strong><?php echo esc_html( $plugin['title'] ); ?></strong>
								<span><?php esc_html_e( 'Åbn app', 'plugin-controller' ); ?> <span aria-hidden="true">→</span></span>
							</span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Renders one requested app after checking its access grant.
	 *
	 * @param string $slug Registered plugin key.
	 * @return string
	 */
	private function render_selected_app( string $slug ): string {
		if ( ! isset( self::$plugins[ $slug ] ) || ! self::user_can_access( $slug ) ) {
			return '<p>' . esc_html__( 'Du har ikke adgang til denne app.', 'plugin-controller' ) . '</p>';
		}

		$plugin = self::$plugins[ $slug ];
		if ( '' === $plugin['shortcode'] ) {
			return '<p>' . esc_html__( 'Denne app kan ikke åbnes fra launcheren endnu.', 'plugin-controller' ) . '</p>';
		}

		$back_url = remove_query_arg( 'pc_app' );
		return '<div class="pc-app-shell pc-app-shell--selected"><p class="pc-app-back"><a href="' . esc_url( $back_url ) . '">← ' . esc_html__( 'Tilbage til dine apps', 'plugin-controller' ) . '</a></p>' . do_shortcode( '[' . $plugin['shortcode'] . ']' ) . '</div>';
	}

	/**
	 * Registers the local access settings page.
	 *
	 * @return void
	 */
	public function register_admin_page(): void {
		add_submenu_page(
			'plugin-controller',
			__( 'Local Access', 'plugin-controller' ),
			__( 'Local Access', 'plugin-controller' ),
			'manage_options',
			'pc-local-access',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Saves up to four local user/passcode entries.
	 *
	 * @return void
	 */
	public function save_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Du har ikke tilladelse til at ændre adgangsindstillingerne.', 'plugin-controller' ) );
		}
		check_admin_referer( 'pc_save_local_access', 'pc_local_access_nonce' );

		$submitted_users     = isset( $_POST['pc_local_user'] ) && is_array( $_POST['pc_local_user'] ) ? wp_unslash( $_POST['pc_local_user'] ) : array();
		$submitted_passcodes = isset( $_POST['pc_local_passcode'] ) && is_array( $_POST['pc_local_passcode'] ) ? wp_unslash( $_POST['pc_local_passcode'] ) : array();
		$submitted_plugins   = isset( $_POST['pc_local_plugins'] ) && is_array( $_POST['pc_local_plugins'] ) ? wp_unslash( $_POST['pc_local_plugins'] ) : array();
		$entries             = array();
		$seen_users          = array();

		for ( $slot = 1; $slot <= 4; $slot++ ) {
			$user_id = isset( $submitted_users[ $slot ] ) ? absint( $submitted_users[ $slot ] ) : 0;
			if ( 0 === $user_id ) {
				continue;
			}

			if ( isset( $seen_users[ $user_id ] ) || ! get_userdata( $user_id ) ) {
				wp_die( esc_html__( 'Vælg en gyldig, unik bruger for hver adgangsplads.', 'plugin-controller' ) );
			}

			$existing = self::get_entry_for_user( $user_id );
			$passcode = isset( $submitted_passcodes[ $slot ] ) && is_string( $submitted_passcodes[ $slot ] )
				? (string) $submitted_passcodes[ $slot ]
				: '';

			if ( $existing && (int) $existing['user_id'] === $user_id && '' === $passcode ) {
				$passcode_hash = $existing['passcode_hash'];
			} elseif ( '' !== $passcode ) {
				$passcode_hash = wp_hash_password( $passcode );
			} else {
				wp_die( esc_html__( 'Angiv en adgangskode for nye eller ændrede brugere.', 'plugin-controller' ) );
			}

			$chosen = isset( $submitted_plugins[ $slot ] ) && is_array( $submitted_plugins[ $slot ] )
				? array_map( 'sanitize_key', array_filter( $submitted_plugins[ $slot ], 'is_string' ) )
				: array();
			$chosen = array_values( array_intersect( $chosen, array_keys( self::$plugins ) ) );

			// Keep grants for plugins that are currently inactive.
			if ( $existing ) {
				$chosen = array_values( array_unique( array_merge( $chosen, array_diff( $existing['plugins'], array_keys( self::$plugins ) ) ) ) );
			}

			$entries[]             = array(
				'user_id'       => $user_id,
				'passcode_hash' => $passcode_hash,
				'plugins'       => $chosen,
			);
			$seen_users[ $user_id ] = true;
		}

		update_option( self::OPTION_NAME, $entries, false );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'             => 'pc-local-access',
					'pc_access_saved'  => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Renders local user/passcode settings.
	 *
	 * @return void
	 */
	public function render_admin_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Du har ikke tilladelse til at se denne side.', 'plugin-controller' ) );
		}

		$entries = self::get_entries();
		$users   = get_users(
			array(
				'orderby' => 'display_name',
				'order'   => 'ASC',
				'number'  => 500,
				'fields'  => array( 'ID', 'display_name', 'user_login' ),
			)
		);
		$saved   = isset( $_GET['pc_access_saved'] ) && is_string( $_GET['pc_access_saved'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['pc_access_saved'] ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Local Access', 'plugin-controller' ); ?></h1>
			<p><?php esc_html_e( 'Konfigurer op til fire WordPress-brugere, der kan logge ind på frontend-funktioner med en lokal adgangskode. Adgangskoder gemmes som hashes.', 'plugin-controller' ); ?></p>
			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Adgangsindstillingerne blev gemt.', 'plugin-controller' ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="pc_save_local_access" />
				<?php wp_nonce_field( 'pc_save_local_access', 'pc_local_access_nonce' ); ?>
				<table class="form-table" role="presentation">
					<tbody>
						<?php for ( $slot = 1; $slot <= 4; $slot++ ) : ?>
							<?php
							$entry            = $entries[ $slot - 1 ] ?? null;
							$selected_user_id = $entry ? (int) $entry['user_id'] : 0;
							?>
							<tr>
								<th scope="row"><label for="pc-local-user-<?php echo esc_attr( (string) $slot ); ?>"><?php echo esc_html( sprintf( __( 'User %d', 'plugin-controller' ), $slot ) ); ?></label></th>
								<td>
									<select id="pc-local-user-<?php echo esc_attr( (string) $slot ); ?>" name="pc_local_user[<?php echo esc_attr( (string) $slot ); ?>]">
										<option value="0"><?php esc_html_e( '- None -', 'plugin-controller' ); ?></option>
										<?php foreach ( $users as $user ) : ?>
											<option value="<?php echo esc_attr( (string) $user->ID ); ?>" <?php selected( $selected_user_id, (int) $user->ID ); ?>>
												<?php echo esc_html( $user->display_name . ' (' . $user->user_login . ')' ); ?>
											</option>
										<?php endforeach; ?>
									</select>
									<br />
									<label class="screen-reader-text" for="pc-local-passcode-<?php echo esc_attr( (string) $slot ); ?>"><?php esc_html_e( 'Passcode', 'plugin-controller' ); ?></label>
									<input id="pc-local-passcode-<?php echo esc_attr( (string) $slot ); ?>" name="pc_local_passcode[<?php echo esc_attr( (string) $slot ); ?>]" type="password" class="regular-text" autocomplete="new-password" />
									<p class="description"><?php esc_html_e( 'Leave blank to keep the saved passcode for this same user.', 'plugin-controller' ); ?></p>
									<fieldset>
										<legend><strong><?php esc_html_e( 'Plugin access', 'plugin-controller' ); ?></strong></legend>
										<?php if ( empty( self::$plugins ) ) : ?>
											<p class="description"><?php esc_html_e( 'No plugins have registered for local access.', 'plugin-controller' ); ?></p>
										<?php endif; ?>
										<?php foreach ( self::$plugins as $plugin_slug => $plugin ) : ?>
											<label>
												<input type="checkbox" name="pc_local_plugins[<?php echo esc_attr( (string) $slot ); ?>][]" value="<?php echo esc_attr( $plugin_slug ); ?>" <?php checked( $entry && in_array( $plugin_slug, $entry['plugins'], true ) ); ?> />
												<?php echo esc_html( $plugin['title'] ); ?>
											</label><br />
										<?php endforeach; ?>
									</fieldset>
								</td>
							</tr>
						<?php endfor; ?>
					</tbody>
				</table>
				<?php submit_button( __( 'Save local access', 'plugin-controller' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handles frontend local login and logout requests.
	 *
	 * @return void
	 */
	public function handle_frontend_request(): void {
		if (
			! isset( $_SERVER['REQUEST_METHOD'], $_POST['pc_local_action'] ) ||
			'POST' !== $_SERVER['REQUEST_METHOD'] ||
			! is_string( $_POST['pc_local_action'] )
		) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_POST['pc_local_action'] ) );
		if ( ! in_array( $action, array( 'login', 'logout' ), true ) ) {
			return;
		}

		$nonce = isset( $_POST['pc_local_nonce'] ) && is_string( $_POST['pc_local_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['pc_local_nonce'] ) )
			: '';
		if ( ! wp_verify_nonce( $nonce, 'pc_local_' . $action ) ) {
			wp_die( esc_html__( 'Sikkerhedstjekket fejlede. Genindlæs siden, og prøv igen.', 'plugin-controller' ) );
		}

		$return_url = isset( $_POST['pc_return_url'] ) && is_string( $_POST['pc_return_url'] )
			? wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['pc_return_url'] ) ), home_url( '/' ) )
			: home_url( '/' );

		if ( 'logout' === $action ) {
			self::clear_auth_cookie();
			wp_safe_redirect( add_query_arg( 'pc_local_status', 'logged_out', $return_url ) );
			exit;
		}

		$user_id  = isset( $_POST['pc_local_user_id'] ) ? absint( $_POST['pc_local_user_id'] ) : 0;
		$passcode = isset( $_POST['pc_local_passcode'] ) && is_string( $_POST['pc_local_passcode'] )
			? (string) wp_unslash( $_POST['pc_local_passcode'] )
			: '';
		$entry    = self::get_entry_for_user( $user_id );

		if ( ! $entry || '' === $passcode || ! wp_check_password( $passcode, $entry['passcode_hash'] ) ) {
			wp_safe_redirect( add_query_arg( 'pc_local_status', 'invalid', $return_url ) );
			exit;
		}

		self::set_auth_cookie( $user_id );
		wp_safe_redirect( add_query_arg( 'pc_local_status', 'logged_in', $return_url ) );
		exit;
	}

	/**
	 * Gets the authenticated local user's WordPress user ID.
	 *
	 * @return int Zero when no valid local login is active.
	 */
	public static function get_authenticated_user_id(): int {
		if ( empty( $_COOKIE[ self::COOKIE_NAME ] ) || ! is_string( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			return 0;
		}

		$parts = explode( '|', sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) ) );
		if ( 3 !== count( $parts ) ) {
			return 0;
		}

		$user_id   = absint( $parts[0] );
		$expires   = absint( $parts[1] );
		$signature = $parts[2];
		if ( 0 === $user_id || $expires <= time() ) {
			return 0;
		}

		$payload           = $user_id . '|' . $expires;
		$expected_signature = hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
		if ( ! hash_equals( $expected_signature, $signature ) || ! self::get_entry_for_user( $user_id ) ) {
			return 0;
		}

		return get_userdata( $user_id ) ? $user_id : 0;
	}

	/**
	 * Renders the shared local login form.
	 *
	 * @return string Login form markup.
	 */
	public static function render_login_form(): string {
		$entries     = self::get_entries();
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] )
			? wp_unslash( $_SERVER['REQUEST_URI'] )
			: '/';
		$current_url = wp_validate_redirect( home_url( $request_uri ), home_url( '/' ) );
		$status      = isset( $_GET['pc_local_status'] ) && is_string( $_GET['pc_local_status'] )
			? sanitize_key( wp_unslash( $_GET['pc_local_status'] ) )
			: '';

		ob_start();
		?>
		<div class="pc-local-access">
			<h2><?php esc_html_e( 'Log in', 'plugin-controller' ); ?></h2>
			<?php if ( 'invalid' === $status ) : ?>
				<p role="alert"><?php esc_html_e( 'Invalid username or passcode.', 'plugin-controller' ); ?></p>
			<?php elseif ( 'logged_out' === $status ) : ?>
				<p><?php esc_html_e( 'You have been logged out.', 'plugin-controller' ); ?></p>
			<?php endif; ?>
			<?php if ( empty( $entries ) ) : ?>
				<p><?php esc_html_e( 'Local access has not been configured. Ask an administrator to configure it in Plugin Controller.', 'plugin-controller' ); ?></p>
			<?php else : ?>
				<form method="post">
					<?php wp_nonce_field( 'pc_local_login', 'pc_local_nonce' ); ?>
					<input type="hidden" name="pc_local_action" value="login" />
					<input type="hidden" name="pc_return_url" value="<?php echo esc_url( $current_url ); ?>" />
					<p>
						<label for="pc-local-user-id"><?php esc_html_e( 'User', 'plugin-controller' ); ?></label><br />
						<select id="pc-local-user-id" name="pc_local_user_id" required>
							<option value=""><?php esc_html_e( 'Select a user', 'plugin-controller' ); ?></option>
							<?php foreach ( $entries as $entry ) : ?>
								<?php $user = get_userdata( (int) $entry['user_id'] ); ?>
								<?php if ( $user ) : ?>
									<option value="<?php echo esc_attr( (string) $user->ID ); ?>"><?php echo esc_html( $user->display_name ); ?></option>
								<?php endif; ?>
							<?php endforeach; ?>
						</select>
					</p>
					<p>
						<label for="pc-local-passcode"><?php esc_html_e( 'Passcode', 'plugin-controller' ); ?></label><br />
						<input type="password" id="pc-local-passcode" name="pc_local_passcode" autocomplete="current-password" required />
					</p>
					<button type="submit"><?php esc_html_e( 'Log in', 'plugin-controller' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Returns valid configured access entries.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function get_entries(): array {
		$raw     = get_option( self::OPTION_NAME, array() );
		$entries = array();
		if ( ! is_array( $raw ) ) {
			return $entries;
		}

		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$user_id       = isset( $entry['user_id'] ) ? absint( $entry['user_id'] ) : 0;
			$passcode_hash = isset( $entry['passcode_hash'] ) && is_string( $entry['passcode_hash'] ) ? $entry['passcode_hash'] : '';
			if ( $user_id && '' !== $passcode_hash && get_userdata( $user_id ) ) {
				$plugins   = isset( $entry['plugins'] ) && is_array( $entry['plugins'] )
					? array_values( array_unique( array_map( 'sanitize_key', array_filter( $entry['plugins'], 'is_string' ) ) ) )
					: array();
				$entries[] = array(
					'user_id'       => $user_id,
					'passcode_hash' => $passcode_hash,
					'plugins'       => $plugins,
				);
			}
		}

		return array_values( $entries );
	}

	/**
	 * Finds a configured local access entry by user ID.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array<string, mixed>|null
	 */
	private static function get_entry_for_user( int $user_id ): ?array {
		foreach ( self::get_entries() as $entry ) {
			if ( $user_id === (int) $entry['user_id'] ) {
				return $entry;
			}
		}
		return null;
	}

	/**
	 * Sets the signed local authentication cookie.
	 *
	 * @param int $user_id Authenticated WordPress user ID.
	 * @return void
	 */
	private static function set_auth_cookie( int $user_id ): void {
		$expires   = time() + ( 14 * DAY_IN_SECONDS );
		$payload   = $user_id . '|' . $expires;
		$signature = hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
		$value     = $payload . '|' . $signature;
		$path      = defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/';

		setcookie(
			self::COOKIE_NAME,
			$value,
			array(
				'expires'  => $expires,
				'path'     => $path,
				'domain'   => defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
		$_COOKIE[ self::COOKIE_NAME ] = $value;
	}

	/**
	 * Clears the local authentication cookie.
	 *
	 * @return void
	 */
	private static function clear_auth_cookie(): void {
		$path = defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/';
		setcookie(
			self::COOKIE_NAME,
			'',
			array(
				'expires'  => time() - HOUR_IN_SECONDS,
				'path'     => $path,
				'domain'   => defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
		unset( $_COOKIE[ self::COOKIE_NAME ] );
	}
}
