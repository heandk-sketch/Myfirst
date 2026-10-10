<?php
/**
 * Plugin Name:       My First Plugin
 * Plugin URI:        https://example.com/my-first-plugin
 * Description:       Et simpelt boilerplate-plugin med aktiveringshook og en admin-notice.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Dit Navn
 * Author URI:        https://example.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       my-first-plugin
 * Domain Path:       /languages
 *
 * @package MyFirstPlugin
 */

// Forhindr direkte adgang til filen.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Definer nogle grundlæggende konstanter til brug i pluginet.
define( 'MFP_VERSION', '1.0.0' );
define( 'MFP_PLUGIN_FILE', __FILE__ );
define( 'MFP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MFP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Køres når pluginet aktiveres.
 *
 * Her kan man f.eks. oprette databasetabeller, sætte standardindstillinger
 * eller flushe rewrite rules. Vi sætter blot et transient, som bruges
 * til at vise en admin-notice én gang efter aktivering.
 *
 * @return void
 */
function mfp_activate_plugin() {
	// Marker at pluginet lige er blevet aktiveret, så vi kan vise en notice.
	set_transient( 'mfp_activation_notice', true, 5 * MINUTE_IN_SECONDS );
}
register_activation_hook( __FILE__, 'mfp_activate_plugin' );

/**
 * Køres når pluginet deaktiveres.
 *
 * Bruges til oprydning, der skal ske med det samme (modsat afinstallation,
 * som håndteres i en separat uninstall.php).
 *
 * @return void
 */
function mfp_deactivate_plugin() {
	delete_transient( 'mfp_activation_notice' );
}
register_deactivation_hook( __FILE__, 'mfp_deactivate_plugin' );

/**
 * Viser en simpel admin-notice efter aktivering af pluginet.
 *
 * Noticen vises kun én gang, og kun til brugere med rettigheden
 * 'manage_options' (typisk administratorer).
 *
 * @return void
 */
function mfp_admin_activation_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( ! get_transient( 'mfp_activation_notice' ) ) {
		return;
	}

	delete_transient( 'mfp_activation_notice' );
	?>
	<div class="notice notice-success is-dismissible">
		<p>
			<?php
			esc_html_e( 'My First Plugin er nu aktiveret og klar til brug!', 'my-first-plugin' );
			?>
		</p>
	</div>
	<?php
}
add_action( 'admin_notices', 'mfp_admin_activation_notice' );
