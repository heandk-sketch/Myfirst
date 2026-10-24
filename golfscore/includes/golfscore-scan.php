<?php
/**
 * Scoreboard photo scan: free in-browser OCR (Tesseract.js) with a small
 * fallback queue (max 3) for photos that could not be read automatically.
 *
 * @package GolfScore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const GOLFSCORE_SCAN_MAX_PENDING = 3;
const GOLFSCORE_SCAN_MAX_BYTES   = 4194304;
const GOLFSCORE_SCAN_DB_VERSION  = '1';

/**
 * Name of the pending scans table.
 */
function golfscore_scan_table(): string {
	global $wpdb;
	return $wpdb->prefix . 'golf_scans';
}

/**
 * Create the pending scans table when missing.
 */
function golfscore_scan_maybe_install(): void {
	if ( get_option( 'golfscore_scan_db_version' ) === GOLFSCORE_SCAN_DB_VERSION ) {
		return;
	}

	global $wpdb;
	$table = golfscore_scan_table();

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta(
		"CREATE TABLE $table (
		id mediumint(9) NOT NULL AUTO_INCREMENT,
		mime_type varchar(50) NOT NULL,
		image_data longblob NOT NULL,
		created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
		PRIMARY KEY  (id)
	) {$wpdb->get_charset_collate()};"
	);

	update_option( 'golfscore_scan_db_version', GOLFSCORE_SCAN_DB_VERSION );
}
add_action( 'init', 'golfscore_scan_maybe_install' );

/**
 * Number of photos waiting for manual handling.
 */
function golfscore_scan_pending_count(): int {
	global $wpdb;
	$table = golfscore_scan_table();
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

/**
 * Outputs the scan UI on the session list page.
 */
function golfscore_scan_render_ui(): void {
	?>
	<div class="golfscore-scan" id="golfscore-scan">
		<h3><?php esc_html_e( 'Scan scoreboard', 'golfscore' ); ?></h3>
		<input type="file" id="golfscore-scan-file" accept="image/*" capture="environment" />
		<div id="golfscore-scan-status" class="golfscore-scan-status"></div>
		<div id="golfscore-scan-review" style="display:none;"></div>
	</div>
	<?php
}

/**
 * Pass scan settings to the script.
 */
function golfscore_scan_enqueue_assets(): void {
	$file = plugin_dir_path( __DIR__ ) . 'assets/js/golfscore-scan.js';
	if ( ! file_exists( $file ) ) {
		return;
	}

	wp_enqueue_script(
		'golfscore-scan-js',
		plugin_dir_url( __DIR__ ) . 'assets/js/golfscore-scan.js',
		array(),
		filemtime( $file ),
		true
	);
	wp_localize_script(
		'golfscore-scan-js',
		'golfscoreScanData',
		array(
			'ajaxurl'      => admin_url( 'admin-ajax.php' ),
			'nonce'        => wp_create_nonce( 'golfscore_nonce' ),
			'tesseractUrl' => 'https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js',
			'maxPending'   => GOLFSCORE_SCAN_MAX_PENDING,
			'pending'      => golfscore_scan_pending_count(),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'golfscore_scan_enqueue_assets' );

/**
 * Save a reviewed scan result as a finished session.
 */
function golfscore_ajax_scan_save_result(): void {
	check_ajax_referer( 'golfscore_nonce', 'nonce' );

	global $wpdb;

	$raw     = isset( $_POST['players'] ) ? wp_unslash( $_POST['players'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Decoded and sanitized below.
	$players = json_decode( is_string( $raw ) ? $raw : '', true );
	if ( ! is_array( $players ) || array() === $players || count( $players ) > 8 ) {
		wp_send_json_error( array( 'message' => 'Invalid scan data.' ) );
	}

	$clean = array();
	$holes = 0;
	foreach ( $players as $player ) {
		$name   = isset( $player['name'] ) ? sanitize_text_field( (string) $player['name'] ) : '';
		$scores = isset( $player['scores'] ) && is_array( $player['scores'] ) ? array_values( $player['scores'] ) : array();
		$count  = count( $scores );
		if ( '' === $name || ( 9 !== $count && 18 !== $count ) ) {
			wp_send_json_error( array( 'message' => 'Each player needs a name and 9 or 18 scores.' ) );
		}
		$holes    = max( $holes, $count );
		$int_list = array();
		foreach ( $scores as $score ) {
			$value = (int) $score;
			if ( $value < 1 || $value > 15 ) {
				wp_send_json_error( array( 'message' => 'Scores must be between 1 and 15.' ) );
			}
			$int_list[] = $value;
		}
		$clean[] = array(
			'name'   => $name,
			'scores' => $int_list,
		);
	}

	$wpdb->insert(
		$wpdb->prefix . 'golf_sessions',
		array(
			'session_name' => sprintf( 'Scan %s', wp_date( 'Y-m-d H:i' ) ),
			'current_hole' => $holes,
			'total_holes'  => $holes,
			'status'       => 'ended',
		),
		array( '%s', '%d', '%d', '%s' )
	);
	$session_id = (int) $wpdb->insert_id;
	if ( ! $session_id ) {
		wp_send_json_error( array( 'message' => 'Could not create session.' ) );
	}

	foreach ( $clean as $player ) {
		$wpdb->insert(
			$wpdb->prefix . 'golf_players',
			array(
				'session_id'  => $session_id,
				'player_name' => $player['name'],
				'is_active'   => 1,
			),
			array( '%d', '%s', '%d' )
		);
		$player_id = (int) $wpdb->insert_id;
		foreach ( $player['scores'] as $index => $score ) {
			$wpdb->insert(
				$wpdb->prefix . 'golf_scores',
				array(
					'session_id' => $session_id,
					'player_id'  => $player_id,
					'hole'       => $index + 1,
					'score'      => $score,
				),
				array( '%d', '%d', '%d', '%d' )
			);
		}
	}

	wp_send_json_success( array( 'message' => 'Scoreboard saved.' ) );
}
add_action( 'wp_ajax_golfscore_scan_save_result', 'golfscore_ajax_scan_save_result' );
add_action( 'wp_ajax_nopriv_golfscore_scan_save_result', 'golfscore_ajax_scan_save_result' );

/**
 * Keep a photo that could not be read, for manual handling later (max 3).
 */
function golfscore_ajax_scan_store_image(): void {
	check_ajax_referer( 'golfscore_nonce', 'nonce' );

	global $wpdb;

	if ( golfscore_scan_pending_count() >= GOLFSCORE_SCAN_MAX_PENDING ) {
		wp_send_json_error( array( 'message' => 'The queue is full (max 3). Handle the saved photos on a PC first.' ) );
	}

	$file = $_FILES['photo'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
	if ( ! is_array( $file ) || ! isset( $file['tmp_name'], $file['size'], $file['error'] ) || UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
		wp_send_json_error( array( 'message' => 'Upload failed.' ) );
	}
	if ( (int) $file['size'] > GOLFSCORE_SCAN_MAX_BYTES ) {
		wp_send_json_error( array( 'message' => 'Image is too large.' ) );
	}

	$info    = getimagesize( $file['tmp_name'] );
	$allowed = array( 'image/jpeg', 'image/png', 'image/webp' );
	if ( false === $info || ! isset( $info['mime'] ) || ! in_array( $info['mime'], $allowed, true ) ) {
		wp_send_json_error( array( 'message' => 'Unsupported image type.' ) );
	}

	$data = file_get_contents( $file['tmp_name'] );
	if ( false === $data ) {
		wp_send_json_error( array( 'message' => 'Could not read image.' ) );
	}

	$wpdb->insert(
		golfscore_scan_table(),
		array(
			'mime_type'  => $info['mime'],
			'image_data' => $data,
		),
		array( '%s', '%s' )
	);

	wp_send_json_success(
		array(
			'message' => 'Could not read the scoreboard. The photo is saved for manual handling.',
			'pending' => golfscore_scan_pending_count(),
		)
	);
}
add_action( 'wp_ajax_golfscore_scan_store_image', 'golfscore_ajax_scan_store_image' );
add_action( 'wp_ajax_nopriv_golfscore_scan_store_image', 'golfscore_ajax_scan_store_image' );

/**
 * Serve a pending photo to administrators.
 */
function golfscore_scan_serve_image(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Not allowed.', 'golfscore' ), '', array( 'response' => 403 ) );
	}
	$id = isset( $_GET['scan_id'] ) ? absint( $_GET['scan_id'] ) : 0;
	check_admin_referer( 'golfscore_scan_image_' . $id );

	global $wpdb;
	$table = golfscore_scan_table();
	$row   = $wpdb->get_row( $wpdb->prepare( "SELECT mime_type, image_data FROM $table WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( ! $row ) {
		wp_die( esc_html__( 'Not found.', 'golfscore' ), '', array( 'response' => 404 ) );
	}

	nocache_headers();
	header( 'Content-Type: ' . $row['mime_type'] );
	header( 'X-Content-Type-Options: nosniff' );
	echo $row['image_data']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Image bytes with a validated MIME type.
	exit;
}
add_action( 'admin_post_golfscore_scan_image', 'golfscore_scan_serve_image' );

/**
 * Delete a pending photo.
 */
function golfscore_scan_delete(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Not allowed.', 'golfscore' ), '', array( 'response' => 403 ) );
	}
	$id = isset( $_GET['scan_id'] ) ? absint( $_GET['scan_id'] ) : 0;
	check_admin_referer( 'golfscore_scan_delete_' . $id );

	global $wpdb;
	$wpdb->delete( golfscore_scan_table(), array( 'id' => $id ), array( '%d' ) );

	wp_safe_redirect( admin_url( 'admin.php?page=golfscore' ) );
	exit;
}
add_action( 'admin_post_golfscore_scan_delete', 'golfscore_scan_delete' );

/**
 * Admin section listing photos waiting for manual handling.
 */
function golfscore_scan_render_admin_section(): void {
	global $wpdb;
	$table = golfscore_scan_table();
	$scans = $wpdb->get_results( "SELECT id, created_at FROM $table ORDER BY created_at ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	?>
	<h2><?php esc_html_e( 'Unread scoreboard photos', 'golfscore' ); ?></h2>
	<?php
	if ( empty( $scans ) ) {
		echo '<p>' . esc_html__( 'No photos waiting.', 'golfscore' ) . '</p>';
		return;
	}
	foreach ( $scans as $scan ) :
		$image_url  = wp_nonce_url( admin_url( 'admin-post.php?action=golfscore_scan_image&scan_id=' . (int) $scan->id ), 'golfscore_scan_image_' . (int) $scan->id );
		$delete_url = wp_nonce_url( admin_url( 'admin-post.php?action=golfscore_scan_delete&scan_id=' . (int) $scan->id ), 'golfscore_scan_delete_' . (int) $scan->id );
		?>
		<div style="display:inline-block;margin:0 16px 16px 0;vertical-align:top;">
			<p><?php echo esc_html( $scan->created_at ); ?></p>
			<a href="<?php echo esc_url( $image_url ); ?>" target="_blank" rel="noopener">
				<img src="<?php echo esc_url( $image_url ); ?>" alt="" style="max-width:360px;height:auto;" />
			</a>
			<p><a href="<?php echo esc_url( $delete_url ); ?>" class="button" onclick="return confirm('Delete this photo?');"><?php esc_html_e( 'Delete (handled)', 'golfscore' ); ?></a></p>
		</div>
		<?php
	endforeach;
}
