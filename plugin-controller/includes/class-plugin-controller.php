<?php
/**
 * Main plugin controller.
 *
 * @package PluginController
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides a shared admin menu and settings API for other plugins.
 */
class PC_Plugin_Controller {

	/**
	 * Registered custom admin pages.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $admin_pages = array();

	/**
	 * Registered settings pages.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $settings_pages = array();

	/**
	 * Parent menu slug shared by registered pages.
	 *
	 * @var string
	 */
	private const MENU_SLUG = 'plugin-controller';

	/**
	 * Registers the plugin hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_notices', array( $this, 'display_activation_notice' ) );
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ), 99 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Registers a custom page under the shared admin menu.
	 *
	 * @param array<string, mixed> $args Page configuration.
	 * @return bool Whether the page was registered.
	 */
	public static function register_admin_page( array $args ): bool {
		$required = array( 'slug', 'title', 'callback' );
		foreach ( $required as $key ) {
			if ( empty( $args[ $key ] ) ) {
				_doing_it_wrong( __FUNCTION__, esc_html( 'Missing required admin page argument: ' . $key ), '1.0.0' );
				return false;
			}
		}

		if ( ! is_callable( $args['callback'] ) ) {
			_doing_it_wrong( __FUNCTION__, esc_html__( 'The admin page callback must be callable.', 'plugin-controller' ), '1.0.0' );
			return false;
		}

		$slug = sanitize_key( (string) $args['slug'] );
		if ( '' === $slug || isset( self::$admin_pages[ $slug ] ) || isset( self::$settings_pages[ $slug ] ) ) {
			_doing_it_wrong( __FUNCTION__, esc_html__( 'The admin page slug is empty or already registered.', 'plugin-controller' ), '1.0.0' );
			return false;
		}

		self::$admin_pages[ $slug ] = array(
			'slug'       => $slug,
			'title'      => sanitize_text_field( (string) $args['title'] ),
			'callback'   => $args['callback'],
			'capability' => isset( $args['capability'] ) ? sanitize_text_field( (string) $args['capability'] ) : 'manage_options',
		);

		return true;
	}

	/**
	 * Registers a settings page under the shared admin menu.
	 *
	 * @param array<string, mixed> $args Settings page configuration.
	 * @return bool Whether the page was registered.
	 */
	public static function register_settings_page( array $args ): bool {
		$required = array( 'slug', 'title', 'option_name', 'fields' );
		foreach ( $required as $key ) {
			if ( ! isset( $args[ $key ] ) || ( 'fields' !== $key && '' === $args[ $key ] ) ) {
				_doing_it_wrong( __FUNCTION__, esc_html( 'Missing required settings page argument: ' . $key ), '1.0.0' );
				return false;
			}
		}

		if ( ! is_array( $args['fields'] ) || empty( $args['fields'] ) ) {
			_doing_it_wrong( __FUNCTION__, esc_html__( 'A settings page must provide at least one field.', 'plugin-controller' ), '1.0.0' );
			return false;
		}

		$slug        = sanitize_key( (string) $args['slug'] );
		$option_name = sanitize_key( (string) $args['option_name'] );
		if ( '' === $slug || '' === $option_name || isset( self::$admin_pages[ $slug ] ) || isset( self::$settings_pages[ $slug ] ) ) {
			_doing_it_wrong( __FUNCTION__, esc_html__( 'The settings page slug or option name is invalid, or the slug is already registered.', 'plugin-controller' ), '1.0.0' );
			return false;
		}

		$fields = self::validate_fields( $args['fields'] );
		if ( false === $fields ) {
			_doing_it_wrong( __FUNCTION__, esc_html__( 'The settings page fields are invalid.', 'plugin-controller' ), '1.0.0' );
			return false;
		}

		self::$settings_pages[ $slug ] = array(
			'slug'        => $slug,
			'title'       => sanitize_text_field( (string) $args['title'] ),
			'option_name' => $option_name,
			'capability'  => isset( $args['capability'] ) ? sanitize_text_field( (string) $args['capability'] ) : 'manage_options',
			'fields'      => $fields,
			'group'       => 'pc_' . $slug . '_settings',
		);

		return true;
	}

	/**
	 * Adds the shared menu and all registered plugin pages.
	 *
	 * @return void
	 */
	public function register_admin_menu(): void {
		add_menu_page(
			__( 'Plugin Controller', 'plugin-controller' ),
			__( 'Plugin Controller', 'plugin-controller' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_dashboard' ),
			'dashicons-admin-generic',
			60
		);

		foreach ( self::$admin_pages as $page ) {
			add_submenu_page(
				self::MENU_SLUG,
				$page['title'],
				$page['title'],
				$page['capability'],
				$page['slug'],
				$page['callback']
			);
		}

		foreach ( self::$settings_pages as $page ) {
			add_submenu_page(
				self::MENU_SLUG,
				$page['title'],
				$page['title'],
				$page['capability'],
				$page['slug'],
				function () use ( $page ): void {
					$this->render_settings_page( $page );
				}
			);
		}
	}

	/**
	 * Registers options using WordPress' Settings API.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		foreach ( self::$settings_pages as $page ) {
			add_filter(
				'option_page_capability_' . $page['group'],
				static function () use ( $page ): string {
					return $page['capability'];
				}
			);

			register_setting(
				$page['group'],
				$page['option_name'],
				array(
					'type'              => 'array',
					'sanitize_callback' => function ( $input ) use ( $page ): array {
						return $this->sanitize_settings( $input, $page['fields'] );
					},
					'default'           => array(),
				)
			);
		}
	}

	/**
	 * Displays the shared menu landing page.
	 *
	 * @return void
	 */
	public function render_dashboard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Du har ikke tilladelse til at se denne side.', 'plugin-controller' ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Plugin Controller', 'plugin-controller' ); ?></h1>
			<p><?php esc_html_e( 'Fælles admin-menu og indstillinger for tilsluttede plugins.', 'plugin-controller' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Renders a registered settings form.
	 *
	 * @param array<string, mixed> $page Settings page configuration.
	 * @return void
	 */
	private function render_settings_page( array $page ): void {
		if ( ! current_user_can( $page['capability'] ) ) {
			wp_die( esc_html__( 'Du har ikke tilladelse til at se denne side.', 'plugin-controller' ) );
		}

		$values = get_option( $page['option_name'], array() );
		$values = is_array( $values ) ? $values : array();
		?>
		<div class="wrap">
			<h1><?php echo esc_html( $page['title'] ); ?></h1>
			<form action="options.php" method="post">
				<?php settings_fields( $page['group'] ); ?>
				<table class="form-table" role="presentation">
					<tbody>
						<?php foreach ( $page['fields'] as $field ) : ?>
							<?php $value = $values[ $field['id'] ] ?? ''; ?>
							<tr>
								<th scope="row">
									<label for="<?php echo esc_attr( $page['option_name'] . '_' . $field['id'] ); ?>">
										<?php echo esc_html( $field['label'] ); ?>
									</label>
								</th>
								<td>
									<?php $this->render_field( $page['option_name'], $field, $value ); ?>
									<?php if ( '' !== $field['description'] ) : ?>
										<p class="description"><?php echo esc_html( $field['description'] ); ?></p>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Renders one supported settings field.
	 *
	 * @param string               $option_name Option name.
	 * @param array<string, mixed> $field Field configuration.
	 * @param mixed                $value Current setting value.
	 * @return void
	 */
	private function render_field( string $option_name, array $field, $value ): void {
		$name = $option_name . '[' . $field['id'] . ']';
		$id   = $option_name . '_' . $field['id'];

		if ( 'textarea' === $field['type'] ) {
			printf(
				'<textarea id="%1$s" name="%2$s" rows="5" class="large-text">%3$s</textarea>',
				esc_attr( $id ),
				esc_attr( $name ),
				esc_textarea( (string) $value )
			);
			return;
		}

		if ( 'checkbox' === $field['type'] ) {
			printf(
				'<input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s />',
				esc_attr( $id ),
				esc_attr( $name ),
				checked( $value, true, false )
			);
			return;
		}

		if ( 'select' === $field['type'] ) {
			printf( '<select id="%1$s" name="%2$s">', esc_attr( $id ), esc_attr( $name ) );
			foreach ( $field['options'] as $option_value => $label ) {
				printf(
					'<option value="%1$s" %2$s>%3$s</option>',
					esc_attr( $option_value ),
					selected( $value, $option_value, false ),
					esc_html( $label )
				);
			}
			echo '</select>';
			return;
		}

		printf(
			'<input type="text" id="%1$s" name="%2$s" value="%3$s" class="regular-text" />',
			esc_attr( $id ),
			esc_attr( $name ),
			esc_attr( (string) $value )
		);
	}

	/**
	 * Validates and normalizes supported settings field definitions.
	 *
	 * @param array<int, mixed> $fields Field definitions.
	 * @return array<int, array<string, mixed>>|false
	 */
	private static function validate_fields( array $fields ) {
		$validated = array();
		$seen      = array();
		$types     = array( 'text', 'textarea', 'checkbox', 'select' );

		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) || empty( $field['id'] ) || empty( $field['label'] ) || empty( $field['type'] ) ) {
				return false;
			}

			$id   = sanitize_key( (string) $field['id'] );
			$type = sanitize_key( (string) $field['type'] );
			if ( '' === $id || isset( $seen[ $id ] ) || ! in_array( $type, $types, true ) ) {
				return false;
			}

			$options = array();
			if ( 'select' === $type ) {
				if ( empty( $field['options'] ) || ! is_array( $field['options'] ) ) {
					return false;
				}
				foreach ( $field['options'] as $option_value => $label ) {
					$option_value = sanitize_key( (string) $option_value );
					if ( '' === $option_value || ! is_scalar( $label ) ) {
						return false;
					}
					$options[ $option_value ] = sanitize_text_field( (string) $label );
				}
			}

			$validated[] = array(
				'id'          => $id,
				'label'       => sanitize_text_field( (string) $field['label'] ),
				'type'        => $type,
				'description' => isset( $field['description'] ) ? sanitize_text_field( (string) $field['description'] ) : '',
				'options'     => $options,
			);
			$seen[ $id ] = true;
		}

		return $validated;
	}

	/**
	 * Sanitizes only values declared in a registered settings page.
	 *
	 * @param mixed                $input Submitted option value.
	 * @param array<int, array<string, mixed>> $fields Registered field definitions.
	 * @return array<string, mixed>
	 */
	private function sanitize_settings( $input, array $fields ): array {
		$input     = is_array( $input ) ? $input : array();
		$sanitized = array();

		foreach ( $fields as $field ) {
			$value = $input[ $field['id'] ] ?? '';

			switch ( $field['type'] ) {
				case 'checkbox':
					$sanitized[ $field['id'] ] = in_array( $value, array( true, 1, '1', 'on' ), true );
					break;
				case 'textarea':
					$sanitized[ $field['id'] ] = sanitize_textarea_field( is_scalar( $value ) ? (string) $value : '' );
					break;
				case 'select':
					$value = sanitize_key( is_scalar( $value ) ? (string) $value : '' );
					$sanitized[ $field['id'] ] = isset( $field['options'][ $value ] ) ? $value : '';
					break;
				default:
					$sanitized[ $field['id'] ] = sanitize_text_field( is_scalar( $value ) ? (string) $value : '' );
			}
		}

		return $sanitized;
	}

	/**
	 * Displays a one-time activation notice to administrators.
	 *
	 * @return void
	 */
	public function display_activation_notice(): void {
		if ( ! current_user_can( 'manage_options' ) || ! get_transient( 'pc_activation_notice' ) ) {
			return;
		}

		delete_transient( 'pc_activation_notice' );
		?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'Plugin Controller er aktiveret og klar til tilpasning.', 'plugin-controller' ); ?></p>
		</div>
		<?php
	}
}
