<?php
/**
 * EV Charge Log functionality.
 *
 * @package EvChargeLog
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores charge sessions, calculates battery capacity and renders the frontend.
 */
class ECL_Charge_Log {

	/**
	 * Plugin key used for Plugin Controller access control.
	 *
	 * @var string
	 */
	private const ACCESS_KEY = 'ev-charge-log';

	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	private const PAGE_SLUG = 'ev-charge-log';

	/**
	 * Gets the charge table name.
	 *
	 * @return string
	 */
	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ecl_charges';
	}

	/**
	 * Creates or upgrades the database table.
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE $table (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				local_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				charge_date DATE NOT NULL,
				percent_charged DECIMAL(5,2) NOT NULL,
				kwh_charged DECIMAL(8,2) NOT NULL,
				capacity_kwh DECIMAL(8,2) NOT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY ecl_charge_date_idx (charge_date)
			) $charset;"
		);

		update_option( 'ecl_db_version', ECL_DB_VERSION );
	}

	/**
	 * Registers hooks and the Plugin Controller integrations.
	 *
	 * @return void
	 */
	public function init(): void {
		if ( ECL_DB_VERSION !== get_option( 'ecl_db_version' ) ) {
			self::install();
		}

		pc_register_access_plugin( self::ACCESS_KEY, __( 'EV Charge Log', 'ev-charge-log' ) );
		pc_register_admin_page(
			array(
				'slug'       => self::PAGE_SLUG,
				'title'      => __( 'EV Charge Log', 'ev-charge-log' ),
				'capability' => 'manage_options',
				'callback'   => array( $this, 'render_admin_page' ),
			)
		);

		add_action( 'init', array( $this, 'handle_frontend_submission' ) );
		add_action( 'admin_init', array( $this, 'handle_admin_delete' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_shortcode( 'ev_charge_log', array( $this, 'render_shortcode' ) );
	}

	/**
	 * Registers frontend assets.
	 *
	 * @return void
	 */
	public function register_assets(): void {
		wp_register_style( 'ecl-styles', ECL_PLUGIN_URL . 'assets/css/ecl.css', array(), ECL_PLUGIN_VERSION );
		wp_register_script( 'ecl-script', ECL_PLUGIN_URL . 'assets/js/ecl.js', array(), ECL_PLUGIN_VERSION, true );
	}

	/**
	 * Calculates full battery capacity from a partial charge.
	 *
	 * @param float $percent Percentage charged.
	 * @param float $kwh     kWh charged.
	 * @return float
	 */
	public static function calculate_capacity( float $percent, float $kwh ): float {
		return round( $kwh / ( $percent / 100 ), 2 );
	}

	/**
	 * Saves a submitted charge session.
	 *
	 * @return void
	 */
	public function handle_frontend_submission(): void {
		if (
			! isset( $_SERVER['REQUEST_METHOD'], $_POST['ecl_action'] ) ||
			'POST' !== $_SERVER['REQUEST_METHOD'] ||
			! is_string( $_POST['ecl_action'] ) ||
			'add_charge' !== sanitize_key( wp_unslash( $_POST['ecl_action'] ) )
		) {
			return;
		}

		if ( ! function_exists( 'pc_local_user_can_access' ) || ! pc_local_user_can_access( self::ACCESS_KEY ) ) {
			wp_die( esc_html__( 'Du har ikke adgang til at gemme opladninger.', 'ev-charge-log' ) );
		}

		$nonce = isset( $_POST['ecl_nonce'] ) && is_string( $_POST['ecl_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['ecl_nonce'] ) )
			: '';
		if ( ! wp_verify_nonce( $nonce, 'ecl_add_charge' ) ) {
			wp_die( esc_html__( 'Sikkerhedstjekket fejlede. Genindlæs siden, og prøv igen.', 'ev-charge-log' ) );
		}

		$return_url = isset( $_POST['ecl_return_url'] ) && is_string( $_POST['ecl_return_url'] )
			? wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['ecl_return_url'] ) ), home_url( '/' ) )
			: home_url( '/' );

		$percent = $this->read_number( 'ecl_percent' );
		$kwh     = $this->read_number( 'ecl_kwh' );

		if ( $percent < 1 || $percent > 100 || $kwh <= 0 || $kwh > 300 ) {
			wp_safe_redirect( add_query_arg( 'ecl_status', 'invalid', $return_url ) );
			exit;
		}

		global $wpdb;
		$inserted = $wpdb->insert(
			self::table(),
			array(
				'local_user_id'   => pc_get_local_access_user_id(),
				'charge_date'     => current_time( 'Y-m-d' ),
				'percent_charged' => $percent,
				'kwh_charged'     => $kwh,
				'capacity_kwh'    => self::calculate_capacity( $percent, $kwh ),
				'created_at'      => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%f', '%f', '%f', '%s' )
		);

		wp_safe_redirect( add_query_arg( 'ecl_status', $inserted ? 'saved' : 'error', $return_url ) );
		exit;
	}

	/**
	 * Reads a posted decimal number, accepting a comma as decimal separator.
	 *
	 * @param string $key POST key.
	 * @return float
	 */
	private function read_number( string $key ): float {
		if ( ! isset( $_POST[ $key ] ) || ! is_string( $_POST[ $key ] ) ) {
			return 0.0;
		}

		$value = str_replace( ',', '.', sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) );
		return is_numeric( $value ) ? (float) $value : 0.0;
	}

	/**
	 * Gets charge sessions from the last two years for the graph.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function get_graph_points(): array {
		global $wpdb;
		$table = self::table();
		$since = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -2 years' ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT charge_date, capacity_kwh FROM $table WHERE charge_date >= %s ORDER BY charge_date ASC, id ASC", $since ),
			ARRAY_A
		);

		$points = array();
		foreach ( (array) $rows as $row ) {
			$points[] = array(
				'date'     => (string) $row['charge_date'],
				'capacity' => (float) $row['capacity_kwh'],
			);
		}

		return $points;
	}

	/**
	 * Renders the frontend shortcode.
	 *
	 * @return string
	 */
	public function render_shortcode(): string {
		wp_enqueue_style( 'ecl-styles' );

		if ( ! function_exists( 'pc_get_local_access_user_id' ) || ! function_exists( 'pc_render_local_access_form' ) ) {
			return '<p>' . esc_html__( 'EV Charge Log kræver, at Plugin Controller er aktivt.', 'ev-charge-log' ) . '</p>';
		}

		if ( 0 === pc_get_local_access_user_id() ) {
			return pc_render_local_access_form();
		}

		if ( ! pc_local_user_can_access( self::ACCESS_KEY ) ) {
			return '<p>' . esc_html__( 'Din bruger har ikke adgang til denne funktion.', 'ev-charge-log' ) . '</p>';
		}

		$graph_points = $this->get_graph_points();
		$today        = current_time( 'Y-m-d' );

		wp_enqueue_script( 'ecl-script' );
		wp_localize_script(
			'ecl-script',
			'eclData',
			array(
				'points'       => $graph_points,
				'today'        => $today,
				'tesseractUrl' => 'https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js',
				'i18n'         => array(
					'reading'   => __( 'Læser billedet… (første gang kan tage lidt tid)', 'ev-charge-log' ),
					'done'      => __( 'Tjek de fundne værdier, ret dem om nødvendigt, og gem.', 'ev-charge-log' ),
					'notFound'  => __( 'Kunne ikke finde alle værdier. Udfyld dem manuelt.', 'ev-charge-log' ),
					'failed'    => __( 'Billedet kunne ikke læses. Udfyld værdierne manuelt.', 'ev-charge-log' ),
					'noData'    => __( 'Ingen data endnu.', 'ev-charge-log' ),
					'winter'    => __( 'Vinter', 'ev-charge-log' ),
					'spring'    => __( 'Forår', 'ev-charge-log' ),
					'summer'    => __( 'Sommer', 'ev-charge-log' ),
					'autumn'    => __( 'Efterår', 'ev-charge-log' ),
					'axisLabel' => __( 'Batterikapacitet (kWh)', 'ev-charge-log' ),
				),
			)
		);

		$status = isset( $_GET['ecl_status'] ) && is_string( $_GET['ecl_status'] )
			? sanitize_key( wp_unslash( $_GET['ecl_status'] ) )
			: '';

		$current_url = get_permalink();
		if ( ! is_string( $current_url ) || '' === $current_url ) {
			$current_url = home_url( '/' );
		}

		$recent = $this->get_recent_rows( 10 );

		ob_start();
		?>
		<div class="ecl-wrap">
			<h2><?php esc_html_e( 'EV Charge Log', 'ev-charge-log' ); ?></h2>

			<?php if ( 'saved' === $status ) : ?>
				<p class="ecl-notice" role="status"><?php esc_html_e( 'Opladningen blev gemt.', 'ev-charge-log' ); ?></p>
			<?php elseif ( 'invalid' === $status ) : ?>
				<p class="ecl-notice ecl-error" role="alert"><?php esc_html_e( 'Ugyldige værdier. Procent skal være 1-100 og kWh større end 0.', 'ev-charge-log' ); ?></p>
			<?php elseif ( 'error' === $status ) : ?>
				<p class="ecl-notice ecl-error" role="alert"><?php esc_html_e( 'Opladningen kunne ikke gemmes.', 'ev-charge-log' ); ?></p>
			<?php endif; ?>

			<p>
				<label for="ecl-photo"><?php esc_html_e( 'Tag et billede af ladeskærmen', 'ev-charge-log' ); ?></label><br />
				<input type="file" id="ecl-photo" accept="image/*" capture="environment" />
			</p>
			<p id="ecl-ocr-status" role="status"></p>

			<form method="post" action="<?php echo esc_url( $current_url ); ?>">
				<?php wp_nonce_field( 'ecl_add_charge', 'ecl_nonce' ); ?>
				<input type="hidden" name="ecl_action" value="add_charge" />
				<input type="hidden" name="ecl_return_url" value="<?php echo esc_url( $current_url ); ?>" />
				<p>
					<label for="ecl-percent"><?php esc_html_e( 'Opladet (%)', 'ev-charge-log' ); ?></label><br />
					<input type="text" inputmode="decimal" id="ecl-percent" name="ecl_percent" required />
				</p>
				<p>
					<label for="ecl-kwh"><?php esc_html_e( 'Opladet (kWh)', 'ev-charge-log' ); ?></label><br />
					<input type="text" inputmode="decimal" id="ecl-kwh" name="ecl_kwh" required />
				</p>
				<p id="ecl-capacity-preview"></p>
				<button type="submit"><?php esc_html_e( 'Gem opladning', 'ev-charge-log' ); ?></button>
			</form>

			<h3><?php esc_html_e( 'Batterikapacitet de seneste 2 år', 'ev-charge-log' ); ?></h3>
			<canvas id="ecl-chart" height="320" data-points="<?php echo esc_attr( wp_json_encode( $graph_points ) ); ?>" data-today="<?php echo esc_attr( $today ); ?>" aria-label="<?php esc_attr_e( 'Graf over batterikapacitet', 'ev-charge-log' ); ?>"></canvas>

			<?php if ( ! empty( $recent ) ) : ?>
				<h3><?php esc_html_e( 'Seneste opladninger', 'ev-charge-log' ); ?></h3>
				<table class="ecl-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Dato', 'ev-charge-log' ); ?></th>
							<th><?php esc_html_e( '%', 'ev-charge-log' ); ?></th>
							<th><?php esc_html_e( 'kWh', 'ev-charge-log' ); ?></th>
							<th><?php esc_html_e( 'Kapacitet (kWh)', 'ev-charge-log' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $recent as $row ) : ?>
							<tr>
								<td><?php echo esc_html( (string) $row['charge_date'] ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (float) $row['percent_charged'], 1 ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (float) $row['kwh_charged'], 2 ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (float) $row['capacity_kwh'], 2 ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<form method="post">
				<?php wp_nonce_field( 'pc_local_logout', 'pc_local_nonce' ); ?>
				<input type="hidden" name="pc_local_action" value="logout" />
				<input type="hidden" name="pc_return_url" value="<?php echo esc_url( $current_url ); ?>" />
				<button type="submit"><?php esc_html_e( 'Log ud', 'ev-charge-log' ); ?></button>
			</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Gets the newest saved rows.
	 *
	 * @param int $limit Maximum rows.
	 * @return array<int, array<string, mixed>>
	 */
	private function get_recent_rows( int $limit ): array {
		global $wpdb;
		$table = self::table();

		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT id, charge_date, percent_charged, kwh_charged, capacity_kwh FROM $table ORDER BY charge_date DESC, id DESC LIMIT %d", $limit ),
			ARRAY_A
		);
	}

	/**
	 * Deletes a row from the admin list.
	 *
	 * @return void
	 */
	public function handle_admin_delete(): void {
		if (
			! isset( $_SERVER['REQUEST_METHOD'], $_POST['ecl_admin_action'] ) ||
			'POST' !== $_SERVER['REQUEST_METHOD'] ||
			! is_string( $_POST['ecl_admin_action'] ) ||
			'delete' !== sanitize_key( wp_unslash( $_POST['ecl_admin_action'] ) )
		) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Du har ikke tilladelse til at slette opladninger.', 'ev-charge-log' ) );
		}
		check_admin_referer( 'ecl_delete_charge', 'ecl_delete_nonce' );

		$id = isset( $_POST['ecl_id'] ) ? absint( $_POST['ecl_id'] ) : 0;
		if ( $id > 0 ) {
			global $wpdb;
			$wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
		}

		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'ecl_deleted' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Renders the admin list of saved charges.
	 *
	 * @return void
	 */
	public function render_admin_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Du har ikke tilladelse til at se denne side.', 'ev-charge-log' ) );
		}

		$rows = $this->get_recent_rows( 200 );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'EV Charge Log', 'ev-charge-log' ); ?></h1>
			<p><?php esc_html_e( 'Brug shortcoden [ev_charge_log] på en side. Giv brugere adgang under Plugin Controller → Local Access.', 'ev-charge-log' ); ?></p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Dato', 'ev-charge-log' ); ?></th>
						<th><?php esc_html_e( '%', 'ev-charge-log' ); ?></th>
						<th><?php esc_html_e( 'kWh', 'ev-charge-log' ); ?></th>
						<th><?php esc_html_e( 'Kapacitet (kWh)', 'ev-charge-log' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'Ingen opladninger endnu.', 'ev-charge-log' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( (string) $row['charge_date'] ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (float) $row['percent_charged'], 1 ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (float) $row['kwh_charged'], 2 ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (float) $row['capacity_kwh'], 2 ) ); ?></td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>">
									<?php wp_nonce_field( 'ecl_delete_charge', 'ecl_delete_nonce' ); ?>
									<input type="hidden" name="ecl_admin_action" value="delete" />
									<input type="hidden" name="ecl_id" value="<?php echo esc_attr( (string) $row['id'] ); ?>" />
									<button type="submit" class="button-link-delete"><?php esc_html_e( 'Slet', 'ev-charge-log' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
