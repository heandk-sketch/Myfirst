<?php
/**
 * Plugin Name:       Julekalender Quiz
 * Description:       En enkel daglig julequiz med 24 spørgsmål og svarfeedback.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * License:           GPL-2.0-or-later
 * Text Domain:       julekalender-quiz
 *
 * @package JulekalenderQuiz
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'JKQ_PLUGIN_FILE', __FILE__ );
define( 'JKQ_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'JKQ_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once JKQ_PLUGIN_DIR . 'includes/class-jkq-quiz.php';

/**
 * Starts the quiz after Plugin Controller has loaded.
 *
 * @return void
 */
function jkq_bootstrap_plugin(): void {
	if ( ! function_exists( 'pc_register_settings_page' ) || ! function_exists( 'pc_register_access_plugin' ) ) {
		add_action( 'admin_notices', 'jkq_controller_missing_notice' );
		return;
	}

	$quiz = new JKQ_Quiz();
	$quiz->init();

	if ( function_exists( 'pc_register_pwa' ) ) {
		pc_register_pwa(
			array(
				'slug'             => 'julekalender-quiz',
				'name'             => __( 'Julekalender Quiz', 'julekalender-quiz' ),
				'short_name'       => __( 'Julequiz', 'julekalender-quiz' ),
				'description'      => __( 'Svar på dagens julespørgsmål og følg stillingen.', 'julekalender-quiz' ),
				'shortcode'        => 'julekalender_quiz',
				'theme_color'      => '#b5232b',
				'background_color' => '#fffaf0',
				'icon_192'         => JKQ_PLUGIN_URL . 'assets/images/icon-192.png',
				'icon_512'         => JKQ_PLUGIN_URL . 'assets/images/icon-512.png',
				'scope'            => home_url( '/' ),
			)
		);
	}
}
add_action( 'plugins_loaded', 'jkq_bootstrap_plugin', 20 );

/**
 * Tells administrators that Plugin Controller is required.
 *
 * @return void
 */
function jkq_controller_missing_notice(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<div class="notice notice-error">
		<p><?php esc_html_e( 'Julekalender Quiz kræver, at Plugin Controller er installeret og aktivt.', 'julekalender-quiz' ); ?></p>
	</div>
	<?php
}
