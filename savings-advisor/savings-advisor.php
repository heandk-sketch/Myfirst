<?php
/**
 * Plugin Name:       Savings Advisor
 * Description:       Imports bank account CSV exports, analyses spending and suggests where money can be saved.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * License:           GPL-2.0-or-later
 * Text Domain:       savings-advisor
 *
 * @package SavingsAdvisor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SVA_PLUGIN_VERSION', '1.0.0' );
define( 'SVA_DB_VERSION', '1' );
define( 'SVA_PLUGIN_FILE', __FILE__ );
define( 'SVA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SVA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once SVA_PLUGIN_DIR . 'includes/class-sva-parser.php';
require_once SVA_PLUGIN_DIR . 'includes/class-sva-store.php';
require_once SVA_PLUGIN_DIR . 'includes/class-sva-analyzer.php';
require_once SVA_PLUGIN_DIR . 'includes/class-sva-admin.php';

register_activation_hook( SVA_PLUGIN_FILE, array( 'SVA_Store', 'install' ) );

/**
 * Starts Savings Advisor after Plugin Controller has loaded.
 *
 * @return void
 */
function sva_bootstrap_plugin(): void {
	if ( ! function_exists( 'pc_register_admin_page' ) ) {
		add_action( 'admin_notices', 'sva_controller_missing_notice' );
		return;
	}

	$admin = new SVA_Admin();
	$admin->init();
}
add_action( 'plugins_loaded', 'sva_bootstrap_plugin', 20 );

/**
 * Tells administrators that Plugin Controller is required.
 *
 * @return void
 */
function sva_controller_missing_notice(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<div class="notice notice-error">
		<p><?php esc_html_e( 'Savings Advisor kræver, at Plugin Controller er installeret og aktivt.', 'savings-advisor' ); ?></p>
	</div>
	<?php
}
