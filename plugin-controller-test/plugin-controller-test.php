<?php
/**
 * Plugin Name:       Plugin Controller Test
 * Description:       Optional administrator dashboard for plugin functional tests.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * License:           GPL-2.0-or-later
 * Text Domain:       plugin-controller-test
 *
 * @package PluginControllerTest
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-pct-tests.php';

/**
 * @param array<string, mixed> $args Suite registration.
 * @return bool Whether the suite was registered.
 */
function pct_register_test_suite( array $args ): bool {
	return PCT_Tests::register_suite( $args );
}

function pct_bootstrap_plugin(): void {
	$tests = new PCT_Tests();
	$tests->init();

	if ( ! function_exists( 'golfscore_session_shortcode' ) ) {
		return;
	}

	require_once plugin_dir_path( __FILE__ ) . 'includes/golfscore-tests.php';
	pct_register_test_suite(
		array(
			'slug'        => 'golfscore',
			'title'       => __( 'GolfScore', 'plugin-controller-test' ),
			'description' => __( 'Runs functional checks with temporary records. Browser interaction, OCR, and PWA installation are not tested. If Plugin Controller is active, sign in through the app launcher with GolfScore access before running.', 'plugin-controller-test' ),
			'callback'    => 'pct_golfscore_run_registered_tests',
			'tests'       => array(
				'Check shortcodes and AJAX hooks',
				'Create session',
				'Add player',
				'Save and update score',
				'Poll session',
				'Advance hole',
				'Remove player',
				'End session',
				'Clean up temporary records',
			),
		)
	);
}
add_action( 'plugins_loaded', 'pct_bootstrap_plugin', 20 );
