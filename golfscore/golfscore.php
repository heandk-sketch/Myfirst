<?php
/*
Plugin Name: GolfScore
Plugin URI: https://example.com/golfscore
Description: A simple golf score tracking plugin.
Version: 1.0
Author: Your Name
License: GPL2
*/

if (!defined('ABSPATH')) {
    exit;
}

global $golfscore_db_version;
$golfscore_db_version = '1.0';

/**
 * Create database tables on activation
 */
function golfscore_install() {
    global $wpdb;
    global $golfscore_db_version;

    $charset_collate = $wpdb->get_charset_collate();

    // Sessions table
    $sessions_table = $wpdb->prefix . 'golf_sessions';
    $sql1 = "CREATE TABLE IF NOT EXISTS $sessions_table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        session_name varchar(100) NOT NULL,
        current_hole int DEFAULT 1,
        total_holes int DEFAULT 18,
        status varchar(20) DEFAULT 'active',
        created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    // Players table
    $players_table = $wpdb->prefix . 'golf_players';
    $sql2 = "CREATE TABLE IF NOT EXISTS $players_table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        session_id mediumint(9) NOT NULL,
        player_name varchar(100) NOT NULL,
        is_active int DEFAULT 1,
        created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY  (id),
        FOREIGN KEY (session_id) REFERENCES $sessions_table(id)
    ) $charset_collate;";

    // Scores table
    $scores_table = $wpdb->prefix . 'golf_scores';
    $sql3 = "CREATE TABLE IF NOT EXISTS $scores_table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        session_id mediumint(9) NOT NULL,
        player_id mediumint(9) NOT NULL,
        hole int NOT NULL,
        score int NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY  (id),
        FOREIGN KEY (session_id) REFERENCES $sessions_table(id),
        FOREIGN KEY (player_id) REFERENCES $players_table(id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql1);
    dbDelta($sql2);
    dbDelta($sql3);

    add_option('golfscore_db_version', $golfscore_db_version);
}
register_activation_hook(__FILE__, 'golfscore_install');

/**
 * Ensure DB tables exist at runtime in case activation hook wasn't triggered.
 */
function golfscore_maybe_install() {
    global $wpdb;
    $sessions_table = $wpdb->prefix . 'golf_sessions';

    $table_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $sessions_table ) );

    if ( $table_exists !== $sessions_table ) {
        golfscore_install();
    }
}
add_action( 'init', 'golfscore_maybe_install' );

/**
 * Enqueue styles and scripts
 */
function golfscore_enqueue_assets() {
    $plugin_dir = plugin_dir_path(__FILE__);
    $css_version = filemtime($plugin_dir . 'assets/css/golfscore.css');
    $js_version  = filemtime($plugin_dir . 'assets/js/golfscore.js');
    wp_enqueue_style('golfscore-css', plugin_dir_url(__FILE__) . 'assets/css/golfscore.css', array(), $css_version);
    wp_enqueue_script('golfscore-js', plugin_dir_url(__FILE__) . 'assets/js/golfscore.js', ['jquery'], $js_version, true);
    wp_localize_script('golfscore-js', 'golfscoreData', [
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('golfscore_nonce')
    ]);
}
add_action('wp_enqueue_scripts', 'golfscore_enqueue_assets');

/**
 * Register PWA script and expose service worker URL.
 */
function golfscore_enqueue_pwa_assets() {
    if ( is_admin() ) {
        return;
    }

    $plugin_dir = plugin_dir_path(__FILE__);
    $pwa_js     = $plugin_dir . 'assets/js/golfscore-pwa.js';

    if ( ! file_exists( $pwa_js ) ) {
        return;
    }

    wp_enqueue_script(
        'golfscore-pwa-js',
        plugin_dir_url(__FILE__) . 'assets/js/golfscore-pwa.js',
        array(),
        filemtime( $pwa_js ),
        true
    );

    wp_localize_script(
        'golfscore-pwa-js',
        'golfscorePwaData',
        array(
            'serviceWorkerUrl' => home_url( '/?golfscore-pwa-sw=1' ),
            'startUrl'         => golfscore_get_pwa_start_url(),
        )
    );
}
add_action( 'wp_enqueue_scripts', 'golfscore_enqueue_pwa_assets' );

/**
 * Add PWA manifest and mobile meta tags.
 */
function golfscore_add_pwa_head_tags() {
    if ( is_admin() ) {
        return;
    }

    $manifest_url = home_url( '/?golfscore-pwa-manifest=1' );
    $theme_color  = '#125b2e';
    $touch_icon   = golfscore_get_pwa_icon_url( 192 );

    echo '<link rel="manifest" href="' . esc_url( $manifest_url ) . '">' . "\n";
    echo '<link rel="apple-touch-icon" href="' . esc_url( $touch_icon ) . '">' . "\n";
    echo '<meta name="theme-color" content="' . esc_attr( $theme_color ) . '">' . "\n";
    echo '<meta name="mobile-web-app-capable" content="yes">' . "\n";
    echo '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
    echo '<meta name="apple-mobile-web-app-status-bar-style" content="default">' . "\n";
    echo '<meta name="apple-mobile-web-app-title" content="GolfScore">' . "\n";
}
add_action( 'wp_head', 'golfscore_add_pwa_head_tags' );

/**
 * Allow custom query vars for dynamic PWA assets.
 */
function golfscore_register_pwa_query_vars( $vars ) {
    $vars[] = 'golfscore-pwa-manifest';
    $vars[] = 'golfscore-pwa-sw';

    return $vars;
}
add_filter( 'query_vars', 'golfscore_register_pwa_query_vars' );

/**
 * Use the site icon when available, with a stable fallback.
 */
function golfscore_get_pwa_icon_url( $size ) {
    $icon_url = get_site_icon_url( $size );

    if ( ! empty( $icon_url ) ) {
        return $icon_url;
    }

    $size = intval( $size );
    if ( $size <= 0 ) {
        $size = 192;
    }

    $plugin_icon_path = plugin_dir_path( __FILE__ ) . 'assets/icons/golfscore-icon-' . $size . '.png';
    if ( file_exists( $plugin_icon_path ) ) {
        return plugin_dir_url( __FILE__ ) . 'assets/icons/golfscore-icon-' . $size . '.png';
    }

    // Fallback to 192px icon if an uncommon size is requested.
    $fallback_icon_path = plugin_dir_path( __FILE__ ) . 'assets/icons/golfscore-icon-192.png';
    if ( file_exists( $fallback_icon_path ) ) {
        return plugin_dir_url( __FILE__ ) . 'assets/icons/golfscore-icon-192.png';
    }

    return includes_url( 'images/w-logo-blue-white-bg.png' );
}

/**
 * Determine the best start URL for the GolfScore PWA.
 */
function golfscore_get_pwa_start_url() {
    // Hard-pin to the known GolfScore page.
    return home_url( '/golfscore/' );
}

/**
 * Serve manifest.json and service worker from root query endpoints.
 */
function golfscore_serve_pwa_assets() {
    $manifest_flag = intval( get_query_var( 'golfscore-pwa-manifest' ) );
    $sw_flag       = intval( get_query_var( 'golfscore-pwa-sw' ) );

    if ( 1 === $manifest_flag ) {
        $start_url = golfscore_get_pwa_start_url();
        $scope_url = trailingslashit( home_url( '/golfscore/' ) );

        $manifest = array(
            'name'             => get_bloginfo( 'name' ) . ' GolfScore',
            'short_name'       => 'GolfScore',
            'description'      => 'Track golf rounds and scores hole by hole.',
            'start_url'        => $start_url,
            'scope'            => $scope_url,
            'display'          => 'standalone',
            'background_color' => '#f4f7f2',
            'theme_color'      => '#125b2e',
            'icons'            => array(
                array(
                    'src'   => golfscore_get_pwa_icon_url( 192 ),
                    'sizes' => '192x192',
                    'type'  => 'image/png',
                ),
                array(
                    'src'   => golfscore_get_pwa_icon_url( 512 ),
                    'sizes' => '512x512',
                    'type'  => 'image/png',
                ),
            ),
        );

        nocache_headers();
        header( 'Content-Type: application/manifest+json; charset=utf-8' );
        echo wp_json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        exit;
    }

    if ( 1 === $sw_flag ) {
        nocache_headers();
        header( 'Content-Type: application/javascript; charset=utf-8' );
        header( 'Service-Worker-Allowed: /' );

        $cache_name = 'golfscore-pwa-v4';
        $start_url  = golfscore_get_pwa_start_url();

        echo "const GOLFSCORE_CACHE = '" . esc_js( $cache_name ) . "';\n";
        echo "const GOLFSCORE_SHELL = ['" . esc_js( $start_url ) . "'];\n";
        ?>
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(GOLFSCORE_CACHE).then((cache) => cache.addAll(GOLFSCORE_SHELL))
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => Promise.all(
            keys
                .filter((key) => key !== GOLFSCORE_CACHE)
                .map((key) => caches.delete(key))
        ))
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    if (event.request.method !== 'GET') {
        return;
    }

    const req = event.request;
    const isNavigation = req.mode === 'navigate';

    if (isNavigation) {
        event.respondWith(
            fetch(req)
                .then((response) => {
                    const cloned = response.clone();
                    caches.open(GOLFSCORE_CACHE).then((cache) => cache.put(req, cloned));
                    return response;
                })
                .catch(() => caches.match(req).then((cached) => cached || caches.match(GOLFSCORE_SHELL[0])))
        );
        return;
    }

    event.respondWith(
        caches.match(req).then((cached) => {
            if (cached) {
                return cached;
            }

            return fetch(req)
                .then((response) => {
                    if (!response || response.status !== 200) {
                        return response;
                    }

                    const copy = response.clone();
                    caches.open(GOLFSCORE_CACHE).then((cache) => cache.put(req, copy));
                    return response;
                })
                .catch(() => caches.match(GOLFSCORE_SHELL[0]));
        })
    );
});
        <?php
        exit;
    }
}
add_action( 'template_redirect', 'golfscore_serve_pwa_assets' );

/**
 * Shortcode: [golfscore_session] - Main scoring interface
 */
function golfscore_session_shortcode() {
    ob_start();
    $session_id = intval($_GET['session_id'] ?? 0);
    
    if (!$session_id) {
        golfscore_session_list();
    } else {
        golfscore_scoring_interface($session_id);
    }
    
    return ob_get_clean();
}
add_shortcode('golfscore_session', 'golfscore_session_shortcode');

/**
 * Display list of active sessions and create new
 */
function golfscore_session_list() {
    global $wpdb;
    $sessions_table = $wpdb->prefix . 'golf_sessions';
    error_log('[GolfScore] golfscore_session_list() called');
    
    // Non-AJAX fallback: handle form POST if JavaScript is unavailable or not loaded
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ! empty($_POST['golfscore_create_session'])) {
        error_log('[GolfScore] POST request detected for create_session');
        // Verify nonce (field name set in wp_nonce_field below)
        $nonce_field = $_POST['golfscore_nonce'] ?? '';
        error_log('[GolfScore] Nonce field: ' . $nonce_field);
        if ( ! wp_verify_nonce( $nonce_field, 'golfscore_create_session' ) ) {
            error_log('[GolfScore] Nonce verification FAILED');
            echo '<div id="session-message" class="error">Security check failed. Please refresh and try again.</div>';
        } else {
            error_log('[GolfScore] Nonce verification PASSED');
            $session_name = sanitize_text_field( $_POST['session_name'] ?? '' );
            error_log('[GolfScore] Session name: ' . $session_name);
            if ( empty( $session_name ) ) {
                error_log('[GolfScore] ERROR: Session name is empty');
                echo '<div id="session-message" class="error">Please enter a session name.</div>';
            } else {
                error_log('[GolfScore] Inserting session into database');
                $inserted = $wpdb->insert( $sessions_table, [
                    'session_name' => $session_name,
                    'current_hole' => 1,
                    'total_holes'  => 18,
                    'status'       => 'active'
                ]);

                if ( $inserted === false ) {
                    error_log('[GolfScore] ERROR: Failed to insert session - ' . $wpdb->last_error);
                    echo '<div id="session-message" class="error">Failed to create session: ' . esc_html( $wpdb->last_error ) . '</div>';
                } else {
                    $new_id = $wpdb->insert_id;
                    error_log('[GolfScore] Session created successfully with ID: ' . $new_id);
                    // Redirect to avoid duplicate form submissions and show the new session
                    wp_redirect( esc_url_raw( add_query_arg( 'session_id', $new_id ) ) );
                    exit;
                }
            }
        }
    }
    $sessions = $wpdb->get_results("SELECT * FROM $sessions_table WHERE status = 'active' ORDER BY created_at DESC");
    ?>
    <div class="golfscore-container">
        <h2>Golf Scoring Sessions</h2>
        
        <div class="golfscore-create-session">
            <h3>Create New Session</h3>
            <form id="golfscore-new-session-form" method="post">
                <?php wp_nonce_field('golfscore_create_session', 'golfscore_nonce'); ?>
                <input type="hidden" name="golfscore_create_session" value="1">
                <label>Session Name:</label>
                <input type="text" name="session_name" placeholder="e.g., Saturday Round" required>
                <button type="submit" class="button button-primary">Create Session</button>
            </form>
            <div id="session-message"></div>
        </div>

        <h3>Active Sessions</h3>
        <?php if ($sessions): ?>
            <table class="golfscore-sessions-table">
                <thead>
                    <tr>
                        <th>Session Name</th>
                        <th>Hole</th>
                        <th>Players</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sessions as $session): ?>
                        <?php
                        $players = $wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(*) FROM {$wpdb->prefix}golf_players WHERE session_id = %d AND is_active = 1",
                            $session->id
                        ));
                        ?>
                        <tr>
                            <td><?php echo esc_html($session->session_name); ?></td>
                            <td><?php echo $session->current_hole; ?>/<?php echo $session->total_holes; ?></td>
                            <td><?php echo $players; ?></td>
                            <td>
                                <a href="?session_id=<?php echo $session->id; ?>" class="button">Score</a>
                                <button class="button golfscore-end-session" data-session-id="<?php echo $session->id; ?>">End</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>No active sessions. Create one to get started!</p>
        <?php endif;
}

/**
 * Main scoring interface - hole by hole for all players
 */
function golfscore_scoring_interface($session_id) {
    global $wpdb;
    
    $sessions_table = $wpdb->prefix . 'golf_sessions';
    $players_table = $wpdb->prefix . 'golf_players';
    $scores_table = $wpdb->prefix . 'golf_scores';
    
    $session = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $sessions_table WHERE id = %d",
        $session_id
    ));
    
    if (!$session) {
        echo "Session not found.";
        return;
    }

    // Non-AJAX fallback: add players via POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ! empty($_POST['golfscore_add_players'])) {
        error_log('[GolfScore] POST request detected for add_players, session_id: ' . $session_id);
        $nonce_field = $_POST['golfscore_nonce'] ?? '';
        error_log('[GolfScore] Nonce field for add_players: ' . $nonce_field);
        if ( ! wp_verify_nonce( $nonce_field, 'golfscore_nonce' ) ) {
            error_log('[GolfScore] Nonce verification FAILED for add_players');
            echo '<div id="golfscore-add-player-message" class="error">Security check failed. Please refresh and try again.</div>';
        } else {
            error_log('[GolfScore] Nonce verification PASSED for add_players');
            $raw = sanitize_text_field( $_POST['player_names'] ?? '' );
            error_log('[GolfScore] Raw player names: ' . $raw);
            $names = array_filter(array_map('trim', explode(',', $raw)));
            error_log('[GolfScore] Parsed ' . count($names) . ' player names');
            if ( empty( $names ) ) {
                error_log('[GolfScore] ERROR: No player names provided');
                echo '<div id="golfscore-add-player-message" class="error">Please enter at least one player name.</div>';
            } else {
                foreach ( $names as $nm ) {
                    if ( $nm === '' ) continue;
                    error_log('[GolfScore] Inserting player: ' . $nm . ' for session_id: ' . $session_id);
                    $wpdb->insert( $players_table, [
                        'session_id' => $session_id,
                        'player_name' => $nm,
                        'is_active' => 1
                    ] );
                }
                error_log('[GolfScore] All players inserted, redirecting to session_id: ' . $session_id);
                // Redirect to avoid duplicate submissions
                wp_redirect( esc_url_raw( add_query_arg( 'session_id', $session_id ) ) );
                exit;
            }
        }
    }
    
    $players = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $players_table WHERE session_id = %d AND is_active = 1 ORDER BY id",
        $session_id
    ));
    $has_players = ! empty( $players );
    
    // Determine which hole to display: prefer explicit ?hole=X, otherwise use session current_hole
    $requested_hole = isset($_GET['hole']) ? intval($_GET['hole']) : 0;
    if ( $requested_hole && $requested_hole >= 1 && $requested_hole <= $session->total_holes ) {
        $hole = $requested_hole;
    } else {
        $hole = $session->current_hole;
    }
    $hole_scores = $wpdb->get_results($wpdb->prepare(
        "SELECT player_id, score FROM $scores_table WHERE session_id = %d AND hole = %d",
        $session_id,
        $hole
    ));
    
    $scores_map = [];
    foreach ($hole_scores as $score) {
        $scores_map[$score->player_id] = $score->score;
    }
    
    ?>
    <div class="golfscore-container">
        <div class="golfscore-header">
            <h2><?php echo esc_html($session->session_name); ?></h2>
            <div class="golfscore-progress">Hole <?php echo $hole; ?> of <?php echo $session->total_holes; ?></div>
        </div>

        <?php if ( ! $has_players ) : ?>
            <!-- Add Players Section (top only when no players exist yet) -->
            <div class="golfscore-add-players-section">
                <button type="button" class="button button-secondary golfscore-toggle-add-players" id="golfscore-toggle-add-players" data-open-label="Add Players" data-close-label="Hide Add Players">Add Players</button>
                <div id="golfscore-add-player-form" style="display:none;margin-top:10px;">
                    <?php wp_nonce_field('golfscore_nonce', 'golfscore_nonce'); ?>
                    <input type="hidden" name="golfscore_add_players" value="1">
                    <input type="hidden" name="session_id" value="<?php echo $session_id; ?>">
                    <label>Player names (comma separated):</label>
                    <input type="text" name="player_names" placeholder="e.g., Alice, Bob">
                    <button type="button" class="button golfscore-add-player-btn">Add Players</button>
                </div>
                <div id="golfscore-add-player-message"></div>
            </div>
        <?php endif; ?>

        <!-- Scoring Section -->
        <form id="golfscore-hole-form" method="post">
            <?php wp_nonce_field('golfscore_nonce', 'golfscore_nonce'); ?>
            <input type="hidden" name="session_id" value="<?php echo $session_id; ?>">
            <input type="hidden" name="hole" value="<?php echo $hole; ?>">

            <div class="golfscore-scoring-grid">
                <h3>Hole <?php echo $hole; ?></h3>
                <?php foreach ($players as $player): ?>
                    <?php $current_score = $scores_map[$player->id] ?? ''; ?>
                    <div class="golfscore-player-input">
                        <label><?php echo esc_html($player->player_name); ?></label>
                        <div class="golfscore-score-buttons">
                            <?php for ($score = 1; $score <= 7; $score++): ?>
                                <button 
                                    type="button" 
                                    class="golfscore-score-btn <?php echo ($current_score == $score) ? 'active' : ''; ?>"
                                    data-player-id="<?php echo $player->id; ?>"
                                    data-score="<?php echo $score; ?>"
                                >
                                    <?php echo $score; ?>
                                </button>
                            <?php endfor; ?>
                        </div>
                        <input 
                            type="hidden" 
                            name="player_<?php echo $player->id; ?>" 
                            value="<?php echo esc_attr($current_score); ?>"
                            class="golfscore-player-score"
                        >
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="golfscore-navigation">
                <?php if ($hole < $session->total_holes): ?>
                    <button type="submit" class="button button-primary golfscore-next-hole" data-next-hole="1">Next Hole →</button>
                <?php else: ?>
                    <button type="submit" class="button button-primary">Save & Finish Round</button>
                <?php endif; ?>
            </div>
        </form>

        <div id="golfscore-response" class="golfscore-response"></div>

        <div class="golfscore-scorecard">
            <h3>Scorecard</h3>
            <?php golfscore_display_scorecard($session_id, $hole); ?>
        </div>

        <?php if ( $has_players ) : ?>
            <!-- Add Players Section (moves to bottom once players exist) -->
            <div class="golfscore-add-players-section" style="margin-top:10px;">
                <button type="button" class="button button-secondary golfscore-toggle-add-players" id="golfscore-toggle-add-players" data-open-label="Edit Players" data-close-label="Hide Edit Players">Edit Players</button>
                <div id="golfscore-add-player-form" style="display:none;margin-top:10px;">
                    <?php wp_nonce_field('golfscore_nonce', 'golfscore_nonce'); ?>
                    <input type="hidden" name="golfscore_add_players" value="1">
                    <input type="hidden" name="session_id" value="<?php echo $session_id; ?>">
                    <label>Player names (comma separated):</label>
                    <input type="text" name="player_names" placeholder="e.g., Alice, Bob">
                    <button type="button" class="button golfscore-add-player-btn">Add Players</button>

                    <?php if ( ! empty( $players ) ) : ?>
                        <div class="golfscore-existing-players" style="margin-top:10px;">
                            <strong>Current players:</strong>
                            <ul style="margin:8px 0 0 18px;">
                                <?php foreach ( $players as $player ) : ?>
                                    <li style="margin:4px 0;">
                                        <span><?php echo esc_html( $player->player_name ); ?></span>
                                        <button
                                            type="button"
                                            class="button button-small golfscore-delete-player-btn"
                                            data-player-id="<?php echo esc_attr( $player->id ); ?>"
                                            data-session-id="<?php echo esc_attr( $session_id ); ?>"
                                            data-player-name="<?php echo esc_attr( $player->player_name ); ?>"
                                            style="margin-left:8px;"
                                        >Delete</button>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
                <div id="golfscore-add-player-message"></div>
            </div>
        <?php endif; ?>

        <div class="golfscore-bottom-row" style="margin-top:10px; display:flex; gap:8px; align-items:center;">
            <?php if ($hole > 1): ?>
                <button type="button" class="button golfscore-prev-hole" data-session-id="<?php echo $session_id; ?>" data-hole="<?php echo $hole - 1; ?>">← Previous Hole</button>
            <?php endif; ?>

            <a href="<?php echo remove_query_arg('session_id'); ?>" class="button">Back to Sessions</a>
        </div>

        <script>
        (function(){
            var toggle = document.getElementById('golfscore-toggle-add-players');
            if (!toggle) return;
            var form = document.getElementById('golfscore-add-player-form');
            var input = form ? form.querySelector('input[name="player_names"]') : null;
            var openLabel = toggle.getAttribute('data-open-label') || 'Add Players';
            var closeLabel = toggle.getAttribute('data-close-label') || 'Hide Add Players';
            toggle.addEventListener('click', function(){
                if (!form) return;
                if (form.style.display === 'none' || form.style.display === '') {
                    form.style.display = 'block';
                    if (input) try{ input.focus(); }catch(e){}
                    toggle.textContent = closeLabel;
                } else {
                    form.style.display = 'none';
                    toggle.textContent = openLabel;
                }
            });
        })();
        </script>
    </div>
    <?php
}

/**
 * Display current scorecard
 */
function golfscore_display_scorecard($session_id, $current_hole) {
    global $wpdb;
    $players_table = $wpdb->prefix . 'golf_players';
    $scores_table = $wpdb->prefix . 'golf_scores';
    $sessions_table = $wpdb->prefix . 'golf_sessions';
    
    $session = $wpdb->get_row($wpdb->prepare(
        "SELECT total_holes FROM {$sessions_table} WHERE id = %d",
        $session_id
    ));
    
    $players = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $players_table WHERE session_id = %d AND is_active = 1 ORDER BY id",
        $session_id
    ));
    
    // Header: Player, Total, then holes
    $base_url = add_query_arg( 'session_id', $session_id, remove_query_arg( 'hole' ) );
    echo '<table class="golfscore-scorecard-table"><tr><th>Player</th><th>Total</th>';
    for ($h = 1; $h <= $session->total_holes; $h++) {
        $hole_url = esc_url( add_query_arg( 'hole', $h, $base_url ) );
        $is_current = ($h === $current_hole);
        echo '<th' . ($is_current ? ' class="current"' : '') . '>'
            . '<a href="' . $hole_url . '" style="' . ($is_current ? 'font-weight:bold;' : '') . 'text-decoration:none;color:inherit;" title="Go to hole ' . $h . '">' . $h . '</a>'
            . '</th>';
    }
    echo '</tr>';

    foreach ($players as $player) {
        // Fetch all scores for this player in one query
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT hole, score FROM $scores_table WHERE session_id = %d AND player_id = %d",
            $session_id,
            $player->id
        ));

        $scores_map = [];
        foreach ($rows as $r) {
            $scores_map[(int)$r->hole] = (int)$r->score;
        }

        $total = array_sum($scores_map);

        echo '<tr data-player-id="' . esc_attr( $player->id ) . '">';
        echo '<td>' . esc_html($player->player_name) . '</td>';
        echo '<td class="total">' . ($total > 0 ? $total : '-') . '</td>';

        for ($h = 1; $h <= $session->total_holes; $h++) {
            $score = isset($scores_map[$h]) ? $scores_map[$h] : null;
            if ($score !== null) {
                echo '<td data-hole="' . esc_attr( $h ) . '"' . ($h === $current_hole ? ' class="current"' : '') . '>' . $score . '</td>';
            } else {
                echo '<td data-hole="' . esc_attr( $h ) . '"' . ($h === $current_hole ? ' class="current"' : '') . '>-</td>';
            }
        }

        echo '</tr>';
    }

    echo '</table>';
}

/**
 * Shortcode: [golfscore_form] - Legacy form (kept for compatibility)
 */
function golfscore_form_shortcode() {
    ob_start();
    ?>
    <div class="golfscore-container">
        <p>Use the <strong>[golfscore_session]</strong> shortcode for the new hole-by-hole scoring system.</p>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('golfscore_form', 'golfscore_form_shortcode');

/**
 * Build sync payload for cross-device updates.
 */
function golfscore_get_session_sync_payload( $session_id, $hole ) {
    global $wpdb;

    $sessions_table = $wpdb->prefix . 'golf_sessions';
    $players_table  = $wpdb->prefix . 'golf_players';
    $scores_table   = $wpdb->prefix . 'golf_scores';

    $session = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id, current_hole, total_holes, status FROM $sessions_table WHERE id = %d",
            $session_id
        )
    );

    if ( ! $session ) {
        return null;
    }

    $hole = intval( $hole );
    if ( $hole < 1 || $hole > intval( $session->total_holes ) ) {
        $hole = intval( $session->current_hole );
    }

    $max_score_id = intval(
        $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(MAX(id), 0) FROM $scores_table WHERE session_id = %d",
                $session_id
            )
        )
    );

    $active_player_count = intval(
        $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM $players_table WHERE session_id = %d AND is_active = 1",
                $session_id
            )
        )
    );

    $max_active_player_id = intval(
        $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(MAX(id), 0) FROM $players_table WHERE session_id = %d AND is_active = 1",
                $session_id
            )
        )
    );

    $hole_rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT player_id, score FROM $scores_table WHERE session_id = %d AND hole = %d",
            $session_id,
            $hole
        )
    );

    $hole_scores = array();
    foreach ( $hole_rows as $row ) {
        $hole_scores[ intval( $row->player_id ) ] = intval( $row->score );
    }

    $signature = implode(
        ':',
        array(
            intval( $session->current_hole ),
            sanitize_text_field( $session->status ),
            $max_score_id,
            $active_player_count,
            $max_active_player_id,
            $hole,
        )
    );

    return array(
        'signature'    => $signature,
        'hole'         => $hole,
        'current_hole' => intval( $session->current_hole ),
        'total_holes'  => intval( $session->total_holes ),
        'status'       => sanitize_text_field( $session->status ),
        'hole_scores'  => $hole_scores,
    );
}

add_action( 'wp_ajax_golfscore_poll_session', 'golfscore_ajax_poll_session' );
add_action( 'wp_ajax_nopriv_golfscore_poll_session', 'golfscore_ajax_poll_session' );

/**
 * Poll session state for near real-time cross-device updates.
 */
function golfscore_ajax_poll_session() {
    check_ajax_referer( 'golfscore_nonce', 'nonce' );

    $session_id = intval( $_POST['session_id'] ?? 0 );
    $hole       = intval( $_POST['hole'] ?? 0 );

    if ( ! $session_id ) {
        wp_send_json_error( array( 'message' => 'Missing session_id.' ) );
    }

    $payload = golfscore_get_session_sync_payload( $session_id, $hole );

    if ( ! $payload ) {
        wp_send_json_error( array( 'message' => 'Session not found.' ) );
    }

    wp_send_json_success( $payload );
}

/**
 * Handle form submissions via AJAX
 */

// Create new session
add_action('wp_ajax_golfscore_create_session', 'golfscore_ajax_create_session');
add_action('wp_ajax_nopriv_golfscore_create_session', 'golfscore_ajax_create_session');

function golfscore_ajax_create_session() {
    error_log('[GolfScore] AJAX golfscore_ajax_create_session() called');
    check_ajax_referer('golfscore_create_session', 'nonce');
    error_log('[GolfScore] AJAX nonce verified for create_session');
    
    global $wpdb;
    $sessions_table = $wpdb->prefix . 'golf_sessions';
    
    if (empty($_POST['session_name'])) {
        error_log('[GolfScore] AJAX ERROR: Session name is required');
        wp_send_json_error(['message' => 'Session name is required']);
    }
    
    $session_name = sanitize_text_field($_POST['session_name']);
    error_log('[GolfScore] AJAX creating session with name: ' . $session_name);
    
    $result = $wpdb->insert($sessions_table, [
        'session_name' => $session_name,
        'current_hole' => 1,
        'total_holes' => 18,
        'status' => 'active'
    ]);
    
    if ($result === false) {
        error_log('[GolfScore] AJAX ERROR: Failed to insert session - ' . $wpdb->last_error);
        wp_send_json_error(['message' => 'Failed to create session: ' . $wpdb->last_error]);
    }
    
    $session_id = $wpdb->insert_id;
    error_log('[GolfScore] AJAX session created with ID: ' . $session_id);
    wp_send_json_success([
        'message' => 'Session created successfully!',
        'session_id' => $session_id,
        'session_name' => $session_name
    ]);
}

// Add player to session
add_action('wp_ajax_golfscore_add_player', 'golfscore_ajax_add_player');
add_action('wp_ajax_nopriv_golfscore_add_player', 'golfscore_ajax_add_player');

function golfscore_ajax_add_player() {
    error_log('[GolfScore] AJAX golfscore_ajax_add_player() called');
    error_log('[GolfScore] AJAX POST data: ' . json_encode($_POST));
    check_ajax_referer('golfscore_nonce', 'nonce');
    error_log('[GolfScore] AJAX nonce verified');
    
    global $wpdb;
    $players_table = $wpdb->prefix . 'golf_players';
    
    $session_id = intval($_POST['session_id']);
    $player_names_raw = sanitize_text_field($_POST['player_names'] ?? ($_POST['player_name'] ?? ''));
    error_log('[GolfScore] AJAX add_player - session_id: ' . $session_id . ', player_names: ' . $player_names_raw);

    if ( empty( $player_names_raw ) ) {
        error_log('[GolfScore] AJAX ERROR: No player names provided');
        wp_send_json_error(['message' => 'Player name(s) required']);
    }

    // Support comma-separated list of names
    $names = array_filter(array_map('trim', explode(',', $player_names_raw)));
    error_log('[GolfScore] AJAX parsed ' . count($names) . ' player names');
    $inserted_ids = [];
    foreach ($names as $name) {
        if ( $name === '' ) {
            continue;
        }
        error_log('[GolfScore] AJAX inserting player: ' . $name);
        $wpdb->insert($players_table, [
            'session_id' => $session_id,
            'player_name' => $name,
            'is_active' => 1
        ]);
        $inserted_ids[] = $wpdb->insert_id;
    }

    if ( empty( $inserted_ids ) ) {
        error_log('[GolfScore] AJAX ERROR: No valid player names were inserted');
        wp_send_json_error(['message' => 'No valid player names provided']);
    }

    error_log('[GolfScore] AJAX success: inserted ' . count($inserted_ids) . ' player(s)');
    wp_send_json_success(['player_ids' => $inserted_ids, 'message' => 'Player(s) added!']);
}

// Delete player from session (soft delete)
add_action('wp_ajax_golfscore_delete_player', 'golfscore_ajax_delete_player');
add_action('wp_ajax_nopriv_golfscore_delete_player', 'golfscore_ajax_delete_player');

function golfscore_ajax_delete_player() {
    check_ajax_referer('golfscore_nonce', 'nonce');

    global $wpdb;
    $players_table = $wpdb->prefix . 'golf_players';

    $session_id = intval($_POST['session_id'] ?? 0);
    $player_id  = intval($_POST['player_id'] ?? 0);

    if ( ! $session_id || ! $player_id ) {
        wp_send_json_error(['message' => 'Invalid player or session.']);
    }

    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $players_table WHERE id = %d AND session_id = %d AND is_active = 1",
        $player_id,
        $session_id
    ));

    if ( ! $exists ) {
        wp_send_json_error(['message' => 'Player not found.']);
    }

    $updated = $wpdb->update(
        $players_table,
        ['is_active' => 0],
        ['id' => $player_id, 'session_id' => $session_id],
        ['%d'],
        ['%d', '%d']
    );

    if ( $updated === false ) {
        wp_send_json_error(['message' => 'Failed to delete player: ' . $wpdb->last_error]);
    }

    wp_send_json_success(['message' => 'Player deleted.']);
}

// Save one player score instantly (used by score button taps)
add_action('wp_ajax_golfscore_save_single_score', 'golfscore_ajax_save_single_score');
add_action('wp_ajax_nopriv_golfscore_save_single_score', 'golfscore_ajax_save_single_score');

function golfscore_ajax_save_single_score() {
    check_ajax_referer('golfscore_nonce', 'nonce');

    global $wpdb;
    $scores_table  = $wpdb->prefix . 'golf_scores';
    $players_table = $wpdb->prefix . 'golf_players';

    $session_id = intval($_POST['session_id'] ?? 0);
    $hole       = intval($_POST['hole'] ?? 0);
    $player_id  = intval($_POST['player_id'] ?? 0);
    $score      = intval($_POST['score'] ?? 0);

    if (!$session_id || !$hole || !$player_id || $score <= 0) {
        wp_send_json_error(['message' => 'Invalid score data.']);
    }

    $player_exists = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $players_table WHERE id = %d AND session_id = %d AND is_active = 1",
        $player_id,
        $session_id
    ));

    if (!$player_exists) {
        wp_send_json_error(['message' => 'Player not found for session.']);
    }

    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $scores_table WHERE session_id = %d AND player_id = %d AND hole = %d",
        $session_id,
        $player_id,
        $hole
    ));

    if ($existing) {
        $saved = $wpdb->update(
            $scores_table,
            ['score' => $score],
            ['session_id' => $session_id, 'player_id' => $player_id, 'hole' => $hole],
            ['%d'],
            ['%d', '%d', '%d']
        );
    } else {
        $saved = $wpdb->insert(
            $scores_table,
            [
                'session_id' => $session_id,
                'player_id'  => $player_id,
                'hole'       => $hole,
                'score'      => $score,
            ],
            ['%d', '%d', '%d', '%d']
        );
    }

    if ($saved === false) {
        wp_send_json_error(['message' => 'Failed to save score: ' . $wpdb->last_error]);
    }

    wp_send_json_success(['message' => 'Score updated.']);
}

// Advance to the next hole without resaving scores.
add_action('wp_ajax_golfscore_advance_hole', 'golfscore_ajax_advance_hole');
add_action('wp_ajax_nopriv_golfscore_advance_hole', 'golfscore_ajax_advance_hole');

function golfscore_ajax_advance_hole() {
    check_ajax_referer('golfscore_nonce', 'nonce');

    global $wpdb;
    $sessions_table = $wpdb->prefix . 'golf_sessions';

    $session_id = intval($_POST['session_id'] ?? 0);
    $hole       = intval($_POST['hole'] ?? 0);

    if ( ! $session_id || ! $hole ) {
        wp_send_json_error( array( 'message' => 'Missing session or hole.' ) );
    }

    $session = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT total_holes FROM $sessions_table WHERE id = %d",
            $session_id
        )
    );

    if ( ! $session ) {
        wp_send_json_error( array( 'message' => 'Session not found.' ) );
    }

    $new_hole = $hole + 1;

    if ( $new_hole <= intval( $session->total_holes ) ) {
        $wpdb->update(
            $sessions_table,
            array( 'current_hole' => $new_hole ),
            array( 'id' => $session_id ),
            array( '%d' ),
            array( '%d' )
        );
    } else {
        $wpdb->update(
            $sessions_table,
            array( 'status' => 'completed', 'current_hole' => intval( $session->total_holes ) ),
            array( 'id' => $session_id ),
            array( '%s', '%d' ),
            array( '%d' )
        );
    }

    if ( $wpdb->last_error ) {
        wp_send_json_error( array( 'message' => 'Failed to advance hole: ' . $wpdb->last_error ) );
    }

    wp_send_json_success( array(
        'message'   => 'Hole advanced.',
        'next_hole' => ( $new_hole <= intval( $session->total_holes ) ) ? $new_hole : null,
    ) );
}

// End session
add_action('wp_ajax_golfscore_end_session', 'golfscore_ajax_end_session');
add_action('wp_ajax_nopriv_golfscore_end_session', 'golfscore_ajax_end_session');

function golfscore_ajax_end_session() {
    check_ajax_referer('golfscore_nonce', 'nonce');
    
    global $wpdb;
    $sessions_table = $wpdb->prefix . 'golf_sessions';
    
    $session_id = intval($_POST['session_id']);
    
    $wpdb->update($sessions_table,
        ['status' => 'ended'],
        ['id' => $session_id]
    );
    
    wp_send_json_success(['message' => 'Session ended']);
}

/**
 * Admin Menu
 */
function golfscore_admin_menu() {
    add_menu_page(
        'Golf Scores',
        'GolfScore',
        'manage_options',
        'golfscore',
        'golfscore_admin_page',
        'dashicons-flag'
    );
}
add_action('admin_menu', 'golfscore_admin_menu');

/**
 * Admin Page
 */
function golfscore_admin_page() {
    global $wpdb;
    $sessions_table = $wpdb->prefix . 'golf_sessions';
    $players_table = $wpdb->prefix . 'golf_players';
    $scores_table = $wpdb->prefix . 'golf_scores';
    
    $completed_sessions = $wpdb->get_results("SELECT * FROM $sessions_table WHERE status != 'active' ORDER BY created_at DESC");
    ?>
    <div class="wrap">
        <h1>Golf Scores - Sessions History</h1>
        
        <table class="widefat">
            <thead>
                <tr>
                    <th>Session</th>
                    <th>Players</th>
                    <th>Status</th>
                    <th>Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($completed_sessions as $session): ?>
                    <?php
                    $player_count = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM $players_table WHERE session_id = %d",
                        $session->id
                    ));
                    ?>
                    <tr>
                        <td><?php echo esc_html($session->session_name); ?></td>
                        <td><?php echo $player_count; ?></td>
                        <td><?php echo esc_html(ucfirst($session->status)); ?></td>
                        <td><?php echo esc_html(date('M d, Y', strtotime($session->created_at))); ?></td>
                        <td>
                            <a href="?page=golfscore&view=detail&session_id=<?php echo $session->id; ?>" class="button">View</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}
