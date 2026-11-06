<?php
/**
 * Shared administrator test suites.
 *
 * @package PluginControllerTest
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCT_Tests {
	/** @var array<string, array{title: string, description: string, callback: callable, tests: array<int, string>}> */
	private static array $suites = array();

	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_page' ), 100 );
		add_action( 'admin_post_pct_run_test_suite', array( $this, 'run_suite' ) );
	}

	/**
	 * @param array<string, mixed> $args Suite registration.
	 * @return bool
	 */
	public static function register_suite( array $args ): bool {
		if ( ! isset( $args['slug'], $args['title'], $args['callback'] ) ||
			! is_string( $args['slug'] ) || ! is_string( $args['title'] ) ||
			! is_callable( $args['callback'] ) ||
			( isset( $args['description'] ) && ! is_string( $args['description'] ) ) ||
			( isset( $args['tests'] ) && ! is_array( $args['tests'] ) ) ) {
			_doing_it_wrong( __FUNCTION__, esc_html__( 'Invalid test suite registration.', 'plugin-controller-test' ), '1.0.0' );
			return false;
		}

		$slug  = sanitize_key( $args['slug'] );
		$title = sanitize_text_field( $args['title'] );
		if ( '' === $slug || strlen( $slug ) > 150 || '' === $title || isset( self::$suites[ $slug ] ) ) {
			_doing_it_wrong( __FUNCTION__, esc_html__( 'Test suite slug or title is invalid, or the slug is already registered.', 'plugin-controller-test' ), '1.0.0' );
			return false;
		}

		$tests = array();
		foreach ( $args['tests'] ?? array() as $label ) {
			if ( ! is_string( $label ) || '' === trim( $label ) || in_array( $label, $tests, true ) ) {
				_doing_it_wrong( __FUNCTION__, esc_html__( 'Test labels must be unique non-empty strings.', 'plugin-controller-test' ), '1.0.0' );
				return false;
			}
			$tests[] = $label;
		}

		self::$suites[ $slug ] = array(
			'title'       => $title,
			'description' => sanitize_text_field( $args['description'] ?? '' ),
			'callback'    => $args['callback'],
			'tests'       => $tests,
		);
		return true;
	}

	public function register_page(): void {
		add_submenu_page(
			function_exists( 'pc_register_admin_page' ) ? 'plugin-controller' : 'tools.php',
			__( 'Plugin Tests', 'plugin-controller-test' ),
			__( 'Plugin Tests', 'plugin-controller-test' ),
			'manage_options',
			'plugin-controller-tests',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Run one suite and save its most recent results.
	 */
	public function run_suite(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to run tests.', 'plugin-controller-test' ), '', array( 'response' => 403 ) );
		}

		$slug = isset( $_POST['suite'] ) && is_string( $_POST['suite'] ) ? sanitize_key( wp_unslash( $_POST['suite'] ) ) : '';
		check_admin_referer( 'pct_run_test_suite_' . $slug );
		if ( ! isset( self::$suites[ $slug ] ) ) {
			wp_die( esc_html__( 'This test suite is not registered.', 'plugin-controller-test' ), '', array( 'response' => 400 ) );
		}

		try {
			$results = call_user_func( self::$suites[ $slug ]['callback'] );
			if ( is_wp_error( $results ) ) {
				$this->redirect_with_error( $slug, $results->get_error_message() );
				return;
			}
			if ( ! is_array( $results ) || empty( $results ) ) {
				throw new UnexpectedValueException( 'The test suite returned no results.' );
			}
			foreach ( $results as $result ) {
				if ( ! is_array( $result ) || ! isset( $result['label'], $result['passed'], $result['detail'] ) ||
					! is_string( $result['label'] ) || '' === trim( $result['label'] ) ||
					! is_bool( $result['passed'] ) || ! is_string( $result['detail'] ) ) {
					throw new UnexpectedValueException( 'The test suite returned an invalid result.' );
				}
			}
		} catch ( Throwable $exception ) {
			$results = array(
				array(
					'label'  => __( 'Run test suite', 'plugin-controller-test' ),
					'passed' => false,
					'detail' => $exception->getMessage(),
				),
			);
		}

		$saved = array(
			'time'    => time(),
			'results' => array_values( $results ),
		);
		$option = 'pct_test_results_' . $slug;
		update_option( $option, $saved, false );
		if ( get_option( $option ) !== $saved ) {
			$this->redirect_with_error( $slug, __( 'The test results could not be saved.', 'plugin-controller-test' ) );
			return;
		}

		wp_safe_redirect( $this->page_url( $slug ) );
		exit;
	}

	private function page_url( string $slug ): string {
		return add_query_arg(
			array(
				'page'  => 'plugin-controller-tests',
				'suite' => $slug,
			),
			admin_url( function_exists( 'pc_register_admin_page' ) ? 'admin.php' : 'tools.php' )
		);
	}

	private function redirect_with_error( string $slug, string $message ): void {
		$key = 'pct_test_error_' . get_current_user_id();
		if ( ! set_transient( $key, $message, MINUTE_IN_SECONDS ) ) {
			wp_die( esc_html( $message ) );
		}
		wp_safe_redirect( $this->page_url( $slug ) );
		exit;
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to view tests.', 'plugin-controller-test' ), '', array( 'response' => 403 ) );
		}

		$selected = isset( $_GET['suite'] ) && is_string( $_GET['suite'] ) ? sanitize_key( wp_unslash( $_GET['suite'] ) ) : '';
		$error_key = 'pct_test_error_' . get_current_user_id();
		$error     = get_transient( $error_key );
		delete_transient( $error_key );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Plugin Tests', 'plugin-controller-test' ); ?></h1>
			<p><?php esc_html_e( 'Expand a plugin to run its tests and view the most recent results. Results reflect the last completed run, not the current health of the plugin.', 'plugin-controller-test' ); ?></p>
			<?php if ( is_string( $error ) && '' !== $error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endif; ?>
			<?php if ( empty( self::$suites ) ) : ?>
				<p><?php esc_html_e( 'No active plugins have registered test suites.', 'plugin-controller-test' ); ?></p>
			<?php endif; ?>
			<?php foreach ( self::$suites as $slug => $suite ) : ?>
				<?php
				$saved   = get_option( 'pct_test_results_' . $slug );
				$results = is_array( $saved ) && isset( $saved['results'] ) && is_array( $saved['results'] ) && ! empty( $saved['results'] ) ? $saved['results'] : array();
				$failed  = array_filter(
					$results,
					static function ( $result ): bool {
						return ! is_array( $result ) || ! isset( $result['passed'] ) || true !== $result['passed'];
					}
				);
				$display_results = array();
				foreach ( $suite['tests'] as $label ) {
					$display_results[ $label ] = array( 'label' => $label, 'passed' => null, 'detail' => '' );
				}
				foreach ( $results as $result ) {
					if ( is_array( $result ) && isset( $result['label'], $result['passed'], $result['detail'] ) &&
						is_string( $result['label'] ) && is_bool( $result['passed'] ) && is_string( $result['detail'] ) ) {
						$display_results[ $result['label'] ] = $result;
					}
				}
				$not_run = array_filter(
					$display_results,
					static function ( array $result ): bool {
						return null === $result['passed'];
					}
				);
				if ( empty( $results ) ) {
					$status = __( 'Not run', 'plugin-controller-test' );
				} elseif ( ! empty( $failed ) ) {
					$status = __( 'Failed', 'plugin-controller-test' );
				} elseif ( ! empty( $not_run ) ) {
					$status = __( 'Incomplete', 'plugin-controller-test' );
				} else {
					$status = __( 'Passed', 'plugin-controller-test' );
				}
				?>
				<details <?php echo $slug === $selected ? 'open' : ''; ?> style="margin: 1em 0; padding: 1em; background: #fff; border: 1px solid #c3c4c7;">
					<summary><strong><?php echo esc_html( $suite['title'] ); ?></strong> &mdash; <?php echo esc_html( $status ); ?></summary>
					<?php if ( '' !== $suite['description'] ) : ?>
						<p><?php echo esc_html( $suite['description'] ); ?></p>
					<?php endif; ?>
					<?php if ( ! empty( $results ) && isset( $saved['time'] ) && is_int( $saved['time'] ) ) : ?>
						<p><?php echo esc_html( sprintf( __( 'Last run: %s', 'plugin-controller-test' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $saved['time'] ) ) ); ?></p>
					<?php endif; ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="pct_run_test_suite" />
						<input type="hidden" name="suite" value="<?php echo esc_attr( $slug ); ?>" />
						<?php wp_nonce_field( 'pct_run_test_suite_' . $slug ); ?>
						<button class="button button-primary" type="submit"><?php esc_html_e( 'Run tests', 'plugin-controller-test' ); ?></button>
					</form>
					<?php if ( ! empty( $display_results ) ) : ?>
						<table class="widefat striped" style="margin-top: 1em">
							<thead><tr><th><?php esc_html_e( 'Check', 'plugin-controller-test' ); ?></th><th><?php esc_html_e( 'Result', 'plugin-controller-test' ); ?></th><th><?php esc_html_e( 'Details', 'plugin-controller-test' ); ?></th></tr></thead>
							<tbody>
								<?php foreach ( $display_results as $result ) : ?>
									<tr>
										<td><?php echo esc_html( $result['label'] ); ?></td>
										<td><?php echo esc_html( null === $result['passed'] ? __( 'Not run', 'plugin-controller-test' ) : ( $result['passed'] ? __( 'PASS', 'plugin-controller-test' ) : __( 'FAIL', 'plugin-controller-test' ) ) ); ?></td>
										<td><?php echo esc_html( $result['detail'] ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</details>
			<?php endforeach; ?>
		</div>
		<?php
	}
}
