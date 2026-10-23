<?php
/**
 * Plugin Name:       Plugin Controller
 * Description:       Fælles admin-menu og indstillings-API til WordPress-plugins.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Plugin Controller
 * License:           GPL-2.0-or-later
 * Text Domain:       plugin-controller
 * Domain Path:       /languages
 *
 * @package PluginController
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PC_PLUGIN_VERSION', '1.0.0' );
define( 'PC_PLUGIN_FILE', __FILE__ );
define( 'PC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once PC_PLUGIN_DIR . 'includes/class-plugin-controller.php';
require_once PC_PLUGIN_DIR . 'includes/class-plugin-controller-access.php';
require_once PC_PLUGIN_DIR . 'includes/class-plugin-controller-pwa.php';

/**
 * Registers an app that can be installed on a phone, without clashing with other apps.
 *
 * Required arguments: slug, name, shortcode. Optional: short_name, description,
 * theme_color, background_color, icon_192, icon_512, orientation.
 *
 * @param array<string, mixed> $args App configuration.
 * @return bool Whether the app was registered.
 */
function pc_register_pwa( array $args ): bool {
	return PC_Plugin_Controller_Pwa::register_app( $args );
}

/**
 * Runs when the plugin is activated.
 *
 * @return void
 */
function pc_activate_plugin() {
	set_transient( 'pc_activation_notice', true, 5 * MINUTE_IN_SECONDS );
}
register_activation_hook( PC_PLUGIN_FILE, 'pc_activate_plugin' );

/**
 * Runs when the plugin is deactivated.
 *
 * @return void
 */
function pc_deactivate_plugin() {
	delete_transient( 'pc_activation_notice' );
}
register_deactivation_hook( PC_PLUGIN_FILE, 'pc_deactivate_plugin' );

/**
 * Starts the plugin.
 *
 * @return void
 */
function pc_bootstrap_plugin() {
	$plugin = new PC_Plugin_Controller();
	$plugin->init();

	$access = new PC_Plugin_Controller_Access();
	$access->init();

	$pwa = new PC_Plugin_Controller_Pwa();
	$pwa->init();
}
add_action( 'plugins_loaded', 'pc_bootstrap_plugin' );

/**
 * Registers a plugin admin page under the shared Plugin Controller menu.
 *
 * Required arguments: slug, title, callback. Optional arguments: capability.
 *
 * @param array<string, mixed> $args Admin page configuration.
 * @return bool Whether the page was registered.
 */
function pc_register_admin_page( array $args ): bool {
	return PC_Plugin_Controller::register_admin_page( $args );
}

/**
 * Registers a settings page under the shared Plugin Controller menu.
 *
 * Required arguments: slug, title, option_name, fields. Optional argument: capability.
 * Supported field types are text, textarea, checkbox, and select.
 *
 * @param array<string, mixed> $args Settings page configuration.
 * @return bool Whether the page was registered.
 */
function pc_register_settings_page( array $args ): bool {
	return PC_Plugin_Controller::register_settings_page( $args );
}

/**
 * Gets the authenticated local user's WordPress user ID.
 *
 * @return int Zero when no valid local login is active.
 */
function pc_get_local_access_user_id(): int {
	return PC_Plugin_Controller_Access::get_authenticated_user_id();
}

/**
 * Renders the shared local login form for a frontend plugin.
 *
 * @return string Login form markup.
 */
function pc_render_local_access_form(): string {
	return PC_Plugin_Controller_Access::render_login_form();
}

/**
 * Registers a plugin that local users can be granted access to.
 *
 * @param string $slug  Unique plugin key.
 * @param string $title Name shown on the Local Access page.
 * @return bool Whether the plugin was registered.
 */
function pc_register_access_plugin( string $slug, string $title ): bool {
	return PC_Plugin_Controller_Access::register_plugin( $slug, $title );
}

/**
 * Checks whether the logged-in local user has access to a registered plugin.
 *
 * @param string $slug Plugin key.
 * @return bool
 */
function pc_local_user_can_access( string $slug ): bool {
	return PC_Plugin_Controller_Access::user_can_access( $slug );
}
