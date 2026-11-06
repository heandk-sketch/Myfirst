<?php
/**
 * Administrator-only GolfScore functional checks.
 *
 * @package PluginControllerTest
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCT_Golfscore_Test_Ajax_Exit extends Exception {}

function pct_golfscore_test_die_handler(): void {
	throw new PCT_Golfscore_Test_Ajax_Exit();
}

/**
 * Dispatch the actual WordPress AJAX hooks without making an HTTP loopback request.
 *
 * @param string               $action GolfScore action suffix.
 * @param array<string, mixed> $data Request fields.
 * @return array<string, mixed>
 */
function pct_golfscore_test_ajax( string $action, array $data ): array {
	$old_post    = $_POST;
	$old_request = $_REQUEST;
	$headers     = headers_list();
	$status      = http_response_code();
	$ajax_filter = static function (): bool {
		return true;
	};
	$die_filter  = static function (): string {
		return 'pct_golfscore_test_die_handler';
	};
	$_POST       = $data;
	$_REQUEST    = $data;
	add_filter( 'wp_doing_ajax', $ajax_filter );
	add_filter( 'wp_die_ajax_handler', $die_filter );
	ob_start();

	try {
		try {
			do_action( 'wp_ajax_golfscore_' . $action );
			throw new RuntimeException( 'The AJAX handler did not return a response.' );
		} catch ( PCT_Golfscore_Test_Ajax_Exit $exception ) {
			$output = ob_get_contents();
		}
	} finally {
		ob_end_clean();
		remove_filter( 'wp_doing_ajax', $ajax_filter );
		remove_filter( 'wp_die_ajax_handler', $die_filter );
		$_POST    = $old_post;
		$_REQUEST = $old_request;
		if ( ! headers_sent() ) {
			header_remove( 'Content-Type' );
			foreach ( $headers as $header ) {
				if ( 0 === stripos( $header, 'Content-Type:' ) ) {
					header( $header );
				}
			}
			if ( is_int( $status ) ) {
				http_response_code( $status );
			}
		}
	}

	$response = json_decode( (string) $output, true );
	if ( ! is_array( $response ) || ! isset( $response['success'] ) ) {
		throw new RuntimeException( 'The AJAX handler returned invalid JSON.' );
	}
	return $response;
}

function pct_golfscore_test_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * Run checks against an identifiable temporary session and remove its records.
 *
 * @return array<int, array{label: string, passed: bool, detail: string}>
 */
function pct_golfscore_run_tests(): array {
	global $wpdb;

	$results       = array();
	$name          = 'GolfScore test ' . wp_generate_uuid4();
	$session_id    = 0;
	$player_id     = 0;
	$nonce         = wp_create_nonce( 'golfscore_nonce' );
	$create_nonce  = wp_create_nonce( 'golfscore_create_session' );
	$step          = 'Check shortcodes and AJAX hooks';
	$sessions      = $wpdb->prefix . 'golf_sessions';
	$players       = $wpdb->prefix . 'golf_players';
	$scores        = $wpdb->prefix . 'golf_scores';
	$add_result    = static function ( string $label, bool $passed, string $detail = '' ) use ( &$results ): void {
		$results[] = array( 'label' => $label, 'passed' => $passed, 'detail' => $detail );
	};

	try {
		pct_golfscore_test_assert( shortcode_exists( 'golfscore_session' ), 'The session shortcode is not registered.' );
		foreach ( array( 'create_session', 'add_player', 'save_single_score', 'poll_session', 'advance_hole', 'delete_player', 'end_session' ) as $action ) {
			pct_golfscore_test_assert( false !== has_action( 'wp_ajax_golfscore_' . $action ), 'Missing AJAX hook: ' . $action );
		}
		$add_result( $step, true );

		$step = 'Create session';
		$response = pct_golfscore_test_ajax( 'create_session', array( 'session_name' => $name, 'nonce' => $create_nonce ) );
		$session_id = (int) ( $response['data']['session_id'] ?? 0 );
		pct_golfscore_test_assert( true === $response['success'] && $session_id > 0, 'Session creation failed.' );
		$session = $wpdb->get_row( $wpdb->prepare( "SELECT session_name, current_hole, total_holes, status FROM $sessions WHERE id = %d", $session_id ), ARRAY_A );
		pct_golfscore_test_assert( is_array( $session ) && $session['session_name'] === $name && 1 === (int) $session['current_hole'] && 18 === (int) $session['total_holes'] && 'active' === $session['status'], 'Created session has unexpected data.' );
		$add_result( $step, true );

		$step = 'Add player';
		$response = pct_golfscore_test_ajax( 'add_player', array( 'session_id' => $session_id, 'player_names' => 'Test Player', 'nonce' => $nonce ) );
		$player_id = (int) ( $response['data']['player_ids'][0] ?? 0 );
		$player = $wpdb->get_row( $wpdb->prepare( "SELECT player_name, is_active FROM $players WHERE id = %d AND session_id = %d", $player_id, $session_id ), ARRAY_A );
		pct_golfscore_test_assert( true === $response['success'] && $player_id > 0 && is_array( $player ) && 'Test Player' === $player['player_name'] && 1 === (int) $player['is_active'], 'Player was not saved correctly.' );
		$add_result( $step, true );

		$step = 'Save and update score';
		foreach ( array( 4, 5 ) as $score ) {
			$response = pct_golfscore_test_ajax( 'save_single_score', array( 'session_id' => $session_id, 'player_id' => $player_id, 'hole' => 1, 'score' => $score, 'nonce' => $nonce ) );
			pct_golfscore_test_assert( true === $response['success'], 'Score save failed.' );
		}
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT score FROM $scores WHERE session_id = %d AND player_id = %d AND hole = 1", $session_id, $player_id ), ARRAY_A );
		pct_golfscore_test_assert( 1 === count( $rows ) && 5 === (int) $rows[0]['score'], 'Score update did not replace the original score.' );
		$response = pct_golfscore_test_ajax( 'save_single_score', array( 'session_id' => $session_id, 'player_id' => $player_id, 'hole' => 1, 'score' => 0, 'nonce' => $nonce ) );
		pct_golfscore_test_assert( false === $response['success'], 'Invalid score was accepted.' );
		$add_result( $step, true );

		$step = 'Poll session';
		$response = pct_golfscore_test_ajax( 'poll_session', array( 'session_id' => $session_id, 'hole' => 1, 'nonce' => $nonce ) );
		pct_golfscore_test_assert( true === $response['success'] && 5 === ( $response['data']['hole_scores'][ $player_id ] ?? null ) && 1 === ( $response['data']['current_hole'] ?? null ), 'Polling returned unexpected session data.' );
		$add_result( $step, true );

		$step = 'Advance hole';
		$response = pct_golfscore_test_ajax( 'advance_hole', array( 'session_id' => $session_id, 'hole' => 1, 'nonce' => $nonce ) );
		$current_hole = $wpdb->get_var( $wpdb->prepare( "SELECT current_hole FROM $sessions WHERE id = %d", $session_id ) );
		pct_golfscore_test_assert( true === $response['success'] && 2 === ( $response['data']['next_hole'] ?? null ) && 2 === (int) $current_hole, 'The session did not advance to hole 2.' );
		$add_result( $step, true );

		$step = 'Remove player';
		$response = pct_golfscore_test_ajax( 'delete_player', array( 'session_id' => $session_id, 'player_id' => $player_id, 'nonce' => $nonce ) );
		$active = $wpdb->get_var( $wpdb->prepare( "SELECT is_active FROM $players WHERE id = %d AND session_id = %d", $player_id, $session_id ) );
		pct_golfscore_test_assert( true === $response['success'] && null !== $active && 0 === (int) $active, 'Player was not deactivated.' );
		$add_result( $step, true );

		$step = 'End session';
		$response = pct_golfscore_test_ajax( 'end_session', array( 'session_id' => $session_id, 'nonce' => $nonce ) );
		$status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $sessions WHERE id = %d", $session_id ) );
		pct_golfscore_test_assert( true === $response['success'] && 'ended' === $status, 'The session was not ended.' );
		$add_result( $step, true );
	} catch ( Throwable $exception ) {
		$add_result( $step, false, $exception->getMessage() );
	}

	// Find the uniquely named session even if its handler failed after insertion.
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $sessions WHERE session_name = %s", $name ) );
	$cleanup_errors = array();
	if ( $wpdb->last_error ) {
		$cleanup_errors[] = $wpdb->last_error;
	}
	foreach ( $ids as $id ) {
		foreach ( array( $scores, $players, $sessions ) as $table ) {
			$where = $table === $sessions ? array( 'id' => (int) $id, 'session_name' => $name ) : array( 'session_id' => (int) $id );
			if ( false === $wpdb->delete( $table, $where ) ) {
				$cleanup_errors[] = $wpdb->last_error;
			}
		}
	}
	$add_result( 'Clean up temporary records', empty( $cleanup_errors ), implode( '; ', $cleanup_errors ) );

	return $results;
}

/**
 * Check the local-access prerequisite before running the registered suite.
 *
 * @return array<int, array{label: string, passed: bool, detail: string}>|WP_Error
 */
function pct_golfscore_run_registered_tests() {
	if ( ! golfscore_has_access() ) {
		return new WP_Error( 'golfscore_access', __( 'Sign in through the Plugin Controller app launcher as a local user with GolfScore access, then return to this page.', 'golfscore' ) );
	}

	return pct_golfscore_run_tests();
}
