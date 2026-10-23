<?php
/**
 * Plugin Name:       EV Charge Log
 * Description:       Reads charged % and kWh from a photo of the charger screen, calculates battery capacity and graphs it by season.
 * Version:           1.2.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * License:           GPL-2.0-or-later
 * Text Domain:       ev-charge-log
 *
 * @package EvChargeLog
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ECL_PLUGIN_VERSION', '1.2.0' );
define( 'ECL_DB_VERSION', '2' );
define( 'ECL_PLUGIN_FILE', __FILE__ );
define( 'ECL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ECL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once ECL_PLUGIN_DIR . 'includes/class-ecl-charge-log.php';
require_once ECL_PLUGIN_DIR . 'includes/class-ecl-pwa.php';

register_activation_hook( ECL_PLUGIN_FILE, array( 'ECL_Charge_Log', 'install' ) );

/**
 * Starts EV Charge Log after Plugin Controller has loaded.
 *
 * @return void
 */
function ecl_bootstrap_plugin(): void {
	if ( ! function_exists( 'pc_register_admin_page' ) || ! function_exists( 'pc_register_access_plugin' ) ) {
		add_action( 'admin_notices', 'ecl_controller_missing_notice' );
		return;
	}

	$plugin = new ECL_Charge_Log();
	$plugin->init();

	if ( function_exists( 'pc_register_pwa' ) ) {
		pc_register_pwa(
			array(
				'slug'        => 'ev-charge-log',
				'name'        => __( 'EV Charge Log', 'ev-charge-log' ),
				'short_name'  => __( 'EV Log', 'ev-charge-log' ),
				'description' => __( 'Log opladninger og følg batterikapaciteten.', 'ev-charge-log' ),
				'shortcode'   => 'ev_charge_log',
				'theme_color' => '#1a7f37',
				'icon_192'    => ECL_PLUGIN_URL . 'assets/images/icon-192.png',
				'icon_512'    => ECL_PLUGIN_URL . 'assets/images/icon-512.png',
			)
		);
		return;
	}

	// Older Plugin Controller without the shared PWA registry.
	$pwa = new ECL_Pwa();
	$pwa->init();
}
add_action( 'plugins_loaded', 'ecl_bootstrap_plugin', 20 );

/**
 * Tells administrators that Plugin Controller is required.
 *
 * @return void
 */
function ecl_controller_missing_notice(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<div class="notice notice-error">
		<p><?php esc_html_e( 'EV Charge Log kræver, at Plugin Controller er installeret og aktivt.', 'ev-charge-log' ); ?></p>
	</div>
	<?php
}
