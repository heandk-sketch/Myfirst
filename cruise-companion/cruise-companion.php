<?php
/**
 * Plugin Name: Cruise Companion
 * Description: Privat cruise-plugin til 4 rejsende med rejseplan, delt feed og daglig multiple-choice havnequiz.
 * Version: 0.1.0
 * Author: Cruise Companion Team
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CC_PLUGIN_VERSION', '0.2.0');
define('CC_DB_VERSION', '4');
define('CC_PLUGIN_FILE', __FILE__);
define('CC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CC_LOCAL_AUTH_COOKIE', 'cc_local_auth');

register_activation_hook(CC_PLUGIN_FILE, 'cc_install_plugin');
add_action('plugins_loaded', 'cc_bootstrap_plugin');

function cc_bootstrap_plugin() {
    add_shortcode('cruise_companion', 'cc_render_shortcode');
    add_action('init', 'cc_handle_frontend_post');
    add_action('init', 'cc_maybe_serve_pwa_assets');
    add_action('wp', 'cc_isolate_shortcode_pwa_environment', 20);
    add_action('wp_enqueue_scripts', 'cc_enqueue_assets');
    add_action('admin_menu', 'cc_register_admin_page');
    add_action('wp_head', 'cc_output_pwa_meta_tags');
    add_action('wp_footer', 'cc_output_pwa_registration_script');
    cc_maybe_upgrade_db();
}

function cc_isolate_shortcode_pwa_environment() {
    if (!cc_is_shortcode_page()) {
        return;
    }

    // On Cruise Companion pages, remove Golfscore PWA hooks to avoid duplicate manifests/icons.
    if (function_exists('golfscore_add_pwa_head_tags')) {
        remove_action('wp_head', 'golfscore_add_pwa_head_tags');
    }

    if (function_exists('golfscore_enqueue_pwa_assets')) {
        remove_action('wp_enqueue_scripts', 'golfscore_enqueue_pwa_assets');
    }
}

function cc_maybe_upgrade_db() {
    if ((string) get_option('cc_db_version', '1') === CC_DB_VERSION) {
        return;
    }

    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();

    $ports_table = $wpdb->prefix . 'cc_ports';
    $polls_table       = $wpdb->prefix . 'cc_polls';
    $poll_options_table = $wpdb->prefix . 'cc_poll_options';
    $poll_votes_table  = $wpdb->prefix . 'cc_poll_votes';
    $push_subscriptions_table = $wpdb->prefix . 'cc_push_subscriptions';

    dbDelta( "CREATE TABLE $ports_table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(190) NOT NULL,
        country VARCHAR(190) NOT NULL,
        city VARCHAR(190) NOT NULL,
        latitude DECIMAL(10,7) NULL,
        longitude DECIMAL(10,7) NULL,
        terminal_address TEXT NULL,
        timezone VARCHAR(100) NULL,
        currency VARCHAR(20) NULL,
        language VARCHAR(100) NULL,
        emergency_number VARCHAR(50) NULL,
        notes TEXT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id)
    ) $charset_collate;" );

    dbDelta( "CREATE TABLE $polls_table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        group_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        question VARCHAR(255) NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY cc_polls_group_idx (group_id, created_at)
    ) $charset_collate;" );

    dbDelta( "CREATE TABLE $poll_options_table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        poll_id BIGINT UNSIGNED NOT NULL,
        option_text VARCHAR(255) NOT NULL,
        sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        KEY cc_poll_options_poll_idx (poll_id, sort_order)
    ) $charset_collate;" );

    dbDelta( "CREATE TABLE $poll_votes_table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        poll_id BIGINT UNSIGNED NOT NULL,
        option_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        voted_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY cc_poll_vote_unique (poll_id, user_id),
        KEY cc_poll_votes_option_idx (option_id)
    ) $charset_collate;" );

    dbDelta( "CREATE TABLE $push_subscriptions_table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        group_id BIGINT UNSIGNED NOT NULL,
        endpoint TEXT NOT NULL,
        p256dh VARCHAR(255) NOT NULL,
        auth VARCHAR(100) NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY cc_push_sub_user_idx (user_id),
        KEY cc_push_sub_group_idx (group_id)
    ) $charset_collate;" );

    update_option( 'cc_db_version', CC_DB_VERSION );
}

function cc_enqueue_assets() {
    $style_file = CC_PLUGIN_DIR . 'assets/css/cruise-companion.css';
    $style_version = file_exists($style_file) ? (string) filemtime($style_file) : CC_PLUGIN_VERSION;

    wp_register_style(
        'cc-styles',
        CC_PLUGIN_URL . 'assets/css/cruise-companion.css',
        array(),
        $style_version
    );
}

function cc_is_shortcode_page() {
    if (is_admin() || !is_singular()) {
        return false;
    }

    $post = get_post();
    if (!$post || !isset($post->post_content)) {
        return false;
    }

    return has_shortcode((string) $post->post_content, 'cruise_companion');
}

function cc_get_pwa_icon_url($size) {
    $size = (int) $size;
    if ($size <= 0) {
        $size = 192;
    }

    return CC_PLUGIN_URL . 'assets/pwa/icon-' . $size . '.png';
}

function cc_get_pwa_start_url($page_id = 0) {
    $page_id = $page_id > 0 ? $page_id : get_queried_object_id();
    $start_url = $page_id > 0 ? get_permalink($page_id) : home_url('/');
    if (!is_string($start_url) || $start_url === '') {
        $start_url = home_url('/');
    }

    return add_query_arg('cc_view', 'home', $start_url);
}

function cc_get_pwa_scope_url($page_id = 0) {
    $page_id = $page_id > 0 ? $page_id : get_queried_object_id();
    $candidate_url = $page_id > 0 ? get_permalink($page_id) : home_url('/');
    if (!is_string($candidate_url) || $candidate_url === '') {
        return trailingslashit(home_url('/'));
    }

    $parts = wp_parse_url($candidate_url);
    $path = isset($parts['path']) ? (string) $parts['path'] : '/';
    if ($path === '') {
        $path = '/';
    }

    return trailingslashit(home_url($path));
}

function cc_get_pwa_manifest_url($page_id = 0) {
    $page_id = $page_id > 0 ? $page_id : get_queried_object_id();

    return add_query_arg(
        array(
            'cc_pwa_manifest' => 1,
            'cc_pwa_page_id' => $page_id,
        ),
        home_url('/')
    );
}

function cc_get_pwa_service_worker_url($page_id = 0) {
    $page_id = $page_id > 0 ? $page_id : get_queried_object_id();

    return add_query_arg(
        array(
            'cc_pwa_sw' => 1,
            'cc_pwa_page_id' => $page_id,
        ),
        home_url('/')
    );
}

function cc_maybe_serve_pwa_assets() {
    if (isset($_GET['cc_pwa_manifest'])) {
        cc_serve_pwa_manifest();
    }

    if (isset($_GET['cc_pwa_sw'])) {
        cc_serve_pwa_service_worker();
    }

    if (isset($_GET['cc_poll_notify_status'])) {
        cc_serve_poll_notification_status();
    }

    if (isset($_GET['cc_push_subscribe'])) {
        cc_serve_push_subscribe();
    }
}

function cc_serve_poll_notification_status() {
    $user_id = cc_get_effective_user_id();
    if ($user_id <= 0) {
        status_header(401);
        nocache_headers();
        header('Content-Type: application/json; charset=' . get_option('blog_charset'));
        echo wp_json_encode(array('ok' => false, 'error' => 'not_logged_in'));
        exit;
    }

    $group_id = cc_current_user_group_id($user_id);
    if ($group_id <= 0) {
        status_header(403);
        nocache_headers();
        header('Content-Type: application/json; charset=' . get_option('blog_charset'));
        echo wp_json_encode(array('ok' => false, 'error' => 'no_group'));
        exit;
    }

    $latest_poll = cc_get_latest_poll_for_group($group_id);

    nocache_headers();
    header('Content-Type: application/json; charset=' . get_option('blog_charset'));
    echo wp_json_encode(
        array(
            'ok' => true,
            'user_id' => (int) $user_id,
            'group_id' => (int) $group_id,
            'latest_poll' => $latest_poll,
        ),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    exit;
}

function cc_serve_pwa_manifest() {
    $page_id = isset($_GET['cc_pwa_page_id']) ? (int) $_GET['cc_pwa_page_id'] : 0;
    $start_url = cc_get_pwa_start_url($page_id);
    $scope_url = cc_get_pwa_scope_url($page_id);

    nocache_headers();
    header('Content-Type: application/manifest+json; charset=' . get_option('blog_charset'));

    echo wp_json_encode(
        array(
            'name' => 'Cruise Companion',
            'short_name' => 'Cruise',
            'description' => 'Cruise Companion dashboard til rejseplan, havneinfo, feed og dagens quiz.',
            'id' => $start_url,
            'start_url' => $start_url,
            'scope' => $scope_url,
            'display' => 'standalone',
            'orientation' => 'portrait-primary',
            'background_color' => '#0c1624',
            'theme_color' => '#0f4c5c',
            'lang' => 'da',
            'prefer_related_applications' => false,
            'icons' => array(
                array(
                    'src' => cc_get_pwa_icon_url(192),
                    'sizes' => '192x192',
                    'type' => 'image/png',
                    'purpose' => 'any maskable',
                ),
                array(
                    'src' => cc_get_pwa_icon_url(512),
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'any maskable',
                ),
            ),
        ),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    exit;
}

function cc_serve_pwa_service_worker() {
    $page_id = isset($_GET['cc_pwa_page_id']) ? (int) $_GET['cc_pwa_page_id'] : 0;
    $scope_url = cc_get_pwa_scope_url($page_id);
    $scope_path = wp_parse_url($scope_url, PHP_URL_PATH);
    if (!is_string($scope_path) || $scope_path === '') {
        $scope_path = '/';
    }

    $sw_poll_status_url = add_query_arg('cc_poll_notify_status', 1, home_url('/'));
    $sw_poll_view_url = add_query_arg('cc_view', 'poll', get_permalink($page_id));
    $sw_icon_url = cc_get_pwa_icon_url(192);

    nocache_headers();
    header('Content-Type: application/javascript; charset=' . get_option('blog_charset'));
    header('Service-Worker-Allowed: ' . $scope_path);

    echo 'const CC_POLL_STATUS_URL = ' . wp_json_encode($sw_poll_status_url) . ";\n";
    echo 'const CC_POLL_VIEW_URL = ' . wp_json_encode($sw_poll_view_url) . ";\n";
    echo 'const CC_ICON_URL = ' . wp_json_encode($sw_icon_url) . ";\n";
    echo "\n";
    echo "self.addEventListener('install', event => {\n";
    echo "  event.waitUntil(self.skipWaiting());\n";
    echo "});\n\n";
    echo "self.addEventListener('activate', event => {\n";
    echo "  event.waitUntil(self.clients.claim());\n";
    echo "});\n\n";
    echo "self.addEventListener('fetch', event => {\n";
    echo "  if (event.request.method !== 'GET') {\n";
    echo "    return;\n";
    echo "  }\n";
    echo "\n";
    echo "  event.respondWith(fetch(event.request));\n";
    echo "});\n";
    echo "\n";
    echo "self.addEventListener('notificationclick', event => {\n";
    echo "  event.notification.close();\n";
    echo "  const targetUrl = event.notification && event.notification.data && event.notification.data.url ? event.notification.data.url : null;\n";
    echo "  if (!targetUrl) {\n";
    echo "    return;\n";
    echo "  }\n";
    echo "\n";
    echo "  event.waitUntil((async () => {\n";
    echo "    const allClients = await clients.matchAll({ type: 'window', includeUncontrolled: true });\n";
    echo "    for (const client of allClients) {\n";
    echo "      if (client.url === targetUrl && 'focus' in client) {\n";
    echo "        await client.focus();\n";
    echo "        return;\n";
    echo "      }\n";
    echo "    }\n";
    echo "\n";
    echo "    if (clients.openWindow) {\n";
    echo "      await clients.openWindow(targetUrl);\n";
    echo "    }\n";
    echo "  })());\n";
    echo "});\n";
    echo "\n";
    echo "self.addEventListener('push', event => {\n";
    echo "  event.waitUntil((async () => {\n";
    echo "    let title = 'Ny afstemning i gruppen';\n";
    echo "    let body  = 'Tryk for at se og stemme.';\n";
    echo "    try {\n";
    echo "      const res = await fetch(CC_POLL_STATUS_URL, { credentials: 'include', cache: 'no-store' });\n";
    echo "      if (res.ok) {\n";
    echo "        const data = await res.json();\n";
    echo "        if (data && data.ok && data.latest_poll) {\n";
    echo "          const p = data.latest_poll;\n";
    echo "          if (p.display_name) title = p.display_name + ' har oprettet en afstemning';\n";
    echo "          if (p.question) body = p.question.length > 110 ? p.question.slice(0, 109) + '\\u2026' : p.question;\n";
    echo "        }\n";
    echo "      }\n";
    echo "    } catch (e) {}\n";
    echo "    await self.registration.showNotification(title, {\n";
    echo "      body:  body,\n";
    echo "      icon:  CC_ICON_URL,\n";
    echo "      badge: CC_ICON_URL,\n";
    echo "      tag:   'cc-new-poll',\n";
    echo "      data:  { url: CC_POLL_VIEW_URL }\n";
    echo "    });\n";
    echo "  })());\n";
    echo "});\n";
    exit;
}

function cc_output_pwa_meta_tags() {
    if (!cc_is_shortcode_page()) {
        return;
    }

    $manifest_url = cc_get_pwa_manifest_url();
    $icon_192_url = cc_get_pwa_icon_url(192);
    $icon_512_url = cc_get_pwa_icon_url(512);

    echo '<link rel="manifest" href="' . esc_url($manifest_url) . '">';
    echo "\n";
    echo '<meta name="theme-color" content="#0f4c5c">';
    echo "\n";
    echo '<meta name="mobile-web-app-capable" content="yes">';
    echo "\n";
    echo '<meta name="apple-mobile-web-app-capable" content="yes">';
    echo "\n";
    echo '<meta name="apple-mobile-web-app-status-bar-style" content="default">';
    echo "\n";
    echo '<meta name="apple-mobile-web-app-title" content="Cruise">';
    echo "\n";
    echo '<meta name="application-name" content="Cruise Companion">';
    echo "\n";
    echo '<link rel="apple-touch-icon" sizes="192x192" href="' . esc_url($icon_192_url) . '">';
    echo "\n";
    echo '<link rel="icon" type="image/png" sizes="192x192" href="' . esc_url($icon_192_url) . '">';
    echo "\n";
    echo '<link rel="icon" type="image/png" sizes="512x512" href="' . esc_url($icon_512_url) . '">';
    echo "\n";
}

function cc_output_pwa_registration_script() {
    if (!cc_is_shortcode_page()) {
        return;
    }

    $page_id = get_queried_object_id();
    $service_worker_url = cc_get_pwa_service_worker_url($page_id);
    $scope_url = cc_get_pwa_scope_url($page_id);
    $poll_status_url = add_query_arg('cc_poll_notify_status', 1, home_url('/'));
    $poll_view_url = add_query_arg('cc_view', 'poll', get_permalink($page_id));
    $effective_user_id = cc_get_effective_user_id();
    $effective_group_id = $effective_user_id > 0 ? cc_current_user_group_id($effective_user_id) : 0;
    $vapid_keys = cc_vapid_get_keys();
    $vapid_public_key_b64 = $vapid_keys && !empty($vapid_keys['public_b64'])
        ? (string) $vapid_keys['public_b64']
        : ($vapid_keys ? cc_base64url_encode((string) $vapid_keys['public_uncompressed']) : '');
    $subscribe_url = add_query_arg('cc_push_subscribe', 1, home_url('/'));

    echo '<script>';
    echo '(function(){';
    echo 'if(!("serviceWorker" in navigator)){return;}';
    echo 'window.addEventListener("load",function(){';
    echo 'navigator.serviceWorker.getRegistrations().then(function(registrations){';
    echo 'if(!registrations||!registrations.length){return;}';
    echo 'registrations.forEach(function(reg){';
    echo 'var scriptUrl=reg&&reg.active&&reg.active.scriptURL?reg.active.scriptURL:"";';
    echo 'if(scriptUrl.indexOf("golfscore-pwa-sw=1")!==-1){reg.unregister();}';
    echo '});';
    echo '}).catch(function(){}).finally(function(){';
    echo 'navigator.serviceWorker.register(' . wp_json_encode($service_worker_url) . ',{scope:' . wp_json_encode($scope_url) . '}).then(function(r){ccSwReg=r;}).catch(function(){});';
    echo '});';
    echo '});';
    echo 'var ccSwReg=null;';
    echo 'var ccNotifyCfg=' . wp_json_encode(array(
        'enabled' => $effective_user_id > 0 && (int) $effective_group_id > 0,
        'statusUrl' => $poll_status_url,
        'pollUrl' => $poll_view_url,
        'userId' => (int) $effective_user_id,
        'groupId' => (int) $effective_group_id,
        'vapidPublicKey' => $vapid_public_key_b64,
        'subscribeUrl' => $subscribe_url,
    )) . ';';
    echo 'if(!ccNotifyCfg.enabled){return;}';
    echo 'if(!("Notification" in window)){return;}';
    echo 'var storageKey="cc_poll_last_seen_"+ccNotifyCfg.groupId+"_"+ccNotifyCfg.userId;';
    echo 'var permissionPromptKey="cc_poll_notify_prompted_"+ccNotifyCfg.groupId+"_"+ccNotifyCfg.userId;';
    echo 'var lock=false;';
    echo 'function truncate(text,max){if(!text){return "";}if(text.length<=max){return text;}return text.slice(0,max-1)+"…";}';
    echo 'function notify(title,body){';
    echo 'if(Notification.permission!=="granted"){return;}';
    echo 'navigator.serviceWorker.getRegistration(' . wp_json_encode($scope_url) . ').then(function(reg){';
    echo 'if(reg&&reg.showNotification){';
    echo 'reg.showNotification(title,{body:body,icon:' . wp_json_encode(cc_get_pwa_icon_url(192)) . ',badge:' . wp_json_encode(cc_get_pwa_icon_url(192)) . ',tag:"cc-new-poll",data:{url:ccNotifyCfg.pollUrl}});';
    echo 'return;';
    echo '}';
    echo 'new Notification(title,{body:body,icon:' . wp_json_encode(cc_get_pwa_icon_url(192)) . '});';
    echo '}).catch(function(){';
    echo 'try{new Notification(title,{body:body,icon:' . wp_json_encode(cc_get_pwa_icon_url(192)) . '});}catch(e){}';
    echo '});';
    echo '}';
    echo 'function ccB64ToUint8Array(b64){var pad="=".repeat((4-b64.length%4)%4);var raw=atob((b64+pad).replace(/-/g,"+").replace(/_/g,"/"));var arr=new Uint8Array(raw.length);for(var i=0;i<raw.length;i++){arr[i]=raw.charCodeAt(i);}return arr;}';
    echo 'function ccSaveSubscription(sub){if(!sub||!ccNotifyCfg.subscribeUrl){return;}var d=sub.toJSON();fetch(ccNotifyCfg.subscribeUrl,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/json"},body:JSON.stringify({endpoint:d.endpoint,keys:d.keys})}).catch(function(){});}';
    echo 'function ccUint8ArrayEquals(a,b){if(!(a instanceof Uint8Array)||!(b instanceof Uint8Array)){return false;}if(a.length!==b.length){return false;}for(var i=0;i<a.length;i++){if(a[i]!==b[i]){return false;}}return true;}';
    echo 'function ccSubscribeToPush(reg){if(!reg||(!("pushManager" in reg))||!ccNotifyCfg.vapidPublicKey){return;}var appKey=ccB64ToUint8Array(ccNotifyCfg.vapidPublicKey);var keyStamp="cc_push_vapid_key_"+ccNotifyCfg.groupId+"_"+ccNotifyCfg.userId;var refreshStamp="cc_push_vapid_refresh_"+ccNotifyCfg.groupId+"_"+ccNotifyCfg.userId;var keyChanged=localStorage.getItem(keyStamp)!==ccNotifyCfg.vapidPublicKey;reg.pushManager.getSubscription().then(function(existing){if(existing){if(keyChanged){existing.unsubscribe().catch(function(){}).finally(function(){reg.pushManager.subscribe({userVisibleOnly:true,applicationServerKey:appKey}).then(function(sub){ccSaveSubscription(sub);localStorage.setItem(keyStamp,ccNotifyCfg.vapidPublicKey);localStorage.setItem(refreshStamp,ccNotifyCfg.vapidPublicKey);}).catch(function(){});});return;}var hasKey=existing.options&&existing.options.applicationServerKey;var oldKey=hasKey?new Uint8Array(existing.options.applicationServerKey):null;if(!oldKey){var didRefresh=localStorage.getItem(refreshStamp)===ccNotifyCfg.vapidPublicKey;if(didRefresh){ccSaveSubscription(existing);localStorage.setItem(keyStamp,ccNotifyCfg.vapidPublicKey);return;}existing.unsubscribe().catch(function(){}).finally(function(){reg.pushManager.subscribe({userVisibleOnly:true,applicationServerKey:appKey}).then(function(sub){ccSaveSubscription(sub);localStorage.setItem(keyStamp,ccNotifyCfg.vapidPublicKey);localStorage.setItem(refreshStamp,ccNotifyCfg.vapidPublicKey);}).catch(function(){});});return;}if(ccUint8ArrayEquals(oldKey,appKey)){ccSaveSubscription(existing);localStorage.setItem(keyStamp,ccNotifyCfg.vapidPublicKey);localStorage.setItem(refreshStamp,ccNotifyCfg.vapidPublicKey);return;}existing.unsubscribe().catch(function(){}).finally(function(){reg.pushManager.subscribe({userVisibleOnly:true,applicationServerKey:appKey}).then(function(sub){ccSaveSubscription(sub);localStorage.setItem(keyStamp,ccNotifyCfg.vapidPublicKey);localStorage.setItem(refreshStamp,ccNotifyCfg.vapidPublicKey);}).catch(function(){});});return;}reg.pushManager.subscribe({userVisibleOnly:true,applicationServerKey:appKey}).then(function(sub){ccSaveSubscription(sub);localStorage.setItem(keyStamp,ccNotifyCfg.vapidPublicKey);localStorage.setItem(refreshStamp,ccNotifyCfg.vapidPublicKey);}).catch(function(){});}).catch(function(){});}';
    echo 'function maybeAskPermission(){';
    echo 'if(Notification.permission==="granted"){';
    echo 'navigator.serviceWorker.ready.then(function(r){ccSubscribeToPush(r);});';
    echo 'return;';
    echo '}';
    echo 'if(Notification.permission!=="default"){return;}';
    echo 'if(localStorage.getItem(permissionPromptKey)==="1"){return;}';
    echo 'localStorage.setItem(permissionPromptKey,"1");';
    echo 'setTimeout(function(){Notification.requestPermission().then(function(perm){if(perm==="granted"){navigator.serviceWorker.ready.then(function(r){ccSubscribeToPush(r);});}}).catch(function(){});},1200);';
    echo '}';
    echo 'function pollStatus(){';
    echo 'if(lock){return;}';
    echo 'lock=true;';
    echo 'fetch(ccNotifyCfg.statusUrl,{credentials:"same-origin",cache:"no-store"}).then(function(res){';
    echo 'if(!res.ok){throw new Error("status_"+res.status);}';
    echo 'return res.json();';
    echo '}).then(function(data){';
    echo 'if(!data||!data.ok||!data.latest_poll){return;}';
    echo 'var latest=data.latest_poll;';
    echo 'var latestId=Number(latest.id||0);';
    echo 'if(!latestId){return;}';
    echo 'var seenRaw=localStorage.getItem(storageKey);';
    echo 'var seenId=seenRaw?Number(seenRaw):0;';
    echo 'if(!seenId){localStorage.setItem(storageKey,String(latestId));return;}';
    echo 'if(latestId<=seenId){return;}';
    echo 'localStorage.setItem(storageKey,String(latestId));';
    echo 'if(Number(latest.user_id||0)===Number(ccNotifyCfg.userId||0)){return;}';
    echo 'if(Notification.permission!=="granted"){return;}';
    echo 'var title=(latest.display_name?latest.display_name+" har oprettet en afstemning":"Ny afstemning i gruppen");';
    echo 'var body=truncate(latest.question||"Tryk for at stemme.",110);';
    echo 'notify(title,body);';
    echo '}).catch(function(){}).finally(function(){lock=false;});';
    echo '}';
    echo 'maybeAskPermission();';
    echo 'setTimeout(pollStatus,900);';
    echo 'setInterval(pollStatus,45000);';
    echo 'document.addEventListener("visibilitychange",function(){if(document.visibilityState==="visible"){pollStatus();}});';
    echo '})();';
    echo '</script>';
    echo "\n";
}

function cc_install_plugin() {
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();

    $groups_table = $wpdb->prefix . 'cc_groups';
    $members_table = $wpdb->prefix . 'cc_group_members';
    $ports_table = $wpdb->prefix . 'cc_ports';
    $days_table = $wpdb->prefix . 'cc_itinerary_days';
    $feed_table = $wpdb->prefix . 'cc_feed_posts';
    $questions_table = $wpdb->prefix . 'cc_quiz_questions';
    $attempts_table = $wpdb->prefix . 'cc_quiz_attempts';
    $polls_table = $wpdb->prefix . 'cc_polls';
    $poll_options_table = $wpdb->prefix . 'cc_poll_options';
    $poll_votes_table = $wpdb->prefix . 'cc_poll_votes';
    $push_subscriptions_table = $wpdb->prefix . 'cc_push_subscriptions';

    $sql = array();

    $sql[] = "CREATE TABLE $groups_table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(190) NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id)
    ) $charset_collate;";

    $sql[] = "CREATE TABLE $members_table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        group_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        role VARCHAR(50) NOT NULL DEFAULT 'member',
        joined_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY cc_group_user_unique (group_id, user_id),
        KEY cc_member_user_idx (user_id)
    ) $charset_collate;";

    $sql[] = "CREATE TABLE $ports_table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(190) NOT NULL,
        country VARCHAR(190) NOT NULL,
        city VARCHAR(190) NOT NULL,
        latitude DECIMAL(10,7) NULL,
        longitude DECIMAL(10,7) NULL,
        terminal_address TEXT NULL,
        timezone VARCHAR(100) NULL,
        currency VARCHAR(20) NULL,
        language VARCHAR(100) NULL,
        emergency_number VARCHAR(50) NULL,
        notes TEXT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id)
    ) $charset_collate;";

    $sql[] = "CREATE TABLE $days_table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        group_id BIGINT UNSIGNED NOT NULL,
        day_number INT UNSIGNED NOT NULL,
        sail_date DATE NOT NULL,
        port_id BIGINT UNSIGNED NULL,
        is_sea_day TINYINT(1) NOT NULL DEFAULT 0,
        arrival_time TIME NULL,
        departure_time TIME NULL,
        all_aboard_time TIME NULL,
        meeting_point VARCHAR(190) NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY cc_group_day_unique (group_id, day_number),
        KEY cc_day_date_idx (sail_date),
        KEY cc_day_group_idx (group_id)
    ) $charset_collate;";

    $sql[] = "CREATE TABLE $feed_table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        group_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        itinerary_day_id BIGINT UNSIGNED NULL,
        post_type VARCHAR(30) NOT NULL DEFAULT 'status',
        content TEXT NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY cc_feed_group_created_idx (group_id, created_at),
        KEY cc_feed_day_idx (itinerary_day_id)
    ) $charset_collate;";

    $sql[] = "CREATE TABLE $questions_table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        itinerary_day_id BIGINT UNSIGNED NOT NULL,
        question_text TEXT NOT NULL,
        option_a VARCHAR(255) NOT NULL,
        option_b VARCHAR(255) NOT NULL,
        option_c VARCHAR(255) NOT NULL,
        option_d VARCHAR(255) NOT NULL,
        correct_option CHAR(1) NOT NULL,
        fun_fact TEXT NULL,
        sort_order INT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY cc_quiz_day_sort_idx (itinerary_day_id, sort_order)
    ) $charset_collate;";

    $sql[] = "CREATE TABLE $attempts_table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        itinerary_day_id BIGINT UNSIGNED NOT NULL,
        group_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        score INT UNSIGNED NOT NULL,
        total_questions INT UNSIGNED NOT NULL,
        answers_json LONGTEXT NULL,
        created_at DATETIME NOT NULL,
        mode VARCHAR(30) NOT NULL DEFAULT 'ranked',
        PRIMARY KEY (id),
        UNIQUE KEY cc_daily_ranked_unique (itinerary_day_id, user_id, mode),
        KEY cc_leaderboard_idx (group_id, itinerary_day_id, score, created_at)
    ) $charset_collate;";

    $sql[] = "CREATE TABLE $polls_table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        group_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        question VARCHAR(255) NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY cc_polls_group_idx (group_id, created_at)
    ) $charset_collate;";

    $sql[] = "CREATE TABLE $poll_options_table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        poll_id BIGINT UNSIGNED NOT NULL,
        option_text VARCHAR(255) NOT NULL,
        sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        KEY cc_poll_options_poll_idx (poll_id, sort_order)
    ) $charset_collate;";

    $sql[] = "CREATE TABLE $poll_votes_table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        poll_id BIGINT UNSIGNED NOT NULL,
        option_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        voted_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY cc_poll_vote_unique (poll_id, user_id),
        KEY cc_poll_votes_option_idx (option_id)
    ) $charset_collate;";

    $sql[] = "CREATE TABLE $push_subscriptions_table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        group_id BIGINT UNSIGNED NOT NULL,
        endpoint TEXT NOT NULL,
        p256dh VARCHAR(255) NOT NULL,
        auth VARCHAR(100) NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY cc_push_sub_user_idx (user_id),
        KEY cc_push_sub_group_idx (group_id)
    ) $charset_collate;";

    foreach ($sql as $statement) {
        dbDelta($statement);
    }

    cc_seed_default_data();
}

function cc_register_admin_page() {
    add_menu_page(
        'Cruise Companion',
        'Cruise Companion',
        'manage_options',
        'cc-admin',
        'cc_render_admin_page',
        'dashicons-palmtree',
        60
    );
}

function cc_render_admin_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Uautoriseret adgang');
    }

    $notice = cc_admin_handle_actions();
    $group_id = cc_get_primary_group_id();
    $ports = cc_admin_get_ports();
    $days = $group_id ? cc_get_itinerary_days($group_id) : array();
    $local_login_entries = cc_get_local_login_entries();
    $local_login_users = get_users(array(
        'orderby' => 'display_name',
        'order' => 'ASC',
        'number' => 200,
        'fields' => array('ID', 'display_name', 'user_login'),
    ));

    $quiz_day_filter = isset($_GET['cc_day_filter']) ? (int) $_GET['cc_day_filter'] : 0;
    $quiz_reveal = isset($_GET['cc_reveal_quiz']) && (int) $_GET['cc_reveal_quiz'] === 1;
    if ($quiz_day_filter <= 0) {
        foreach ($days as $day_item) {
            if ((int) $day_item->is_sea_day === 0) {
                $quiz_day_filter = (int) $day_item->id;
                break;
            }
        }
    }
    $quiz_questions = $quiz_day_filter > 0 ? cc_admin_get_quiz_questions($quiz_day_filter) : array();

    echo '<div class="wrap">';
    echo '<h1>Cruise Companion</h1>';
    echo '<p>Brug shortcoden <code>[cruise_companion]</code> paa en side til rejsedashboardet.</p>';

    if (!empty($notice)) {
        echo '<div class="notice notice-' . esc_attr($notice['type']) . ' is-dismissible"><p>' . esc_html($notice['message']) . '</p></div>';
    }

    echo '<h2 class="nav-tab-wrapper">';
    echo '<a class="nav-tab nav-tab-active" href="#">Cruise opsaetning</a>';
    echo '</h2>';

    echo '<h2>Vedligeholdelse</h2>';
    echo '<form method="post">';
    wp_nonce_field('cc_seed_data_action', 'cc_seed_data_nonce');
    submit_button('Genindlaes demo-data', 'secondary', 'cc_seed_data', false);
    echo ' ';
    wp_nonce_field('cc_apply_test_itinerary_action', 'cc_apply_test_itinerary_nonce');
    submit_button('Opret 2-dages testkrydstogt', 'secondary', 'cc_apply_test_itinerary', false);
    echo ' ';
    wp_nonce_field('cc_apply_official_itinerary_action', 'cc_apply_official_itinerary_nonce');
    submit_button('Anvend officiel cruise-rejseplan', 'primary', 'cc_apply_official_itinerary', false);
    echo '</form>';

    echo '<form method="post" style="margin-top: 12px;">';
    wp_nonce_field('cc_save_local_login_action', 'cc_save_local_login_nonce');
    echo '<h2>Lokal plugin-login (4 brugere)</h2>';
    echo '<p class="description">Brugere kan logge ind direkte i Cruise Companion uden WordPress-login. Konfigurer op til 4 brugere med adgangskoder.</p>';
    echo '<table class="form-table" role="presentation"><tbody>';
    for ($slot = 1; $slot <= 4; $slot++) {
        $entry = isset($local_login_entries[$slot - 1]) ? $local_login_entries[$slot - 1] : null;
        $selected_user_id = $entry ? (int) $entry['user_id'] : 0;

        echo '<tr><th scope="row"><label for="cc_local_login_user_' . esc_attr((string) $slot) . '">Bruger ' . esc_html((string) $slot) . '</label></th><td>';
        echo '<select name="cc_local_login_user_' . esc_attr((string) $slot) . '" id="cc_local_login_user_' . esc_attr((string) $slot) . '">';
        echo '<option value="0">- Ingen -</option>';

        foreach ($local_login_users as $user) {
            $label = (string) $user->display_name . ' (' . (string) $user->user_login . ')';
            echo '<option value="' . esc_attr((string) $user->ID) . '" ' . selected($selected_user_id, (int) $user->ID, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select><br>';
        echo '<input name="cc_local_login_code_' . esc_attr((string) $slot) . '" type="text" class="regular-text" placeholder="Adgangskode">';
        echo '<span class="description"> Lad feltet staa tomt for at beholde eksisterende kode for valgt bruger.</span>';
        echo '</td></tr>';
    }
    echo '</tbody></table>';
    submit_button('Gem lokal login', 'secondary', 'cc_save_local_login');
    echo '</form>';

    $background_image_url = cc_get_background_image_url();
    $overlay_opacity = cc_get_background_overlay_opacity();
    $card_opacity = cc_get_background_card_opacity();
    echo '<form method="post" style="margin-top: 12px;">';
    wp_nonce_field('cc_save_background_action', 'cc_save_background_nonce');
    echo '<table class="form-table" role="presentation"><tbody>';
    echo '<tr><th scope="row"><label for="cc_background_image_url">URL til baggrundsbillede (rutekort)</label></th>';
    echo '<td>';
    echo '<input name="cc_background_image_url" id="cc_background_image_url" type="url" class="regular-text code" placeholder="https://example.com/rutekort.jpg" value="' . esc_attr($background_image_url) . '">';
    echo '<p class="description">Brug en fuld billed-URL fra Mediebiblioteket eller en anden paalidelig kilde. Billedet bruges som baggrund med overlay for laesbarhed.</p>';
    if ($background_image_url !== '') {
        echo '<p><img src="' . esc_url($background_image_url) . '" alt="Forhaandsvisning af rutekort" style="max-width: 240px; height: auto; border: 1px solid #dcdcde; border-radius: 4px;"></p>';
    }
    echo '<p><label for="cc_background_overlay_opacity"><strong>Overlay-moerke (%)</strong></label><br>';
    echo '<input name="cc_background_overlay_opacity" id="cc_background_overlay_opacity" type="number" min="0" max="90" class="small-text" value="' . esc_attr((string) $overlay_opacity) . '">';
    echo '<span class="description"> Lavere vaerdi = mere synligt baggrundsbillede.</span></p>';
    echo '<p><label for="cc_background_card_opacity"><strong>Opacitet for indholdskort (%)</strong></label><br>';
    echo '<input name="cc_background_card_opacity" id="cc_background_card_opacity" type="number" min="35" max="100" class="small-text" value="' . esc_attr((string) $card_opacity) . '">';
    echo '<span class="description"> Lavere vaerdi = mere transparente kort.</span></p>';
    echo '</td></tr>';
    echo '</tbody></table>';
    submit_button('Gem baggrundsbillede', 'secondary', 'cc_save_background');
    echo ' ';
    submit_button('Goer baggrund mere synlig', 'secondary', 'cc_boost_background_visibility', false);
    echo '</form>';

    echo '<form method="post" enctype="multipart/form-data" style="margin-top: 12px;">';
    wp_nonce_field('cc_upload_background_action', 'cc_upload_background_nonce');
    echo '<table class="form-table" role="presentation"><tbody>';
    echo '<tr><th scope="row"><label for="cc_background_upload">Upload baggrundsbillede</label></th>';
    echo '<td>';
    echo '<input name="cc_background_upload" id="cc_background_upload" type="file" accept="image/*">';
    echo '<p class="description">Upload direkte fra din computer. Anbefalet stoerrelse: mindst 1400x900.</p>';
    echo '</td></tr>';
    echo '</tbody></table>';
    submit_button('Upload og brug billede', 'secondary', 'cc_upload_background');
    echo '</form>';

    echo '<hr>';
    $whatsapp_url = cc_get_whatsapp_url();
    echo '<h2>WhatsApp gruppe-link</h2>';
    echo '<form method="post" style="margin-top: 12px;">';
    wp_nonce_field('cc_save_whatsapp_url_action', 'cc_save_whatsapp_url_nonce');
    echo '<table class="form-table" role="presentation"><tbody>';
    echo '<tr><th scope="row"><label for="cc_whatsapp_group_url">WhatsApp gruppe-link</label></th>';
    echo '<td>';
    echo '<input name="cc_whatsapp_group_url" id="cc_whatsapp_group_url" type="url" class="regular-text code" placeholder="https://chat.whatsapp.com/..." value="' . esc_attr($whatsapp_url) . '">';
    echo '<p class="description">Paste dit WhatsApp gruppe-invitationslink her. Det vises paa gruppefeed-siden for alle medlemmer.</p>';
    echo '</td></tr>';
    echo '</tbody></table>';
    submit_button('Gem WhatsApp link', 'secondary', 'cc_save_whatsapp_url');
    echo '</form>';

    echo '<hr>';
    echo '<h2>Havne</h2>';
    echo '<form method="post">';
    wp_nonce_field('cc_add_port_action', 'cc_add_port_nonce');
    echo '<table class="form-table" role="presentation">';
    echo '<tbody>';
    echo '<tr><th scope="row"><label for="cc_port_name">Havnenavn</label></th><td><input name="cc_port_name" id="cc_port_name" type="text" class="regular-text" required></td></tr>';
    echo '<tr><th scope="row"><label for="cc_port_country">Land</label></th><td><input name="cc_port_country" id="cc_port_country" type="text" class="regular-text" required></td></tr>';
    echo '<tr><th scope="row"><label for="cc_port_city">By</label></th><td><input name="cc_port_city" id="cc_port_city" type="text" class="regular-text" required></td></tr>';
    echo '<tr><th scope="row"><label for="cc_port_latitude">Breddegrad</label></th><td><input name="cc_port_latitude" id="cc_port_latitude" type="text" class="small-text" placeholder="55.6761"></td></tr>';
    echo '<tr><th scope="row"><label for="cc_port_longitude">Laengdegrad</label></th><td><input name="cc_port_longitude" id="cc_port_longitude" type="text" class="small-text" placeholder="12.5683"></td></tr>';
    echo '<tr><th scope="row"><label for="cc_port_terminal">Terminaladresse</label></th><td><textarea name="cc_port_terminal" id="cc_port_terminal" class="large-text" rows="2"></textarea></td></tr>';
    echo '<tr><th scope="row"><label for="cc_port_timezone">Tidszone</label></th><td><input name="cc_port_timezone" id="cc_port_timezone" type="text" class="regular-text" placeholder="Europe/Copenhagen"></td></tr>';
    echo '<tr><th scope="row"><label for="cc_port_currency">Valuta</label></th><td><input name="cc_port_currency" id="cc_port_currency" type="text" class="small-text" placeholder="DKK"></td></tr>';
    echo '<tr><th scope="row"><label for="cc_port_language">Sprog</label></th><td><input name="cc_port_language" id="cc_port_language" type="text" class="regular-text" placeholder="Dansk/Engelsk"></td></tr>';
    echo '<tr><th scope="row"><label for="cc_port_emergency">Noednummer</label></th><td><input name="cc_port_emergency" id="cc_port_emergency" type="text" class="small-text" placeholder="112"></td></tr>';
    echo '<tr><th scope="row"><label for="cc_port_notes">Noter</label></th><td><textarea name="cc_port_notes" id="cc_port_notes" class="large-text" rows="3"></textarea></td></tr>';
    echo '</tbody>';
    echo '</table>';
    submit_button('Tilfoej havn', 'primary', 'cc_add_port');
    echo '</form>';

    echo '<table class="widefat striped">';
    echo '<thead><tr><th>Havn</th><th>Land</th><th>By</th><th>Tidszone</th><th>Noednummer</th></tr></thead><tbody>';
    if (empty($ports)) {
        echo '<tr><td colspan="5">Ingen havne endnu.</td></tr>';
    } else {
        foreach ($ports as $port) {
            echo '<tr>';
            echo '<td><strong>' . esc_html((string) $port->name) . '</strong><br><span class="description">' . esc_html((string) $port->terminal_address) . '</span></td>';
            echo '<td>' . esc_html((string) $port->country) . '</td>';
            echo '<td>' . esc_html((string) $port->city) . '</td>';
            echo '<td>' . esc_html((string) $port->timezone) . '</td>';
            echo '<td>' . esc_html((string) $port->emergency_number) . '</td>';
            echo '</tr>';
        }
    }
    echo '</tbody></table>';

    echo '<hr>';
    echo '<h2>Rejseplan-dage</h2>';
    echo '<form method="post">';
    wp_nonce_field('cc_add_day_action', 'cc_add_day_nonce');
    echo '<table class="form-table" role="presentation"><tbody>';
    echo '<tr><th scope="row"><label for="cc_day_number">Dagnummer</label></th><td><input name="cc_day_number" id="cc_day_number" type="number" class="small-text" min="1" required></td></tr>';
    echo '<tr><th scope="row"><label for="cc_sail_date">Dato</label></th><td><input name="cc_sail_date" id="cc_sail_date" type="date" required></td></tr>';
    echo '<tr><th scope="row"><label for="cc_port_id">Havn</label></th><td><select name="cc_port_id" id="cc_port_id">';
    echo '<option value="0">Soedag / ingen havn</option>';
    foreach ($ports as $port) {
        echo '<option value="' . esc_attr((string) $port->id) . '">' . esc_html((string) $port->name . ' (' . $port->city . ')') . '</option>';
    }
    echo '</select></td></tr>';
    echo '<tr><th scope="row">Soedag</th><td><label><input type="checkbox" name="cc_is_sea_day" value="1"> Marker som soedag</label></td></tr>';
    echo '<tr><th scope="row"><label for="cc_arrival_time">Ankomst (TT:MM)</label></th><td><input name="cc_arrival_time" id="cc_arrival_time" type="time"></td></tr>';
    echo '<tr><th scope="row"><label for="cc_departure_time">Afgang (TT:MM)</label></th><td><input name="cc_departure_time" id="cc_departure_time" type="time"></td></tr>';
    echo '<tr><th scope="row"><label for="cc_all_aboard_time">Alle ombord (TT:MM)</label></th><td><input name="cc_all_aboard_time" id="cc_all_aboard_time" type="time"></td></tr>';
    echo '<tr><th scope="row"><label for="cc_meeting_point">Moedested</label></th><td><input name="cc_meeting_point" id="cc_meeting_point" type="text" class="regular-text"></td></tr>';
    echo '</tbody></table>';
    submit_button('Tilfoej rejseplan-dag', 'primary', 'cc_add_day');
    echo '</form>';

    echo '<table class="widefat striped">';
    echo '<thead><tr><th>Dag</th><th>Dato</th><th>Havn</th><th>Ankomst</th><th>Afgang</th><th>Alle ombord</th></tr></thead><tbody>';
    if (empty($days)) {
        echo '<tr><td colspan="6">Ingen rejseplan-dage endnu.</td></tr>';
    } else {
        foreach ($days as $day) {
            echo '<tr>';
            echo '<td>' . esc_html((string) $day->day_number) . '</td>';
            echo '<td>' . esc_html((string) $day->sail_date) . '</td>';
            echo '<td>' . ((int) $day->is_sea_day === 1 ? 'Til soes' : esc_html((string) $day->port_name)) . '</td>';
            echo '<td>' . esc_html((string) $day->arrival_time) . '</td>';
            echo '<td>' . esc_html((string) $day->departure_time) . '</td>';
            echo '<td>' . esc_html((string) $day->all_aboard_time) . '</td>';
            echo '</tr>';
        }
    }
    echo '</tbody></table>';

    echo '<hr>';
    echo '<h2>Daglige havnequiz-spoergsmaal (Multiple Choice)</h2>';
    echo '<form method="get" style="margin-bottom: 12px;">';
    echo '<input type="hidden" name="page" value="cc-admin">';
    echo '<input type="hidden" name="cc_reveal_quiz" value="' . ($quiz_reveal ? '1' : '0') . '">';
    echo '<label for="cc_day_filter"><strong>Quiz-dag:</strong></label> ';
    echo '<select name="cc_day_filter" id="cc_day_filter">';
    foreach ($days as $day) {
        if ((int) $day->is_sea_day === 1) {
            continue;
        }
        $label = 'Dag ' . (string) $day->day_number . ' - ' . (string) $day->sail_date . ' - ' . (string) $day->port_name;
        echo '<option value="' . esc_attr((string) $day->id) . '" ' . selected($quiz_day_filter, (int) $day->id, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select> ';
    submit_button('Indlaes dag', 'secondary', '', false);
    echo '</form>';

    if (!$quiz_reveal) {
        echo '<div class="notice notice-warning inline"><p>Quiz-spoergsmaal og svar er skjult. Tryk paa knappen for at vise eller redigere dem.</p></div>';
        echo '<form method="get" style="margin: 12px 0;">';
        echo '<input type="hidden" name="page" value="cc-admin">';
        echo '<input type="hidden" name="cc_day_filter" value="' . esc_attr((string) $quiz_day_filter) . '">';
        echo '<input type="hidden" name="cc_reveal_quiz" value="1">';
        submit_button('Vis quiz-indhold', 'primary', '', false);
        echo '</form>';
    } else {
        echo '<form method="get" style="margin: 12px 0;">';
        echo '<input type="hidden" name="page" value="cc-admin">';
        echo '<input type="hidden" name="cc_day_filter" value="' . esc_attr((string) $quiz_day_filter) . '">';
        echo '<input type="hidden" name="cc_reveal_quiz" value="0">';
        submit_button('Skjul quiz-indhold', 'secondary', '', false);
        echo '</form>';

        echo '<form method="post">';
        wp_nonce_field('cc_add_question_action', 'cc_add_question_nonce');
        echo '<input type="hidden" name="cc_question_day_id" value="' . esc_attr((string) $quiz_day_filter) . '">';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="cc_question_text">Spoergsmaal</label></th><td><textarea name="cc_question_text" id="cc_question_text" class="large-text" rows="2" required></textarea></td></tr>';
        echo '<tr><th scope="row"><label for="cc_option_a">Svarmulighed A</label></th><td><input name="cc_option_a" id="cc_option_a" type="text" class="regular-text" required></td></tr>';
        echo '<tr><th scope="row"><label for="cc_option_b">Svarmulighed B</label></th><td><input name="cc_option_b" id="cc_option_b" type="text" class="regular-text" required></td></tr>';
        echo '<tr><th scope="row"><label for="cc_option_c">Svarmulighed C</label></th><td><input name="cc_option_c" id="cc_option_c" type="text" class="regular-text" required></td></tr>';
        echo '<tr><th scope="row"><label for="cc_option_d">Svarmulighed D</label></th><td><input name="cc_option_d" id="cc_option_d" type="text" class="regular-text" required></td></tr>';
        echo '<tr><th scope="row"><label for="cc_correct_option">Korrekt svar</label></th><td><select name="cc_correct_option" id="cc_correct_option"><option value="A">A</option><option value="B">B</option><option value="C">C</option><option value="D">D</option></select></td></tr>';
        echo '<tr><th scope="row"><label for="cc_fun_fact">Sjov fakta</label></th><td><textarea name="cc_fun_fact" id="cc_fun_fact" class="large-text" rows="2"></textarea></td></tr>';
        echo '<tr><th scope="row"><label for="cc_sort_order">Sortering</label></th><td><input name="cc_sort_order" id="cc_sort_order" type="number" class="small-text" min="1" value="1"></td></tr>';
        echo '</tbody></table>';
        submit_button('Tilfoej quiz-spoergsmaal', 'primary', 'cc_add_question');
        echo '</form>';

        echo '<p><strong>Antal spoergsmaal for valgt dag:</strong> ' . esc_html((string) count($quiz_questions)) . ' (rankeret quiz kraever praecis 5)</p>';
        echo '<table class="widefat striped">';
        echo '<thead><tr><th>#</th><th>Spoergsmaal</th><th>Korrekt</th><th>Sjov fakta</th></tr></thead><tbody>';
        if (empty($quiz_questions)) {
            echo '<tr><td colspan="4">Ingen spoergsmaal for denne dag endnu.</td></tr>';
        } else {
            foreach ($quiz_questions as $question) {
                echo '<tr>';
                echo '<td>' . esc_html((string) $question->sort_order) . '</td>';
                echo '<td>' . esc_html((string) $question->question_text) . '</td>';
                echo '<td>' . esc_html((string) $question->correct_option) . '</td>';
                echo '<td>' . esc_html((string) $question->fun_fact) . '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table>';
    }

    // ---- Push notifications diagnostic ----
    echo '<hr>';
    echo '<h2>Push-notifikationer (diagnose)</h2>';

    global $wpdb;
    $push_table = $wpdb->prefix . 'cc_push_subscriptions';
    $push_table_exists = $wpdb->get_var("SHOW TABLES LIKE '$push_table'") === $push_table;

    $vapid_keys_stored = cc_vapid_get_keys();
    $vapid_ok = $vapid_keys_stored !== null;

    echo '<table class="form-table"><tbody>';
    echo '<tr><th>VAPID-nogler</th><td>';
    if ($vapid_ok) {
        echo '<span style="color:green;">&#10003; Genereret og gemt</span>';
    } else {
        echo '<span style="color:red;">&#10007; Mangler – OpenSSL EC (prime256v1) er sandsynligvis ikke tilgaengeligt paa denne server</span>';
    }
    echo '</td></tr>';
    echo '<tr><th>Push-abonnementstabel</th><td>';
    if ($push_table_exists) {
        echo '<span style="color:green;">&#10003; Eksisterer (' . esc_html($push_table) . ')</span>';
    } else {
        echo '<span style="color:red;">&#10007; Mangler – deaktiver og genaktiver pluginnet for at oprette den</span>';
    }
    echo '</td></tr>';

    if ($push_table_exists) {
        $subscriptions = $wpdb->get_results("SELECT ps.id, ps.user_id, ps.endpoint, ps.created_at, u.display_name FROM $push_table ps LEFT JOIN {$wpdb->users} u ON u.ID = ps.user_id ORDER BY ps.created_at DESC");
        echo '<tr><th>Gemte abonnementer</th><td>';
        if (empty($subscriptions)) {
            echo '<span style="color:orange;">Ingen endnu. Aabne appen i Chrome/Android, tillad notifikationer, og genindlaes siden her.</span>';
        } else {
            echo '<ul style="margin:0;">';
            foreach ($subscriptions as $sub) {
                $ep_short = substr((string) $sub->endpoint, 0, 60) . '…';
                $label = (string) $sub->display_name !== '' ? (string) $sub->display_name : ('user#' . (int) $sub->user_id);
                echo '<li style="margin:0 0 10px 0;">';
                echo esc_html($label) . ': <code>' . esc_html($ep_short) . '</code> (' . esc_html((string) $sub->created_at) . ')';
                if ($vapid_ok) {
                    echo '<form method="post" style="margin-top:6px;">';
                    wp_nonce_field('cc_test_push_action', 'cc_test_push_nonce');
                    echo '<input type="hidden" name="cc_test_push_subscription_id" value="' . esc_attr((string) $sub->id) . '">';
                    submit_button('Send test-push til denne abonnent', 'secondary', 'cc_test_push', false);
                    echo '</form>';
                }
                echo '</li>';
            }
            echo '</ul>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table>';

    if ($vapid_ok && $push_table_exists) {
        echo '<form method="post" style="margin-top:12px;">';
        wp_nonce_field('cc_test_push_action', 'cc_test_push_nonce');
        submit_button('Send test-push til alle gemte abonnementer', 'primary', 'cc_test_push', false);
        echo '</form>';

        echo '<form method="post" style="margin-top:8px;">';
        wp_nonce_field('cc_cleanup_push_action', 'cc_cleanup_push_nonce');
        submit_button('Ryd op i push-abonnementer (behold seneste 2 pr. bruger)', 'secondary', 'cc_cleanup_push', false);
        echo '</form>';

        echo '<form method="post" style="margin-top:8px;">';
        wp_nonce_field('cc_reset_vapid_action', 'cc_reset_vapid_nonce');
        submit_button('Nulstil VAPID-noegler og slet alle abonnementer', 'delete', 'cc_reset_vapid', false);
        echo '</form>';
    }
    // ---- end push diagnostic ----

    echo '</div>';
}

function cc_get_primary_group_id() {
    global $wpdb;

    $groups_table = $wpdb->prefix . 'cc_groups';
    $group_id = (int) $wpdb->get_var("SELECT id FROM $groups_table ORDER BY id ASC LIMIT 1");
    return $group_id;
}

function cc_admin_get_ports() {
    global $wpdb;

    $ports_table = $wpdb->prefix . 'cc_ports';
    return $wpdb->get_results("SELECT * FROM $ports_table ORDER BY name ASC");
}

function cc_admin_get_quiz_questions($day_id) {
    global $wpdb;

    $questions_table = $wpdb->prefix . 'cc_quiz_questions';
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $questions_table WHERE itinerary_day_id = %d ORDER BY sort_order ASC, id ASC",
        (int) $day_id
    ));
}

function cc_sanitize_time_or_null($value) {
    $time = sanitize_text_field((string) $value);
    if ($time === '') {
        return null;
    }

    if (!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $time)) {
        return null;
    }

    return $time . ':00';
}

function cc_get_background_image_url() {
    $attachment_id = (int) get_option('cc_background_image_attachment_id', 0);
    if ($attachment_id > 0) {
        $attachment_url = wp_get_attachment_url($attachment_id);
        if (is_string($attachment_url) && $attachment_url !== '') {
            return $attachment_url;
        }
    }

    $value = get_option('cc_background_image_url', '');
    return is_string($value) ? $value : '';
}

function cc_get_background_overlay_opacity() {
    $value = (int) get_option('cc_background_overlay_opacity', 42);
    if ($value < 0) {
        $value = 0;
    }
    if ($value > 90) {
        $value = 90;
    }

    return $value;
}

function cc_get_background_card_opacity() {
    $value = (int) get_option('cc_background_card_opacity', 72);
    if ($value < 35) {
        $value = 35;
    }
    if ($value > 100) {
        $value = 100;
    }

    return $value;
}

function cc_get_whatsapp_url() {
    $value = get_option('cc_whatsapp_group_url', '');
    return is_string($value) ? $value : '';
}

function cc_get_local_login_entries() {
    $raw = get_option('cc_local_login_entries', array());
    if (!is_array($raw)) {
        return array();
    }

    $entries = array();
    foreach ($raw as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $user_id = isset($entry['user_id']) ? (int) $entry['user_id'] : 0;
        $passcode_hash = isset($entry['passcode_hash']) ? (string) $entry['passcode_hash'] : '';
        if ($user_id <= 0 || $passcode_hash === '') {
            continue;
        }

        $user = get_userdata($user_id);
        if (!$user) {
            continue;
        }

        $entries[] = array(
            'user_id' => $user_id,
            'passcode_hash' => $passcode_hash,
        );
    }

    return array_values($entries);
}

function cc_get_local_login_entry_for_user($user_id) {
    $user_id = (int) $user_id;
    if ($user_id <= 0) {
        return null;
    }

    $entries = cc_get_local_login_entries();
    foreach ($entries as $entry) {
        if ((int) $entry['user_id'] === $user_id) {
            return $entry;
        }
    }

    return null;
}

function cc_set_local_login_cookie($user_id) {
    $user_id = (int) $user_id;
    if ($user_id <= 0) {
        return;
    }

    $expires = time() + (14 * DAY_IN_SECONDS);
    $payload = $user_id . '|' . $expires;
    $signature = hash_hmac('sha256', $payload, wp_salt('auth'));
    $cookie_value = $payload . '|' . $signature;

    $secure = is_ssl();
    $httponly = true;
    $path = defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/';
    setcookie(CC_LOCAL_AUTH_COOKIE, $cookie_value, $expires, $path, COOKIE_DOMAIN, $secure, $httponly);
    if (defined('SITECOOKIEPATH') && SITECOOKIEPATH && SITECOOKIEPATH !== $path) {
        setcookie(CC_LOCAL_AUTH_COOKIE, $cookie_value, $expires, SITECOOKIEPATH, COOKIE_DOMAIN, $secure, $httponly);
    }

    $_COOKIE[CC_LOCAL_AUTH_COOKIE] = $cookie_value;
}

function cc_clear_local_login_cookie() {
    $secure = is_ssl();
    $httponly = true;
    $path = defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/';

    setcookie(CC_LOCAL_AUTH_COOKIE, '', time() - HOUR_IN_SECONDS, $path, COOKIE_DOMAIN, $secure, $httponly);
    if (defined('SITECOOKIEPATH') && SITECOOKIEPATH && SITECOOKIEPATH !== $path) {
        setcookie(CC_LOCAL_AUTH_COOKIE, '', time() - HOUR_IN_SECONDS, SITECOOKIEPATH, COOKIE_DOMAIN, $secure, $httponly);
    }

    unset($_COOKIE[CC_LOCAL_AUTH_COOKIE]);
}

function cc_get_local_logged_in_user_id() {
    if (empty($_COOKIE[CC_LOCAL_AUTH_COOKIE])) {
        return 0;
    }

    $raw = (string) $_COOKIE[CC_LOCAL_AUTH_COOKIE];
    $parts = explode('|', $raw);
    if (count($parts) !== 3) {
        return 0;
    }

    $user_id = (int) $parts[0];
    $expires = (int) $parts[1];
    $signature = (string) $parts[2];
    if ($user_id <= 0 || $expires <= time()) {
        return 0;
    }

    $payload = $user_id . '|' . $expires;
    $expected_signature = hash_hmac('sha256', $payload, wp_salt('auth'));
    if (!hash_equals($expected_signature, $signature)) {
        return 0;
    }

    $entry = cc_get_local_login_entry_for_user($user_id);
    return $entry ? $user_id : 0;
}

function cc_get_effective_user_id() {
    if (is_user_logged_in()) {
        return (int) get_current_user_id();
    }

    return cc_get_local_logged_in_user_id();
}

function cc_admin_save_local_login_settings() {
    global $wpdb;
    check_admin_referer('cc_save_local_login_action', 'cc_save_local_login_nonce');

    $existing_entries = cc_get_local_login_entries();
    $existing_map = array();
    foreach ($existing_entries as $entry) {
        $existing_map[(int) $entry['user_id']] = (string) $entry['passcode_hash'];
    }

    $entries = array();
    $seen_users = array();

    for ($slot = 1; $slot <= 4; $slot++) {
        $user_id = isset($_POST['cc_local_login_user_' . $slot]) ? (int) $_POST['cc_local_login_user_' . $slot] : 0;
        if ($user_id <= 0 || isset($seen_users[$user_id])) {
            continue;
        }

        $code = isset($_POST['cc_local_login_code_' . $slot]) ? (string) wp_unslash($_POST['cc_local_login_code_' . $slot]) : '';
        $code = trim($code);

        $passcode_hash = '';
        if ($code !== '') {
            $passcode_hash = wp_hash_password($code);
        } elseif (isset($existing_map[$user_id])) {
            $passcode_hash = $existing_map[$user_id];
        }

        if ($passcode_hash === '') {
            continue;
        }

        $entries[] = array(
            'user_id' => $user_id,
            'passcode_hash' => $passcode_hash,
        );
        $seen_users[$user_id] = true;
    }

    update_option('cc_local_login_entries', $entries, false);

    // Automatically add configured users to the group members table if not already present.
    $group_id = cc_get_primary_group_id();
    if ($group_id > 0) {
        $members_table = $wpdb->prefix . 'cc_group_members';
        foreach ($entries as $entry) {
            $user_id = (int) $entry['user_id'];
            $exists = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $members_table WHERE group_id = %d AND user_id = %d",
                $group_id,
                $user_id
            ));
            if ($exists === 0) {
                $wpdb->insert(
                    $members_table,
                    array(
                        'group_id' => $group_id,
                        'user_id' => $user_id,
                        'role' => 'member',
                        'joined_at' => current_time('mysql'),
                    ),
                    array('%d', '%d', '%s', '%s')
                );
            }
        }
    }

    if (empty($entries)) {
        return array('type' => 'success', 'message' => 'Lokal plugin-login er nulstillet. Ingen brugere er aktive.');
    }

    return array('type' => 'success', 'message' => 'Lokal plugin-login er gemt for ' . count($entries) . ' bruger(e).');
}

function cc_save_background_visibility_settings_from_post() {
    $overlay = isset($_POST['cc_background_overlay_opacity']) ? (int) $_POST['cc_background_overlay_opacity'] : cc_get_background_overlay_opacity();
    $card = isset($_POST['cc_background_card_opacity']) ? (int) $_POST['cc_background_card_opacity'] : cc_get_background_card_opacity();

    if ($overlay < 0) {
        $overlay = 0;
    }
    if ($overlay > 90) {
        $overlay = 90;
    }

    if ($card < 35) {
        $card = 35;
    }
    if ($card > 100) {
        $card = 100;
    }

    update_option('cc_background_overlay_opacity', $overlay);
    update_option('cc_background_card_opacity', $card);
}

function cc_admin_handle_actions() {
    if (!isset($_POST) || empty($_POST)) {
        return null;
    }

    if (isset($_POST['cc_seed_data'])) {
        check_admin_referer('cc_seed_data_action', 'cc_seed_data_nonce');
        cc_seed_default_data(true);
        return array(
            'type' => 'success',
            'message' => 'Demo-data er indlaest.',
        );
    }

    if (isset($_POST['cc_apply_official_itinerary'])) {
        check_admin_referer('cc_apply_official_itinerary_action', 'cc_apply_official_itinerary_nonce');
        cc_apply_official_itinerary();
        return array(
            'type' => 'success',
            'message' => 'Officiel cruise-rejseplan er anvendt paa havne og dage.',
        );
    }

    if (isset($_POST['cc_apply_test_itinerary'])) {
        check_admin_referer('cc_apply_test_itinerary_action', 'cc_apply_test_itinerary_nonce');
        cc_apply_test_itinerary();
        return array(
            'type' => 'success',
            'message' => '2-dages testkrydstogt er oprettet med havne og quiz for i dag og i morgen.',
        );
    }

    if (isset($_POST['cc_save_background'])) {
        check_admin_referer('cc_save_background_action', 'cc_save_background_nonce');
        $raw_url = wp_unslash($_POST['cc_background_image_url'] ?? '');
        $clean_url = esc_url_raw($raw_url);
        update_option('cc_background_image_url', $clean_url);
        if ($clean_url !== '') {
            update_option('cc_background_image_attachment_id', 0);
        }
        cc_save_background_visibility_settings_from_post();
        return array(
            'type' => 'success',
            'message' => 'Baggrundsbillede er gemt.',
        );
    }

    if (isset($_POST['cc_boost_background_visibility'])) {
        check_admin_referer('cc_save_background_action', 'cc_save_background_nonce');
        update_option('cc_background_overlay_opacity', 25);
        update_option('cc_background_card_opacity', 55);
        return array(
            'type' => 'success',
            'message' => 'Baggrundssynlighed er oget. Du kan finjustere vaerdierne nedenfor.',
        );
    }

    if (isset($_POST['cc_upload_background'])) {
        return cc_admin_upload_background_image();
    }

    if (isset($_POST['cc_save_whatsapp_url'])) {
        check_admin_referer('cc_save_whatsapp_url_action', 'cc_save_whatsapp_url_nonce');
        $raw_url = wp_unslash($_POST['cc_whatsapp_group_url'] ?? '');
        $clean_url = esc_url_raw($raw_url);
        update_option('cc_whatsapp_group_url', $clean_url);
        return array(
            'type' => 'success',
            'message' => 'WhatsApp gruppe-link er gemt.',
        );
    }

    if (isset($_POST['cc_save_local_login'])) {
        return cc_admin_save_local_login_settings();
    }

    if (isset($_POST['cc_add_port'])) {
        return cc_admin_add_port();
    }

    if (isset($_POST['cc_add_day'])) {
        return cc_admin_add_day();
    }

    if (isset($_POST['cc_add_question'])) {
        return cc_admin_add_question();
    }

    if (isset($_POST['cc_test_push'])) {
        check_admin_referer('cc_test_push_action', 'cc_test_push_nonce');
        $subscription_id = isset($_POST['cc_test_push_subscription_id']) ? (int) $_POST['cc_test_push_subscription_id'] : 0;
        return cc_admin_send_test_push($subscription_id);
    }

    if (isset($_POST['cc_cleanup_push'])) {
        check_admin_referer('cc_cleanup_push_action', 'cc_cleanup_push_nonce');
        return cc_admin_cleanup_push_subscriptions();
    }

    if (isset($_POST['cc_reset_vapid'])) {
        check_admin_referer('cc_reset_vapid_action', 'cc_reset_vapid_nonce');
        return cc_admin_reset_vapid_and_subscriptions();
    }

    return null;
}

function cc_admin_reset_vapid_and_subscriptions() {
    global $wpdb;

    $table = $wpdb->prefix . 'cc_push_subscriptions';
    if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
        $wpdb->query("DELETE FROM $table");
    }

    delete_option('cc_vapid_keys');
    $keys = cc_vapid_get_keys();
    if (!$keys) {
        return array(
            'type' => 'error',
            'message' => 'VAPID blev nulstillet, men nye noegler kunne ikke genereres. Tjek OpenSSL EC (prime256v1).',
        );
    }

    return array(
        'type' => 'success',
        'message' => 'VAPID-noegler er nulstillet og alle push-abonnementer er slettet. Aabn appen paa enhederne for ny tilmelding.',
    );
}

function cc_admin_cleanup_push_subscriptions() {
    global $wpdb;

    $table = $wpdb->prefix . 'cc_push_subscriptions';
    if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
        return array('type' => 'error', 'message' => 'cc_push_subscriptions-tabellen eksisterer ikke.');
    }

    $before = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");

    $pairs = $wpdb->get_results("SELECT DISTINCT user_id, group_id FROM $table");
    if (!empty($pairs)) {
        foreach ($pairs as $pair) {
            cc_prune_push_subscriptions((int) $pair->user_id, (int) $pair->group_id, 2);
        }
    }

    $after = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
    $removed = max(0, $before - $after);

    return array(
        'type' => 'success',
        'message' => 'Push-oprydning fuldfoert. Slettede ' . $removed . ' abonnement(er). Tilbage: ' . $after . '.',
    );
}

function cc_admin_upload_background_image() {
    check_admin_referer('cc_upload_background_action', 'cc_upload_background_nonce');

    if (empty($_FILES['cc_background_upload']) || !is_array($_FILES['cc_background_upload'])) {
        return array('type' => 'error', 'message' => 'Ingen fil blev valgt til upload.');
    }

    $file = $_FILES['cc_background_upload'];
    if (!isset($file['error']) || (int) $file['error'] === UPLOAD_ERR_NO_FILE) {
        return array('type' => 'error', 'message' => 'Vaelg venligst en billedfil foerst.');
    }

    if ((int) $file['error'] !== UPLOAD_ERR_OK) {
        return array('type' => 'error', 'message' => 'Upload fejlede. Proev igen.');
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    $attachment_id = media_handle_upload('cc_background_upload', 0, array(), array('test_form' => false));
    if (is_wp_error($attachment_id)) {
        return array('type' => 'error', 'message' => 'Upload fejlede: ' . $attachment_id->get_error_message());
    }

    $uploaded_url = wp_get_attachment_url($attachment_id);
    if (!is_string($uploaded_url) || $uploaded_url === '') {
        return array('type' => 'error', 'message' => 'Upload lykkedes, men billedets URL kunne ikke bestemmes.');
    }

    update_option('cc_background_image_attachment_id', (int) $attachment_id);
    update_option('cc_background_image_url', esc_url_raw($uploaded_url));
    cc_save_background_visibility_settings_from_post();

    return array('type' => 'success', 'message' => 'Baggrundsbillede er uploadet og anvendt.');
}

function cc_admin_add_port() {
    global $wpdb;

    check_admin_referer('cc_add_port_action', 'cc_add_port_nonce');

    $name = sanitize_text_field(wp_unslash($_POST['cc_port_name'] ?? ''));
    $country = sanitize_text_field(wp_unslash($_POST['cc_port_country'] ?? ''));
    $city = sanitize_text_field(wp_unslash($_POST['cc_port_city'] ?? ''));

    if ($name === '' || $country === '' || $city === '') {
        return array('type' => 'error', 'message' => 'Havnenavn, land og by er paakraevet.');
    }

    $ports_table = $wpdb->prefix . 'cc_ports';
    $wpdb->insert(
        $ports_table,
        array(
            'name' => $name,
            'country' => $country,
            'city' => $city,
            'latitude' => cc_sanitize_decimal_or_null($_POST['cc_port_latitude'] ?? ''),
            'longitude' => cc_sanitize_decimal_or_null($_POST['cc_port_longitude'] ?? ''),
            'terminal_address' => sanitize_textarea_field(wp_unslash($_POST['cc_port_terminal'] ?? '')),
            'timezone' => sanitize_text_field(wp_unslash($_POST['cc_port_timezone'] ?? '')),
            'currency' => sanitize_text_field(wp_unslash($_POST['cc_port_currency'] ?? '')),
            'language' => sanitize_text_field(wp_unslash($_POST['cc_port_language'] ?? '')),
            'emergency_number' => sanitize_text_field(wp_unslash($_POST['cc_port_emergency'] ?? '')),
            'notes' => sanitize_textarea_field(wp_unslash($_POST['cc_port_notes'] ?? '')),
            'created_at' => current_time('mysql'),
        ),
        array('%s', '%s', '%s', '%f', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
    );

    return array('type' => 'success', 'message' => 'Havn er tilfoejet.');
}

function cc_admin_add_day() {
    global $wpdb;

    check_admin_referer('cc_add_day_action', 'cc_add_day_nonce');

    $group_id = cc_get_primary_group_id();
    if ($group_id <= 0) {
        return array('type' => 'error', 'message' => 'Ingen gruppe fundet. Genindlaes demo-data foerst.');
    }

    $day_number = isset($_POST['cc_day_number']) ? (int) $_POST['cc_day_number'] : 0;
    $sail_date = sanitize_text_field(wp_unslash($_POST['cc_sail_date'] ?? ''));

    if ($day_number <= 0 || $sail_date === '') {
        return array('type' => 'error', 'message' => 'Dagnummer og dato er paakraevet.');
    }

    $is_sea_day = isset($_POST['cc_is_sea_day']) ? 1 : 0;
    $port_id = isset($_POST['cc_port_id']) ? (int) $_POST['cc_port_id'] : 0;
    if ($is_sea_day === 1) {
        $port_id = 0;
    }

    $days_table = $wpdb->prefix . 'cc_itinerary_days';
    $wpdb->insert(
        $days_table,
        array(
            'group_id' => $group_id,
            'day_number' => $day_number,
            'sail_date' => $sail_date,
            'port_id' => $port_id > 0 ? $port_id : null,
            'is_sea_day' => $is_sea_day,
            'arrival_time' => cc_sanitize_time_or_null($_POST['cc_arrival_time'] ?? ''),
            'departure_time' => cc_sanitize_time_or_null($_POST['cc_departure_time'] ?? ''),
            'all_aboard_time' => cc_sanitize_time_or_null($_POST['cc_all_aboard_time'] ?? ''),
            'meeting_point' => sanitize_text_field(wp_unslash($_POST['cc_meeting_point'] ?? '')),
            'created_at' => current_time('mysql'),
        ),
        array('%d', '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s')
    );

    if ($wpdb->last_error) {
        return array('type' => 'error', 'message' => 'Kunne ikke tilfoeje rejseplan-dag. Tjek for duplikeret dagnummer.');
    }

    return array('type' => 'success', 'message' => 'Rejseplan-dag er tilfoejet.');
}

function cc_admin_add_question() {
    global $wpdb;

    check_admin_referer('cc_add_question_action', 'cc_add_question_nonce');

    $day_id = isset($_POST['cc_question_day_id']) ? (int) $_POST['cc_question_day_id'] : 0;
    if ($day_id <= 0) {
        return array('type' => 'error', 'message' => 'Vaelg en gyldig rejseplan-dag foer du tilfoejer quiz-spoergsmaal.');
    }

    $question_text = sanitize_textarea_field(wp_unslash($_POST['cc_question_text'] ?? ''));
    $option_a = sanitize_text_field(wp_unslash($_POST['cc_option_a'] ?? ''));
    $option_b = sanitize_text_field(wp_unslash($_POST['cc_option_b'] ?? ''));
    $option_c = sanitize_text_field(wp_unslash($_POST['cc_option_c'] ?? ''));
    $option_d = sanitize_text_field(wp_unslash($_POST['cc_option_d'] ?? ''));
    $correct_option = strtoupper(sanitize_text_field(wp_unslash($_POST['cc_correct_option'] ?? 'A')));
    $fun_fact = sanitize_textarea_field(wp_unslash($_POST['cc_fun_fact'] ?? ''));
    $sort_order = isset($_POST['cc_sort_order']) ? max(1, (int) $_POST['cc_sort_order']) : 1;

    if ($question_text === '' || $option_a === '' || $option_b === '' || $option_c === '' || $option_d === '') {
        return array('type' => 'error', 'message' => 'Spoergsmaal og alle fire svarmuligheder er paakraevet.');
    }

    if (!in_array($correct_option, array('A', 'B', 'C', 'D'), true)) {
        return array('type' => 'error', 'message' => 'Korrekt svar skal vaere A, B, C eller D.');
    }

    $questions_table = $wpdb->prefix . 'cc_quiz_questions';
    $wpdb->insert(
        $questions_table,
        array(
            'itinerary_day_id' => $day_id,
            'question_text' => $question_text,
            'option_a' => $option_a,
            'option_b' => $option_b,
            'option_c' => $option_c,
            'option_d' => $option_d,
            'correct_option' => $correct_option,
            'fun_fact' => $fun_fact,
            'sort_order' => $sort_order,
            'created_at' => current_time('mysql'),
        ),
        array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s')
    );

    return array('type' => 'success', 'message' => 'Quiz-spoergsmaal er tilfoejet.');
}

function cc_admin_send_test_push($subscription_id = 0) {
    $vapid_keys = cc_vapid_get_keys();
    if (!$vapid_keys) {
        return array('type' => 'error', 'message' => 'VAPID-nogler mangler. OpenSSL EC (prime256v1) er ikke tilgaengeligt paa denne server.');
    }

    global $wpdb;
    $table = $wpdb->prefix . 'cc_push_subscriptions';
    if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
        return array('type' => 'error', 'message' => 'cc_push_subscriptions-tabellen eksisterer ikke. Deaktiver og genaktiver pluginnet.');
    }

    $subscription_id = (int) $subscription_id;
    if ($subscription_id > 0) {
        $subscriptions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $subscription_id
        ));
    } else {
        $subscriptions = $wpdb->get_results("SELECT * FROM $table");
    }

    if (empty($subscriptions)) {
        if ($subscription_id > 0) {
            return array('type' => 'error', 'message' => 'Det valgte abonnement findes ikke laengere. Opdater siden og proev igen.');
        }
        return array('type' => 'error', 'message' => 'Ingen abonnementer fundet. Aabne appen i Chrome/Android, tillad notifikationer, og proev igen.');
    }

    $user_names = array();
    $user_ids = array();
    foreach ($subscriptions as $sub) {
        $uid = (int) $sub->user_id;
        if ($uid > 0) {
            $user_ids[$uid] = true;
        }
    }
    if (!empty($user_ids)) {
        $users = get_users(array(
            'include' => array_keys($user_ids),
            'fields' => array('ID', 'display_name'),
        ));
        foreach ($users as $user) {
            $user_names[(int) $user->ID] = (string) $user->display_name;
        }
    }

    $results = array();
    foreach ($subscriptions as $sub) {
        $result = cc_send_web_push_blocking(
            (string) $sub->endpoint,
            (string) $vapid_keys['public_uncompressed'],
            (string) $vapid_keys['private_pem']
        );
        $endpoint_host = wp_parse_url((string) $sub->endpoint, PHP_URL_HOST);
        if (!is_string($endpoint_host) || $endpoint_host === '') {
            $endpoint_host = 'ukendt-host';
        }
        $label = isset($user_names[(int) $sub->user_id]) ? $user_names[(int) $sub->user_id] : ('user#' . (int) $sub->user_id);
        $is_vapid_mismatch = $result['status'] === 403
            && stripos((string) $result['error'], 'vapid credentials') !== false;

        $results[] = $label . ' @ ' . $endpoint_host . ': HTTP ' . $result['status'] . ($result['error'] ? ' – ' . $result['error'] : ' OK');
        if ($result['status'] === 410 || $result['status'] === 404 || $is_vapid_mismatch) {
            $wpdb->delete($table, array('id' => (int) $sub->id), array('%d'));
            if ($is_vapid_mismatch) {
                $results[] = $label . ' @ ' . $endpoint_host . ': abonnement slettet pga. VAPID-noegle-mismatch; aabn appen igen for automatisk ny tilmelding';
            }
        }
    }

    return array(
        'type' => 'info',
        'message' => ($subscription_id > 0 ? 'Test-push-resultat for valgt abonnent: ' : 'Test-push-resultater (' . count($subscriptions) . ' abonnement(er)): ') . implode(' | ', $results),
    );
}

function cc_send_web_push_blocking($endpoint, $vapid_public_uncompressed, $private_key_pem) {
    $jwt = cc_vapid_build_jwt($endpoint, $private_key_pem);
    if (!$jwt) {
        return array('ok' => false, 'status' => 0, 'error' => 'JWT-signering mislykkedes');
    }

    $public_key_b64 = cc_base64url_encode((string) $vapid_public_uncompressed);
    $authorization_header = cc_build_webpush_authorization_header($endpoint, $jwt, $public_key_b64);

    $response = wp_remote_post($endpoint, array(
        'timeout'  => 15,
        'blocking' => true,
        'headers'  => array(
            'Authorization' => $authorization_header,
            // Keep backwards compatibility with push services that still read VAPID key from Crypto-Key.
            'Crypto-Key'    => 'p256ecdsa=' . $public_key_b64,
            'TTL'           => '86400',
            'Urgency'       => 'normal',
        ),
        'body'     => '',
    ));

    if (is_wp_error($response)) {
        return array('ok' => false, 'status' => 0, 'error' => $response->get_error_message());
    }

    $status = (int) wp_remote_retrieve_response_code($response);
    $body   = wp_remote_retrieve_body($response);
    $ok     = $status >= 200 && $status < 300;

    return array('ok' => $ok, 'status' => $status, 'error' => $ok ? '' : substr($body, 0, 200));
}

function cc_seed_default_data($force = false) {
    global $wpdb;

    $groups_table = $wpdb->prefix . 'cc_groups';
    $members_table = $wpdb->prefix . 'cc_group_members';
    $ports_table = $wpdb->prefix . 'cc_ports';
    $days_table = $wpdb->prefix . 'cc_itinerary_days';
    $questions_table = $wpdb->prefix . 'cc_quiz_questions';

    $group_id = (int) $wpdb->get_var("SELECT id FROM $groups_table ORDER BY id ASC LIMIT 1");

    if (!$group_id || $force) {
        if ($force) {
            $wpdb->query("TRUNCATE TABLE $groups_table");
            $wpdb->query("TRUNCATE TABLE $members_table");
            $wpdb->query("TRUNCATE TABLE $ports_table");
            $wpdb->query("TRUNCATE TABLE $days_table");
            $wpdb->query("TRUNCATE TABLE $questions_table");
            $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'cc_feed_posts');
            $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'cc_quiz_attempts');
        }

        $wpdb->insert(
            $groups_table,
            array(
                'name' => 'Cruise Gruppe',
                'created_at' => current_time('mysql'),
            ),
            array('%s', '%s')
        );
        $group_id = (int) $wpdb->insert_id;
    }

    $existing_members = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $members_table WHERE group_id = %d",
        $group_id
    ));

    if ($existing_members < 4 || $force) {
        $users = get_users(array(
            'number' => 4,
            'orderby' => 'registered',
            'order' => 'ASC',
            'fields' => array('ID'),
        ));

        foreach ($users as $user) {
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO $members_table (group_id, user_id, role, joined_at) VALUES (%d, %d, %s, %s)",
                $group_id,
                (int) $user->ID,
                'member',
                current_time('mysql')
            ));
        }
    }

    $existing_ports = (int) $wpdb->get_var("SELECT COUNT(*) FROM $ports_table");
    if ($existing_ports === 0 || $force) {
        $ports = array(
            array('Kobenhavn', 'Danmark', 'Kobenhavn', 55.6761, 12.5683, 'Oceankaj Cruise Terminal, Kobenhavn', 'Europe/Copenhagen'),
            array('Warnemunde (Berlin)', 'Tyskland', 'Warnemunde', 54.1766, 12.0887, 'Warnemunde Cruise Center, Rostock', 'Europe/Berlin'),
            array('Gdynia (Gdansk-Danzig)', 'Polen', 'Gdynia', 54.5189, 18.5305, 'French Quay, Gdynia Port', 'Europe/Warsaw'),
            array('Klaipeda', 'Litauen', 'Klaipeda', 55.7033, 21.1443, 'Cruise Ship Terminal, Klaipeda', 'Europe/Vilnius'),
            array('Riga', 'Letland', 'Riga', 56.9496, 24.1052, 'Riga Passenger Port', 'Europe/Riga'),
            array('Stockholm', 'Sverige', 'Stockholm', 59.3293, 18.0686, 'Stadsgarden / Frihamnen', 'Europe/Stockholm'),
        );

        foreach ($ports as $port) {
            $wpdb->insert(
                $ports_table,
                array(
                    'name' => $port[0],
                    'country' => $port[1],
                    'city' => $port[2],
                    'latitude' => $port[3],
                    'longitude' => $port[4],
                    'terminal_address' => $port[5],
                    'timezone' => $port[6],
                    'currency' => 'Lokal',
                    'language' => 'Lokalt/Engelsk',
                    'emergency_number' => '112',
                    'notes' => 'Gennemgaa transporttid og moedested foer ankomst.',
                    'created_at' => current_time('mysql'),
                ),
                array('%s', '%s', '%s', '%f', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
            );
        }
    }

    $existing_days = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $days_table WHERE group_id = %d",
        $group_id
    ));

    if ($existing_days === 0 || $force) {
        $port_map = $wpdb->get_results("SELECT name, id FROM $ports_table", OBJECT_K);
        $days = array(
            array(1, '2026-08-01', 'Kobenhavn', 0, null, '17:00:00', '16:30:00'),
            array(2, '2026-08-02', 'Warnemunde (Berlin)', 0, '07:00:00', '17:00:00', '16:30:00'),
            array(3, '2026-08-03', 'Gdynia (Gdansk-Danzig)', 0, '10:00:00', '19:00:00', '18:30:00'),
            array(4, '2026-08-04', 'Klaipeda', 0, '07:00:00', '15:30:00', '15:00:00'),
            array(5, '2026-08-05', 'Riga', 0, '07:00:00', '14:30:00', '14:00:00'),
            array(6, '2026-08-06', 'Stockholm', 0, '09:00:00', '17:00:00', '16:30:00'),
            array(7, '2026-08-07', null, 1, null, null, null),
            array(8, '2026-08-08', 'Kobenhavn', 0, '09:00:00', null, null),
        );

        foreach ($days as $day) {
            $port_id = null;
            if (!empty($day[2]) && isset($port_map[$day[2]])) {
                $port_id = (int) $port_map[$day[2]]->id;
            }

            $wpdb->insert(
                $days_table,
                array(
                    'group_id' => $group_id,
                    'day_number' => (int) $day[0],
                    'sail_date' => $day[1],
                    'port_id' => $port_id,
                    'is_sea_day' => (int) $day[3],
                    'arrival_time' => $day[4],
                    'departure_time' => $day[5],
                    'all_aboard_time' => $day[6],
                    'meeting_point' => 'Hovedindgang ved terminalen',
                    'created_at' => current_time('mysql'),
                ),
                array('%d', '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s')
            );
        }
    }

    cc_seed_quiz_questions_for_all_days($group_id, $force);
}

function cc_apply_official_itinerary() {
    global $wpdb;

    $group_id = cc_get_primary_group_id();
    if ($group_id <= 0) {
        cc_seed_default_data(false);
        $group_id = cc_get_primary_group_id();
    }

    if ($group_id <= 0) {
        return;
    }

    $ports_table = $wpdb->prefix . 'cc_ports';
    $days_table = $wpdb->prefix . 'cc_itinerary_days';
    $questions_table = $wpdb->prefix . 'cc_quiz_questions';
    $attempts_table = $wpdb->prefix . 'cc_quiz_attempts';

    $wpdb->query("TRUNCATE TABLE $ports_table");
    $wpdb->query($wpdb->prepare("DELETE FROM $days_table WHERE group_id = %d", $group_id));

    $ports = array(
        array('Kobenhavn', 'Danmark', 'Kobenhavn', 55.6761, 12.5683, 'Oceankaj Cruise Terminal, Kobenhavn', 'Europe/Copenhagen'),
        array('Warnemunde (Berlin)', 'Tyskland', 'Warnemunde', 54.1766, 12.0887, 'Warnemunde Cruise Center, Rostock', 'Europe/Berlin'),
        array('Gdynia (Gdansk-Danzig)', 'Polen', 'Gdynia', 54.5189, 18.5305, 'French Quay, Gdynia Port', 'Europe/Warsaw'),
        array('Klaipeda', 'Litauen', 'Klaipeda', 55.7033, 21.1443, 'Cruise Ship Terminal, Klaipeda', 'Europe/Vilnius'),
        array('Riga', 'Letland', 'Riga', 56.9496, 24.1052, 'Riga Passenger Port', 'Europe/Riga'),
        array('Stockholm', 'Sverige', 'Stockholm', 59.3293, 18.0686, 'Stadsgarden / Frihamnen', 'Europe/Stockholm'),
    );

    foreach ($ports as $port) {
        $wpdb->insert(
            $ports_table,
            array(
                'name' => $port[0],
                'country' => $port[1],
                'city' => $port[2],
                'latitude' => $port[3],
                'longitude' => $port[4],
                'terminal_address' => $port[5],
                'timezone' => $port[6],
                'currency' => 'Lokal',
                'language' => 'Lokalt/Engelsk',
                'emergency_number' => '112',
                'notes' => 'Officiel rejseplansbaseline. Bekraeft transportdetaljer foer ankomst.',
                'created_at' => current_time('mysql'),
            ),
            array('%s', '%s', '%s', '%f', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );
    }

    $port_map = $wpdb->get_results("SELECT name, id FROM $ports_table", OBJECT_K);
    $days = array(
        array(1, '2026-08-01', 'Kobenhavn', 0, null, '17:00:00', '16:30:00'),
        array(2, '2026-08-02', 'Warnemunde (Berlin)', 0, '07:00:00', '17:00:00', '16:30:00'),
        array(3, '2026-08-03', 'Gdynia (Gdansk-Danzig)', 0, '10:00:00', '19:00:00', '18:30:00'),
        array(4, '2026-08-04', 'Klaipeda', 0, '07:00:00', '15:30:00', '15:00:00'),
        array(5, '2026-08-05', 'Riga', 0, '07:00:00', '14:30:00', '14:00:00'),
        array(6, '2026-08-06', 'Stockholm', 0, '09:00:00', '17:00:00', '16:30:00'),
        array(7, '2026-08-07', null, 1, null, null, null),
        array(8, '2026-08-08', 'Kobenhavn', 0, '09:00:00', null, null),
    );

    foreach ($days as $day) {
        $port_id = null;
        if (!empty($day[2]) && isset($port_map[$day[2]])) {
            $port_id = (int) $port_map[$day[2]]->id;
        }

        $wpdb->insert(
            $days_table,
            array(
                'group_id' => $group_id,
                'day_number' => (int) $day[0],
                'sail_date' => $day[1],
                'port_id' => $port_id,
                'is_sea_day' => (int) $day[3],
                'arrival_time' => $day[4],
                'departure_time' => $day[5],
                'all_aboard_time' => $day[6],
                'meeting_point' => 'Hovedindgang ved terminalen',
                'created_at' => current_time('mysql'),
            ),
            array('%d', '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s')
        );
    }

    // Re-anchor quiz attempts/questions to the refreshed itinerary IDs.
    $wpdb->query("TRUNCATE TABLE $questions_table");
    $wpdb->query("TRUNCATE TABLE $attempts_table");

    cc_seed_quiz_questions_for_all_days($group_id, true);
}

function cc_apply_test_itinerary() {
    global $wpdb;

    $group_id = cc_get_primary_group_id();
    if ($group_id <= 0) {
        cc_seed_default_data(false);
        $group_id = cc_get_primary_group_id();
    }

    if ($group_id <= 0) {
        return;
    }

    $ports_table = $wpdb->prefix . 'cc_ports';
    $days_table = $wpdb->prefix . 'cc_itinerary_days';
    $questions_table = $wpdb->prefix . 'cc_quiz_questions';
    $attempts_table = $wpdb->prefix . 'cc_quiz_attempts';

    $start_timestamp = (int) current_time('timestamp');
    $day_one = wp_date('Y-m-d', $start_timestamp);
    $day_two = wp_date('Y-m-d', strtotime('+1 day', $start_timestamp));

    $wpdb->query("TRUNCATE TABLE $ports_table");
    $wpdb->query($wpdb->prepare("DELETE FROM $days_table WHERE group_id = %d", $group_id));

    $ports = array(
        array('Oslo', 'Norge', 'Oslo', 59.9139, 10.7522, 'Oslo Havn, Revierkaia', 'Europe/Oslo', 'NOK', 'Norsk/Engelsk', '112', 'Testhavn dag 1 til admin-gennemgang.'),
        array('Helsinki', 'Finland', 'Helsinki', 60.1699, 24.9384, 'West Harbour Terminal 2, Helsinki', 'Europe/Helsinki', 'EUR', 'Finsk/Engelsk', '112', 'Testhavn dag 2 til admin-gennemgang.'),
    );

    foreach ($ports as $port) {
        $wpdb->insert(
            $ports_table,
            array(
                'name' => $port[0],
                'country' => $port[1],
                'city' => $port[2],
                'latitude' => $port[3],
                'longitude' => $port[4],
                'terminal_address' => $port[5],
                'timezone' => $port[6],
                'currency' => $port[7],
                'language' => $port[8],
                'emergency_number' => $port[9],
                'notes' => $port[10],
                'created_at' => current_time('mysql'),
            ),
            array('%s', '%s', '%s', '%f', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );
    }

    $port_map = $wpdb->get_results("SELECT name, id FROM $ports_table", OBJECT_K);
    $days = array(
        array(1, $day_one, 'Oslo', 0, null, '17:00:00', '16:30:00'),
        array(2, $day_two, 'Helsinki', 0, '07:00:00', '17:00:00', '16:30:00'),
    );

    foreach ($days as $day) {
        $port_id = null;
        if (!empty($day[2]) && isset($port_map[$day[2]])) {
            $port_id = (int) $port_map[$day[2]]->id;
        }

        $wpdb->insert(
            $days_table,
            array(
                'group_id' => $group_id,
                'day_number' => (int) $day[0],
                'sail_date' => $day[1],
                'port_id' => $port_id,
                'is_sea_day' => (int) $day[3],
                'arrival_time' => $day[4],
                'departure_time' => $day[5],
                'all_aboard_time' => $day[6],
                'meeting_point' => 'Hovedindgang ved terminalen',
                'created_at' => current_time('mysql'),
            ),
            array('%d', '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s')
        );
    }

    $wpdb->query("TRUNCATE TABLE $questions_table");
    $wpdb->query("TRUNCATE TABLE $attempts_table");

    cc_seed_quiz_questions_for_all_days($group_id, true);
}

function cc_get_default_questions_for_day($is_sea_day, $port_name) {
    if ((int) $is_sea_day === 1) {
        return array(
            array('Hvad er den bedste maade at forebygge soesyge ombord?', 'Spring maaltider helt over', 'Faa frisk luft og drik vand', 'Drik kun kaffe', 'Staa stille i badet', 'B', 'Frisk luft, vaeske og en stabil placering paa skibet kan mindske ubehag.'),
            array('Hvor maerkes bevagelse typisk mindst paa et krydstogtskib?', 'Forrest paa oeverste daek', 'Nederst midt i skibet', 'Kun ved poolen', 'Helt agter', 'B', 'Lavere og mere centrale omraader har ofte mindst bevagelse.'),
            array('Hvad boer I tjekke foerst en morgen til soes?', 'Kun duty free aabningstid', 'Dagens program og aktiviteter', 'Kun sociale medier', 'Naerliggende togafgange', 'B', 'Soedage har ofte mange aktiviteter, saa programmet giver overblik.'),
            array('Hvilken sikkerhedsvane er vigtig selv paa rolige soedage?', 'Lad kahytsdoeren staa aaben', 'Ignorer moenstringsinfo efter dag 1', 'Kend moenstringsstation og ruter', 'Brug elevator i alle noedsituationer', 'C', 'Kendskab til sikkerhedsruter er vigtigt under hele rejsen.'),
            array('Hvad er et godt tip til en travl soedag?', 'Book aktiviteter og spisning i god tid', 'Vent til alt er fuldt', 'Dropp solcreme paa aabent daek', 'Bliv kun indenfor hele dagen', 'A', 'Populaere aktiviteter bliver hurtigt fyldt, saa tidlig planlaegning hjaelper.'),
        );
    }

    $normalized = strtolower(trim((string) $port_name));
    if (strpos($normalized, 'kobenhavn') !== false) {
        return array(
            array('Hvilket havneomraade i Koebenhavn er kendt for farverige facader og gamle traeskibe?', 'Nyhavn', 'Refshaleoen', 'Nordhavn', 'Islands Brygge', 'A', 'Nyhavn er et af byens mest fotograferede havnemiljoeer.'),
            array('Hvad er et kendt kaldenavn for Koebenhavn?', 'Broernes by', 'Spirenes by', 'Den blaesende havn', 'Nordens perle', 'B', 'Koebenhavn kaldes ofte spirenes by paa grund af den historiske skyline.'),
            array('Hvilken forlystelsespark aabnede i 1843 midt i Koebenhavn?', 'Bakken', 'Legoland', 'Tivoli', 'Dyrehavsbakken Havn', 'C', 'Tivoli er en af verdens aeldste stadig aktive forlystelsesparker.'),
            array('Hvilken statue hilser besoegende velkommen ved vandet i Koebenhavn?', 'Den Lille Havfrue', 'Den Gyldne Havn', 'Sirenen fra Oeresund', 'Havfruen paa Raadhuset', 'A', 'Den Lille Havfrue har siddet ved havnen siden 1913.'),
            array('Hvilken transportform er Koebenhavn saerligt kendt for, og som ofte ses overalt fra havnefronten?', 'Kun metro', 'Kun vandtaxa', 'Byens cykelstier', 'Hestesporvogne', 'C', 'Koebenhavn har en af verdens mest cykelvenlige infrastrukturer.'),
        );
    }

    if (strpos($normalized, 'warnemunde') !== false) {
        return array(
            array('Warnemuende hoerer til hvilken stor tysk by?', 'Hamborg', 'Rostock', 'Luebeck', 'Kiel', 'B', 'Warnemuende er en kystbydel i Rostock.'),
            array('Ved hvilket hav ligger Warnemuende?', 'Nordssoeen', 'Oestersoeen', 'Sortehavet', 'Adriaterhavet', 'B', 'Warnemuende ligger ved Tysklands oestersoekyst.'),
            array('Hvad hedder den livlige kanalpromenade med cafeer, butikker og fiskesandwich i Warnemuende?', 'Alter Strom', 'Alte Bruecke', 'Hafenring', 'Kaiserkai', 'A', 'Alter Strom er et klassisk stop for krydstogtgaester lige ved havnen.'),
            array('Hvilken dagsudflugt er almindelig fra Warnemuende?', 'Berlin', 'Muenchen', 'Prag', 'Wien', 'A', 'Mange krydstogtgaester tager paa lang udflugt til Berlin.'),
            array('Hvad er Warnemuende saerligt kendt for udover krydstogthavnen?', 'Alper og skisport', 'Bred sandstrand og fyrtaarn', 'Vulkaner', 'Underjordiske kanaler', 'B', 'Stranden og fyrtaarnet er to af byens mest kendte vartegn.'),
        );
    }

    if (strpos($normalized, 'gdynia') !== false) {
        return array(
            array('Gdynia ligger i hvilket land?', 'Litauen', 'Polen', 'Letland', 'Sverige', 'B', 'Gdynia er en stor polsk havneby ved Oestersoeen.'),
            array('Hvilken naerliggende historisk by kombineres ofte med Gdynia paa udflugter?', 'Gdansk', 'Poznan', 'Wroclaw', 'Katowice', 'A', 'Gdansk er et populaert udflugtsmaal fra Gdynia.'),
            array('Hvilken type by blev Gdynia iser kendt som i 1900-tallet?', 'Bjerglandsby', 'Moderne havneby', 'Kurby uden havn', 'Middelalderborg', 'B', 'Gdynia voksede hurtigt frem som Polens moderne havneby.'),
            array('Hvilket hav graenser op til Gdynia?', 'Oestersoeen', 'Middelhavet', 'Nordssoeen', 'Det aegaeiske hav', 'A', 'Gdynia ligger ved Oestersoekysten.'),
            array('Hvad er et klassisk maritimt symbol i Gdynia?', 'Historiske museums- og skoleskibe ved havnefronten', 'En oerkensafari', 'Romerske akvaedukter', 'Svaevebaner over havnen', 'A', 'Havnefronten er kendt for maritime museumsskibe og havnestemning.'),
        );
    }

    if (strpos($normalized, 'klaipeda') !== false) {
        return array(
            array('Klaipeda ligger i hvilket land?', 'Letland', 'Litauen', 'Estland', 'Polen', 'B', 'Klaipeda er Litauens vigtigste havn.'),
            array('Hvilket UNESCO-sted er en kendt udflugt fra Klaipeda?', 'Kuriske naes', 'Rigas gamle by', 'Skansen', 'Nyhavn', 'A', 'Kuriske naes er et af regionens mest kendte naturomraader.'),
            array('Hvad er Klaipeda saerligt kendt for ved havnefronten?', 'Store sandklitter og faerger mod Kuriske naes', 'Alpine gondoler', 'Underjordiske saltminer', 'Vulkanstrande', 'A', 'Mange besoeg kombinerer byen med faergen til Kuriske naes.'),
            array('Ved hvilket hav ligger Klaipeda?', 'Oestersoeen', 'Sortehavet', 'Nordssoeen', 'Det kaspiske hav', 'A', 'Klaipeda ligger ved Oestersoekysten.'),
            array('Hvilken lille havnefigur leder mange turister efter i Klaipeda?', 'Den sorte spogelsesfigur ved kajen', 'Den gyldne elefant', 'Havnekongen med krone', 'Den syngende loeve', 'A', 'Den sorte spogelsesskulptur ved vandet er en lokal kuriositet.'),
        );
    }

    if (strpos($normalized, 'riga') !== false) {
        return array(
            array('Riga er hovedstad i hvilket land?', 'Litauen', 'Estland', 'Letland', 'Polen', 'C', 'Riga er Letlands hovedstad og stoerste by.'),
            array('Rigas gamle by er kendt for:', 'Jugendstil og middelalderarv', 'Kun romerske ruiner', 'Alpelandsbyer', 'Vulkanske landskaber', 'A', 'Riga er kendt for arkitektur, herunder mange jugendbygninger.'),
            array('Hvilken flod loeber gennem Riga og ud mod havnen?', 'Wisla', 'Daugava', 'Oder', 'Elben', 'B', 'Daugava loeber gennem Riga ud i Oestersoeen.'),
            array('Hvilken bygning i Riga gamle by blev genopfort som et af byens store vartegn?', 'Sorthovedernes Hus', 'Rigas Operahus', 'Centralstationen', 'TV-taarnet', 'A', 'Sorthovedernes Hus er et af de mest kendte fotostop i Riga.'),
            array('Hvad er Riga saerligt beromt for i bymidten?', 'Et af Europas stoerste samlinger af jugendstilbygninger', 'Kun glas-skyskrabere', 'Et havneloest centrum', 'Romerske akvaedukter', 'A', 'Riga er internationalt kendt for sin jugendstilarkitektur.'),
        );
    }

    if (strpos($normalized, 'stockholm') !== false) {
        return array(
            array('Stockholm straekker sig over cirka hvor mange oer?', '4', '14', '40', '140', 'B', 'Stockholm forbindes klassisk med sine 14 centrale oer og mange broer.'),
            array('Gamla Stan er Stockholms:', 'Lufthavnsomraade', 'Gamle bydel', 'Hovedstrand', 'Navn paa krydstogtterminal', 'B', 'Gamla Stan er Stockholms historiske gamle bydel.'),
            array('Hvilket kendt krigsskib kan man se paa et af Stockholms mest beromte museer?', 'Vasa', 'Victory', 'Bismarck', 'Aurora', 'A', 'Vasa sank paa sin jomfrurejse i 1628 og er i dag et ikonisk museumsskib.'),
            array('Hvilken havregion er Stockholm forbundet til?', 'Oestersoeen', 'Sortehavet', 'Roedehavet', 'Aralsoeen', 'A', 'Stockholm ligger ved Sveriges oestersoekyst.'),
            array('Hvad er Stockholms raadhus saerligt kendt for hvert aar?', 'Nobelbanketten', 'Vikingemarkedet', 'Midnatssol-lobet', 'Nordisk sejlfestival', 'A', 'Den beromte Nobelbanket afholdes i Stockholms Stadshus.'),
        );
    }

    if (strpos($normalized, 'oslo') !== false) {
        return array(
            array('Oslo er hovedstad i hvilket land?', 'Sverige', 'Danmark', 'Norge', 'Finland', 'C', 'Oslo er Norges hovedstad og stoerste by.'),
            array('Oslo ligger ved hvilken fjord?', 'Oslofjorden', 'Hardangerfjorden', 'Geirangerfjorden', 'Sognefjorden', 'A', 'Byen ligger ved inderste del af Oslofjorden.'),
            array('Hvad hedder den kendte skulpturpark i Oslo med over 200 skulpturer?', 'Tivoli', 'Vigelandsparken', 'Skansen', 'Esplanadi', 'B', 'Vigelandsparken er et af Oslos mest kendte besoegsmaal og rummer over 200 skulpturer.'),
            array('Hvilket moderne havneomraade i Oslo er kendt for Operaen og ny byudvikling?', 'Aker Brygge/Bjoervika', 'Gamla Stan', 'Nyhavn', 'Frihamnen', 'A', 'Omraadet omkring Bjoervika og Aker Brygge er blevet et markant nyt havnefrontsmiljoe.'),
            array('Hvilken bygning kan man gaa op paa taget af ved Oslos havnefront?', 'Nationalmuseet', 'Oslo Operahus', 'Kongeslottet', 'Nobelinstituttet', 'B', 'Oslo Operahus er beromt for sit skrånende tag, som er aabent for publikum.'),
        );
    }

    if (strpos($normalized, 'helsinki') !== false) {
        return array(
            array('Helsinki er hovedstad i hvilket land?', 'Norge', 'Finland', 'Estland', 'Letland', 'B', 'Helsinki er Finlands hovedstad og stoerste by.'),
            array('Hvad hedder den kendte markedsplads ved havnen i Helsinki?', 'Nyhavn', 'Kauppatori', 'Gamla Stan', 'Aker Brygge', 'B', 'Kauppatori er Helsinkis centrale markedsplads ved vandet.'),
            array('Hvilken kirke kaldes ofte Helsinkis hvide visitkort over havnefronten?', 'Tempelpladsens kirke', 'Helsinki Domkirke', 'Uspenski-katedralen', 'Mikael Agricola-kirken', 'B', 'Den hvide domkirke er et af byens mest genkendelige vartegn fra centrum og havnen.'),
            array('Hvilket transportmiddel er Helsinki saerligt kendt for i centrum?', 'Kamelkaravaner', 'Svaevebaner', 'Sporvogne', 'Kun taxaer', 'C', 'Sporvogne er en klassisk og praktisk del af byens transportnet.'),
            array('Hvilken gamle soefaestning paa en oe uden for Helsinki er paa UNESCO-listen?', 'Suomenlinna', 'Akershus', 'Skansen', 'Kronborg', 'A', 'Suomenlinna er en af Finlands mest kendte sevardigheder og en klassisk udflugt fra havnen.'),
        );
    }

    return array(
        array('Hvad er det bedste foerste skridt ved ankomst til en havn?', 'Tjek lokal transport og returtid', 'Ignorer skibets tid', 'Spring ruteplanlaegning over', 'Undgaa kort', 'A', 'Et hurtigt tidstjek hjaelper med at undgaa sen retur til skibet.'),
        array('Hvilken rejsevane forbedrer sikkerheden paa havnedage?', 'Hav noedkontakter lige ved haanden', 'Lad telefonen blive i kahytten', 'Drop vaeskeindtag', 'Undgaa altid solcreme', 'A', 'Noednumre og opladet telefon er altid nyttigt.'),
        array('Hvad boer gruppen bekraefte foer I forlader havneomraadet?', 'Moedested og returbuffer', 'Kun indkoebsliste', 'Kun planer paa sociale medier', 'Ingenting', 'A', 'Faelles moedeplaner mindsker stress og missede forbindelser.'),
        array('Hvad er en smart maade at undgaa at fare vild?', 'Brug offline-kort og gem skibets placering', 'Stol kun paa hukommelsen', 'Foelg tilfaeldige menneskemaengder', 'Ignorer gadenavne', 'A', 'Gemte returpunkter goer det lettere at navigere i ukendte omraader.'),
        array('Hvilken adfaerd giver den bedste dag i land?', 'Balancer sightseeing med tjek af returtid', 'Ignorer tiden helt', 'Drop al planlaegning', 'Efterlad dokumenter ombord', 'A', 'God tidsstyring giver mere frihed og mindre stress paa udflugter.'),
    );
}

function cc_seed_quiz_questions_for_all_days($group_id, $force = false) {
    global $wpdb;

    $days_table = $wpdb->prefix . 'cc_itinerary_days';
    $ports_table = $wpdb->prefix . 'cc_ports';
    $questions_table = $wpdb->prefix . 'cc_quiz_questions';

    $days = $wpdb->get_results($wpdb->prepare(
        "SELECT d.id, d.is_sea_day, p.name AS port_name
         FROM $days_table d
         LEFT JOIN $ports_table p ON p.id = d.port_id
         WHERE d.group_id = %d
         ORDER BY d.sail_date ASC",
        (int) $group_id
    ));

    foreach ($days as $day) {
        $day_id = (int) $day->id;
        $existing = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $questions_table WHERE itinerary_day_id = %d",
            $day_id
        ));

        if ($force) {
            $wpdb->delete($questions_table, array('itinerary_day_id' => $day_id), array('%d'));
            $existing = 0;
        }

        if ($existing >= 5) {
            continue;
        }

        $template = cc_get_default_questions_for_day((int) $day->is_sea_day, (string) $day->port_name);
        $sort_order = 1;
        foreach ($template as $q) {
            $wpdb->insert(
                $questions_table,
                array(
                    'itinerary_day_id' => $day_id,
                    'question_text' => $q[0],
                    'option_a' => $q[1],
                    'option_b' => $q[2],
                    'option_c' => $q[3],
                    'option_d' => $q[4],
                    'correct_option' => $q[5],
                    'fun_fact' => $q[6],
                    'sort_order' => $sort_order,
                    'created_at' => current_time('mysql'),
                ),
                array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s')
            );
            $sort_order++;
            if ($sort_order > 5) {
                break;
            }
        }
    }
}

function cc_current_user_group_id($user_id) {
    global $wpdb;

    $members_table = $wpdb->prefix . 'cc_group_members';
    $group_id = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT group_id FROM $members_table WHERE user_id = %d ORDER BY id ASC LIMIT 1",
        (int) $user_id
    ));

    return $group_id;
}

function cc_handle_frontend_post() {
    if (!isset($_POST['cc_action']) || !isset($_POST['cc_nonce'])) {
        return;
    }

    $action = sanitize_text_field(wp_unslash($_POST['cc_action']));

    if (!in_array($action, array('add_feed_post', 'submit_quiz', 'create_poll', 'vote_poll', 'delete_poll', 'local_login', 'local_logout'), true)) {
        return;
    }

    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cc_nonce'])), 'cc_frontend_action')) {
        return;
    }

    if ($action === 'local_login') {
        cc_process_local_login();
    }

    if ($action === 'local_logout') {
        cc_process_local_logout();
    }

    $user_id = cc_get_effective_user_id();
    if ($user_id <= 0) {
        return;
    }

    $group_id = cc_current_user_group_id($user_id);
    if (!$group_id) {
        return;
    }

    if ($action === 'add_feed_post') {
        cc_process_feed_post($group_id, $user_id);
    }

    if ($action === 'submit_quiz') {
        cc_process_quiz_submission($group_id, $user_id);
    }

    if ($action === 'create_poll') {
        cc_process_create_poll($group_id, $user_id);
    }

    if ($action === 'vote_poll') {
        cc_process_vote_poll($group_id, $user_id);
    }

    if ($action === 'delete_poll') {
        cc_process_delete_poll($group_id, $user_id);
    }
}

function cc_process_local_login() {
    $user_id = isset($_POST['cc_local_user_id']) ? (int) $_POST['cc_local_user_id'] : 0;
    $passcode = isset($_POST['cc_local_passcode']) ? (string) wp_unslash($_POST['cc_local_passcode']) : '';
    $passcode = trim($passcode);

    $entry = cc_get_local_login_entry_for_user($user_id);
    if (!$entry || $passcode === '') {
        cc_redirect_back_with_notice('Ugyldigt login. Tjek bruger og adgangskode.', 'error');
    }

    if (!wp_check_password($passcode, (string) $entry['passcode_hash'])) {
        cc_redirect_back_with_notice('Ugyldigt login. Tjek bruger og adgangskode.', 'error');
    }

    cc_set_local_login_cookie($user_id);
    cc_redirect_back_with_notice('Du er logget ind i Cruise Companion.', 'success');
}

function cc_process_local_logout() {
    cc_clear_local_login_cookie();
    cc_redirect_back_with_notice('Du er logget ud af Cruise Companion.', 'success');
}

function cc_redirect_back_with_notice($message, $type = 'success') {
    $redirect_url = '';

    // Prioritize explicit return URL from form posts (avoids hosting auth interception).
    if (!empty($_POST['cc_return_url'])) {
        $redirect_url = esc_url_raw(wp_unslash($_POST['cc_return_url']));
    }

    // Second: try referer, but only if it's within the same WordPress domain.
    if (!$redirect_url) {
        $referer = wp_get_referer();
        if ($referer && strpos($referer, home_url('')) === 0) {
            $redirect_url = $referer;
        }
    }

    // Third: reconstruct from REQUEST_URI (keeps cc_view query when posting from shortcode views).
    if (!$redirect_url && !empty($_SERVER['REQUEST_URI'])) {
        $request_uri = wp_unslash($_SERVER['REQUEST_URI']);
        $redirect_url = home_url($request_uri);
    }

    // Last fallback: home URL.
    if (!$redirect_url) {
        $redirect_url = home_url('/');
    }

    // Validate redirect stays within WordPress domain.
    $redirect_url = wp_validate_redirect($redirect_url, home_url('/'));

    $redirect_url = add_query_arg(
        array(
            'cc_notice' => rawurlencode($message),
            'cc_notice_type' => rawurlencode($type),
        ),
        $redirect_url
    );

    wp_safe_redirect($redirect_url);
    exit;
}

function cc_process_feed_post($group_id, $user_id) {
    global $wpdb;

    $content = isset($_POST['cc_feed_content']) ? sanitize_textarea_field(wp_unslash($_POST['cc_feed_content'])) : '';
    $day_id = isset($_POST['cc_day_id']) ? (int) $_POST['cc_day_id'] : 0;

    if (trim($content) === '') {
        cc_redirect_back_with_notice('Besked maa ikke vaere tom.', 'error');
    }

    $feed_table = $wpdb->prefix . 'cc_feed_posts';
    $wpdb->insert(
        $feed_table,
        array(
            'group_id' => (int) $group_id,
            'user_id' => (int) $user_id,
            'itinerary_day_id' => $day_id > 0 ? $day_id : null,
            'post_type' => 'status',
            'content' => $content,
            'created_at' => current_time('mysql'),
        ),
        array('%d', '%d', '%d', '%s', '%s', '%s')
    );

    cc_redirect_back_with_notice('Delt opdatering sendt til alle rejsende.', 'success');
}

function cc_process_quiz_submission($group_id, $user_id) {
    global $wpdb;

    $day_id = isset($_POST['cc_quiz_day_id']) ? (int) $_POST['cc_quiz_day_id'] : 0;
    if ($day_id <= 0) {
        cc_redirect_back_with_notice('Quiz-dag mangler.', 'error');
    }

    $attempts_table = $wpdb->prefix . 'cc_quiz_attempts';
    $existing_attempt = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $attempts_table WHERE itinerary_day_id = %d AND user_id = %d AND mode = %s",
        $day_id,
        $user_id,
        'ranked'
    ));

    if ($existing_attempt > 0) {
        cc_redirect_back_with_notice('Du har allerede brugt dit ene rangerede forsoeg for denne dag.', 'error');
    }

    $questions = cc_get_quiz_questions_for_day_with_answers($day_id);
    if (count($questions) !== 5) {
        cc_redirect_back_with_notice('Quiz er endnu ikke tilgaengelig for denne dag.', 'error');
    }

    $valid_options = array('A', 'B', 'C', 'D');
    $score = 0;
    $submitted_answers = array();

    foreach ($questions as $question) {
        $key = 'cc_answer_' . (int) $question->id;
        $answer = isset($_POST[$key]) ? strtoupper(sanitize_text_field(wp_unslash($_POST[$key]))) : '';

        if (!in_array($answer, $valid_options, true)) {
            cc_redirect_back_with_notice('Besvar venligst alle 5 spoergsmaal foer indsendelse.', 'error');
        }

        $submitted_answers[$question->id] = $answer;
        if ($answer === $question->correct_option) {
            $score++;
        }
    }

    $wpdb->insert(
        $attempts_table,
        array(
            'itinerary_day_id' => $day_id,
            'group_id' => $group_id,
            'user_id' => $user_id,
            'score' => $score,
            'total_questions' => 5,
            'answers_json' => wp_json_encode($submitted_answers),
            'created_at' => current_time('mysql'),
            'mode' => 'ranked',
        ),
        array('%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s')
    );

    cc_redirect_back_with_notice('Quiz indsendt. Du fik ' . $score . ' ud af 5.', 'success');
}

function cc_process_create_poll($group_id, $user_id) {
    global $wpdb;

    $question = isset($_POST['cc_poll_question']) ? sanitize_text_field(wp_unslash($_POST['cc_poll_question'])) : '';
    if (trim($question) === '') {
        cc_redirect_back_with_notice('Spoergsmaal maa ikke vaere tomt.', 'error');
    }

    $raw_options = isset($_POST['cc_poll_options']) && is_array($_POST['cc_poll_options'])
        ? $_POST['cc_poll_options']
        : array();

    $options = array();
    foreach ($raw_options as $opt) {
        $clean = sanitize_text_field(wp_unslash((string) $opt));
        if (trim($clean) !== '') {
            $options[] = $clean;
        }
    }

    if (count($options) < 2) {
        cc_redirect_back_with_notice('En afstemning skal have mindst 2 svarmuligheder.', 'error');
    }

    if (count($options) > 6) {
        cc_redirect_back_with_notice('En afstemning maa have hoejst 6 svarmuligheder.', 'error');
    }

    $polls_table = $wpdb->prefix . 'cc_polls';
    $poll_options_table = $wpdb->prefix . 'cc_poll_options';

    $wpdb->insert(
        $polls_table,
        array(
            'group_id' => (int) $group_id,
            'user_id' => (int) $user_id,
            'question' => $question,
            'created_at' => current_time('mysql'),
        ),
        array('%d', '%d', '%s', '%s')
    );

    $poll_id = (int) $wpdb->insert_id;
    if ($poll_id <= 0) {
        cc_redirect_back_with_notice('Afstemning kunne ikke oprettes. Proev igen.', 'error');
    }

    $sort_order = 1;
    foreach ($options as $option_text) {
        $wpdb->insert(
            $poll_options_table,
            array(
                'poll_id' => $poll_id,
                'option_text' => $option_text,
                'sort_order' => $sort_order,
            ),
            array('%d', '%s', '%d')
        );
        $sort_order++;
    }

    cc_send_poll_push_notifications((int) $group_id, (int) $user_id);
    cc_redirect_back_with_notice('Afstemning oprettet og delt med gruppen.', 'success');
}

function cc_process_vote_poll($group_id, $user_id) {
    global $wpdb;

    $poll_id = isset($_POST['cc_poll_id']) ? (int) $_POST['cc_poll_id'] : 0;
    $option_id = isset($_POST['cc_poll_option_id']) ? (int) $_POST['cc_poll_option_id'] : 0;

    if ($poll_id <= 0 || $option_id <= 0) {
        cc_redirect_back_with_notice('Ugyldigt valg. Proev igen.', 'error');
    }

    // Verify poll belongs to this group.
    $polls_table = $wpdb->prefix . 'cc_polls';
    $poll_group = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT group_id FROM $polls_table WHERE id = %d LIMIT 1",
        $poll_id
    ));

    if ($poll_group !== (int) $group_id) {
        cc_redirect_back_with_notice('Afstemningen tilhoerer ikke din gruppe.', 'error');
    }

    // Verify option belongs to this poll.
    $poll_options_table = $wpdb->prefix . 'cc_poll_options';
    $option_poll = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT poll_id FROM $poll_options_table WHERE id = %d LIMIT 1",
        $option_id
    ));

    if ($option_poll !== $poll_id) {
        cc_redirect_back_with_notice('Ugyldig svarmulighed.', 'error');
    }

    $poll_votes_table = $wpdb->prefix . 'cc_poll_votes';

    $existing = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $poll_votes_table WHERE poll_id = %d AND user_id = %d",
        $poll_id,
        $user_id
    ));

    if ($existing > 0) {
        cc_redirect_back_with_notice('Du har allerede stemt i denne afstemning.', 'error');
    }

    $wpdb->insert(
        $poll_votes_table,
        array(
            'poll_id' => $poll_id,
            'option_id' => $option_id,
            'user_id' => $user_id,
            'voted_at' => current_time('mysql'),
        ),
        array('%d', '%d', '%d', '%s')
    );

    cc_redirect_back_with_notice('Din stemme er registreret.', 'success');
}

function cc_process_delete_poll($group_id, $user_id) {
    global $wpdb;

    $poll_id = isset($_POST['cc_poll_id']) ? (int) $_POST['cc_poll_id'] : 0;
    if ($poll_id <= 0) {
        cc_redirect_back_with_notice('Ugyldig afstemning.', 'error');
    }

    $polls_table       = $wpdb->prefix . 'cc_polls';
    $poll_options_table = $wpdb->prefix . 'cc_poll_options';
    $poll_votes_table  = $wpdb->prefix . 'cc_poll_votes';

    $poll = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $polls_table WHERE id = %d LIMIT 1",
        $poll_id
    ));

    if (!$poll || (int) $poll->group_id !== (int) $group_id) {
        cc_redirect_back_with_notice('Afstemningen tilhoerer ikke din gruppe.', 'error');
    }

    if ((int) $poll->user_id !== (int) $user_id) {
        cc_redirect_back_with_notice('Kun den der oprettede afstemningen kan slette den.', 'error');
    }

    $option_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT id FROM $poll_options_table WHERE poll_id = %d",
        $poll_id
    ));

    if (!empty($option_ids)) {
        $placeholders = implode(',', array_fill(0, count($option_ids), '%d'));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $poll_votes_table WHERE option_id IN ($placeholders)",
            ...$option_ids
        ));
    }

    $wpdb->delete($poll_options_table, array('poll_id' => $poll_id), array('%d'));
    $wpdb->delete($polls_table, array('id' => $poll_id), array('%d'));

    cc_redirect_back_with_notice('Afstemning slettet.', 'success');
}

function cc_get_itinerary_days($group_id) {
    global $wpdb;
    $days_table = $wpdb->prefix . 'cc_itinerary_days';
    $ports_table = $wpdb->prefix . 'cc_ports';

    return $wpdb->get_results($wpdb->prepare(
        "SELECT d.*, p.name AS port_name, p.country, p.city, p.terminal_address, p.timezone, p.currency, p.language,
                p.emergency_number, p.notes, p.latitude, p.longitude
         FROM $days_table d
         LEFT JOIN $ports_table p ON p.id = d.port_id
         WHERE d.group_id = %d
         ORDER BY d.sail_date ASC",
        $group_id
    ));
}

function cc_sanitize_decimal_or_null($value) {
    $value = trim((string) wp_unslash($value));
    if ($value === '' || !is_numeric($value)) {
        return null;
    }

    return (float) $value;
}

function cc_get_port_weather_coordinates($port_day) {
    $latitude = isset($port_day->latitude) ? (float) $port_day->latitude : 0.0;
    $longitude = isset($port_day->longitude) ? (float) $port_day->longitude : 0.0;
    if ($latitude !== 0.0 && $longitude !== 0.0) {
        return array($latitude, $longitude);
    }

    $fallback_coordinates = array(
        'Kobenhavn' => array(55.6761, 12.5683),
        'Warnemunde (Berlin)' => array(54.1766, 12.0887),
        'Gdynia (Gdansk-Danzig)' => array(54.5189, 18.5305),
        'Klaipeda' => array(55.7033, 21.1443),
        'Riga' => array(56.9496, 24.1052),
        'Stockholm' => array(59.3293, 18.0686),
    );

    $port_name = isset($port_day->port_name) ? (string) $port_day->port_name : '';
    if ($port_name !== '' && isset($fallback_coordinates[$port_name])) {
        return $fallback_coordinates[$port_name];
    }

    return null;
}

function cc_weather_code_label($code) {
    $map = array(
        0 => 'klar himmel',
        1 => 'overvejende klart',
        2 => 'delvist skyet',
        3 => 'overskyet',
        45 => 'taage',
        48 => 'rimtaage',
        51 => 'let stoedregn',
        53 => 'moderat stoedregn',
        55 => 'kraftig stoedregn',
        61 => 'let regn',
        63 => 'moderat regn',
        65 => 'kraftig regn',
        71 => 'let sne',
        73 => 'moderat sne',
        75 => 'kraftig sne',
        80 => 'lette byger',
        81 => 'moderate byger',
        82 => 'kraftige byger',
        95 => 'tordenvejr',
        96 => 'tordenvejr med hagl',
        99 => 'kraftigt tordenvejr med hagl',
    );

    $code = (int) $code;
    return isset($map[$code]) ? $map[$code] : 'opdateret vejrudsigt';
}

function cc_format_weather_temp($value) {
    return $value === null ? '-' : round((float) $value, 1) . ' °C';
}

function cc_format_weather_speed($value) {
    return $value === null ? '-' : round((float) $value, 1) . ' m/s';
}

function cc_get_open_meteo_weather_for_port($port_day) {
    $coordinates = cc_get_port_weather_coordinates($port_day);
    if (!$coordinates) {
        return null;
    }

    $weather_date = !empty($port_day->sail_date) ? (string) $port_day->sail_date : wp_date('Y-m-d');
    $today = wp_date('Y-m-d');
    $today_ts = strtotime($today . ' 00:00:00');
    $weather_ts = strtotime($weather_date . ' 00:00:00');
    $forecast_horizon_days = 16;
    $days_ahead = ($today_ts !== false && $weather_ts !== false) ? (int) floor(($weather_ts - $today_ts) / DAY_IN_SECONDS) : 0;
    $used_today_fallback = false;
    if ($days_ahead > $forecast_horizon_days || $days_ahead < 0) {
        $weather_date = $today;
        $used_today_fallback = true;
    }

    $query_args = array(
        'latitude' => $coordinates[0],
        'longitude' => $coordinates[1],
        'current' => 'temperature_2m,apparent_temperature,weather_code,wind_speed_10m,wind_gusts_10m,precipitation,precipitation_probability',
        'daily' => 'temperature_2m_max,temperature_2m_min,precipitation_sum,precipitation_probability_max,weather_code,wind_speed_10m_max,wind_gusts_10m_max',
        'timezone' => 'auto',
        'temperature_unit' => 'celsius',
        'wind_speed_unit' => 'ms',
        'precipitation_unit' => 'mm',
        'start_date' => $weather_date,
        'end_date' => $weather_date,
        'format' => 'json',
    );

    $cache_key = 'cc_om_' . md5(wp_json_encode($query_args));
    $cached = get_transient($cache_key);
    if ($cached !== false) {
        return $cached;
    }

    $request_url = add_query_arg($query_args, 'https://api.open-meteo.com/v1/forecast');
    $response = wp_remote_get($request_url, array(
        'timeout' => 8,
        'headers' => array(
            'Accept' => 'application/json',
            'User-Agent' => 'Cruise Companion WP Plugin/' . CC_PLUGIN_VERSION,
        ),
    ));

    if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
        return null;
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($data) || empty($data['daily']['time'][0])) {
        return null;
    }

    $weather = array(
        'date' => (string) $data['daily']['time'][0],
        'requested_date' => $weather_date,
        'used_today_fallback' => $used_today_fallback,
        'is_today' => $weather_date === wp_date('Y-m-d'),
        'current_temperature' => isset($data['current']['temperature_2m']) ? (float) $data['current']['temperature_2m'] : null,
        'current_feels_like' => isset($data['current']['apparent_temperature']) ? (float) $data['current']['apparent_temperature'] : null,
        'current_wind_speed' => isset($data['current']['wind_speed_10m']) ? (float) $data['current']['wind_speed_10m'] : null,
        'current_wind_gusts' => isset($data['current']['wind_gusts_10m']) ? (float) $data['current']['wind_gusts_10m'] : null,
        'current_precipitation' => isset($data['current']['precipitation']) ? (float) $data['current']['precipitation'] : null,
        'current_precipitation_probability' => isset($data['current']['precipitation_probability']) ? (int) $data['current']['precipitation_probability'] : null,
        'current_weather_code' => isset($data['current']['weather_code']) ? (int) $data['current']['weather_code'] : null,
        'daily_temperature_max' => isset($data['daily']['temperature_2m_max'][0]) ? (float) $data['daily']['temperature_2m_max'][0] : null,
        'daily_temperature_min' => isset($data['daily']['temperature_2m_min'][0]) ? (float) $data['daily']['temperature_2m_min'][0] : null,
        'daily_precipitation_sum' => isset($data['daily']['precipitation_sum'][0]) ? (float) $data['daily']['precipitation_sum'][0] : null,
        'daily_precipitation_probability_max' => isset($data['daily']['precipitation_probability_max'][0]) ? (int) $data['daily']['precipitation_probability_max'][0] : null,
        'daily_weather_code' => isset($data['daily']['weather_code'][0]) ? (int) $data['daily']['weather_code'][0] : null,
        'daily_wind_speed_max' => isset($data['daily']['wind_speed_10m_max'][0]) ? (float) $data['daily']['wind_speed_10m_max'][0] : null,
        'daily_wind_gusts_max' => isset($data['daily']['wind_gusts_10m_max'][0]) ? (float) $data['daily']['wind_gusts_10m_max'][0] : null,
        'source_url' => esc_url_raw($request_url),
        'provider_name' => 'Open-Meteo',
    );

    set_transient($cache_key, $weather, HOUR_IN_SECONDS);
    return $weather;
}

function cc_get_quiz_day($group_id) {
    $days = cc_get_itinerary_days($group_id);
    if (!$days) {
        return null;
    }

    $today = wp_date('Y-m-d');
    foreach ($days as $day) {
        if ($day->sail_date === $today) {
            return $day;
        }
    }

    foreach ($days as $day) {
        if ($day->sail_date > $today) {
            return $day;
        }
    }

    return $days[0];
}

function cc_get_quiz_questions_for_day($day_id) {
    global $wpdb;

    $questions_table = $wpdb->prefix . 'cc_quiz_questions';
    return $wpdb->get_results($wpdb->prepare(
        "SELECT id, itinerary_day_id, question_text, option_a, option_b, option_c, option_d, fun_fact, sort_order
         FROM $questions_table
         WHERE itinerary_day_id = %d
         ORDER BY sort_order ASC
         LIMIT 5",
        $day_id
    ));
}

function cc_get_quiz_questions_for_day_with_answers($day_id) {
    global $wpdb;

    $questions_table = $wpdb->prefix . 'cc_quiz_questions';
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $questions_table WHERE itinerary_day_id = %d ORDER BY sort_order ASC LIMIT 5",
        $day_id
    ));
}

function cc_get_attempt_for_day($day_id, $user_id) {
    global $wpdb;

    $attempts_table = $wpdb->prefix . 'cc_quiz_attempts';
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $attempts_table WHERE itinerary_day_id = %d AND user_id = %d AND mode = %s LIMIT 1",
        $day_id,
        $user_id,
        'ranked'
    ));
}

function cc_get_leaderboard_for_day($group_id, $day_id) {
    global $wpdb;

    $attempts_table = $wpdb->prefix . 'cc_quiz_attempts';
    $users_table = $wpdb->users;

    return $wpdb->get_results($wpdb->prepare(
        "SELECT a.score, a.total_questions, a.created_at, u.display_name
         FROM $attempts_table a
         INNER JOIN $users_table u ON u.ID = a.user_id
         WHERE a.group_id = %d AND a.itinerary_day_id = %d AND a.mode = %s
         ORDER BY a.score DESC, a.created_at ASC",
        $group_id,
        $day_id,
        'ranked'
    ));
}

function cc_get_feed_posts($group_id, $limit = 30) {
    global $wpdb;

    $feed_table = $wpdb->prefix . 'cc_feed_posts';
    $users_table = $wpdb->users;
    $days_table = $wpdb->prefix . 'cc_itinerary_days';

    return $wpdb->get_results($wpdb->prepare(
        "SELECT f.*, u.display_name, d.day_number, d.sail_date
         FROM $feed_table f
         INNER JOIN $users_table u ON u.ID = f.user_id
         LEFT JOIN $days_table d ON d.id = f.itinerary_day_id
         WHERE f.group_id = %d
         ORDER BY f.created_at DESC
         LIMIT %d",
        $group_id,
        (int) $limit
    ));
}

function cc_get_polls_for_group($group_id, $limit = 20) {
    global $wpdb;

    $polls_table = $wpdb->prefix . 'cc_polls';
    $users_table = $wpdb->users;

    return $wpdb->get_results($wpdb->prepare(
        "SELECT p.*, u.display_name
         FROM $polls_table p
         INNER JOIN $users_table u ON u.ID = p.user_id
         WHERE p.group_id = %d
         ORDER BY p.created_at DESC
         LIMIT %d",
        (int) $group_id,
        (int) $limit
    ));
}

function cc_get_latest_poll_for_group($group_id) {
    global $wpdb;

    $polls_table = $wpdb->prefix . 'cc_polls';
    $users_table = $wpdb->users;

    $poll = $wpdb->get_row($wpdb->prepare(
        "SELECT p.id, p.user_id, p.question, p.created_at, u.display_name
         FROM $polls_table p
         INNER JOIN $users_table u ON u.ID = p.user_id
         WHERE p.group_id = %d
         ORDER BY p.created_at DESC, p.id DESC
         LIMIT 1",
        (int) $group_id
    ));

    if (!$poll) {
        return null;
    }

    return array(
        'id' => (int) $poll->id,
        'user_id' => (int) $poll->user_id,
        'display_name' => (string) $poll->display_name,
        'question' => (string) $poll->question,
        'created_at' => (string) $poll->created_at,
    );
}

function cc_get_poll_options($poll_id) {
    global $wpdb;

    $poll_options_table = $wpdb->prefix . 'cc_poll_options';

    return $wpdb->get_results($wpdb->prepare(
        "SELECT po.*, COUNT(pv.id) AS vote_count
         FROM $poll_options_table po
         LEFT JOIN {$wpdb->prefix}cc_poll_votes pv ON pv.option_id = po.id
         WHERE po.poll_id = %d
         GROUP BY po.id
         ORDER BY po.sort_order ASC",
        (int) $poll_id
    ));
}

function cc_get_user_vote_for_poll($poll_id, $user_id) {
    global $wpdb;

    $poll_votes_table = $wpdb->prefix . 'cc_poll_votes';

    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $poll_votes_table WHERE poll_id = %d AND user_id = %d LIMIT 1",
        (int) $poll_id,
        (int) $user_id
    ));
}

function cc_get_must_do_sights_for_port($port_name) {
    $normalized = strtolower(trim((string) $port_name));

    if (strpos($normalized, 'kobenhavn') !== false) {
        return array(
            'Nyhavn og kanalrundfart i de historiske havnekanaler.',
            'Amalienborg + vagtskifte ved middagstid.',
            'Torvehallerne for klassisk smorrebrod og danske specialiteter.',
        );
    }

    if (strpos($normalized, 'warnemunde') !== false) {
        return array(
            'Spadseretur langs Alter Strom med cafeer og fiskesandwich.',
            'Stranden i Warnemunde for en hurtig kystpause.',
            'Rostocks gamle bydel hvis tiden tillader det.',
        );
    }

    if (strpos($normalized, 'gdynia') !== false) {
        return array(
            'Dagsudflugt til Gdansk gamle by (Long Market og Mariakirken).',
            'Sopot mole og strandpromenade.',
            'Emigrationsmuseet i Gdynia for lokal historie.',
        );
    }

    if (strpos($normalized, 'klaipeda') !== false) {
        return array(
            'Faerge til Kuriske naes (UNESCO) og sandklitter.',
            'Klaipedas gamle by med bindingsvaerk og pladser.',
            'Skulpturjagt i centrum, fx den lille mus ved teatret.',
        );
    }

    if (strpos($normalized, 'riga') !== false) {
        return array(
            'Rigas gamle by med Sorthovedernes Hus og Domkirken.',
            'Jugendstil-kvarteret omkring Alberta iela.',
            'Centralmarkedet i de gamle zeppelinhaller.',
        );
    }

    if (strpos($normalized, 'stockholm') !== false) {
        return array(
            'Gamla Stan med Stortorget og smalle middelaldergader.',
            'Vasa-museet (et af Nordens mest populaere museer).',
            'Udsigt fra Monteliusvagen over by og vand.',
        );
    }

    return array(
        'Historisk bykerne eller vigtigste sevardighed i gaafstand.',
        'Lokalt marked/madhal for hurtig introduktion til byen.',
        'Udsigtspunkt naer havnen med god returlogistik.',
    );
}

function cc_render_notice_from_query() {
    if (empty($_GET['cc_notice'])) {
        return;
    }

    $type = isset($_GET['cc_notice_type']) ? sanitize_text_field(wp_unslash($_GET['cc_notice_type'])) : 'success';
    $class = $type === 'error' ? 'cc-notice cc-notice-error' : 'cc-notice cc-notice-success';
    $message = sanitize_text_field(rawurldecode(wp_unslash($_GET['cc_notice'])));

    echo '<div class="' . esc_attr($class) . '">' . esc_html($message) . '</div>';
}

function cc_render_local_login_prompt() {
    $entries = cc_get_local_login_entries();
    // Build the return URL explicitly to stay on the current page with cc_view query preserved.
    $current_url = home_url(wp_unslash($_SERVER['REQUEST_URI'] ?? '/'));
    ob_start();
    ?>
    <div class="cc-wrap">
        <?php cc_render_notice_from_query(); ?>
        <section class="cc-card">
            <h2>Log ind i Cruise Companion</h2>
            <?php if (empty($entries)) : ?>
                <p>Local login er endnu ikke sat op. Bed en administrator om at konfigurere brugere og adgangskoder i plugin-admin.</p>
            <?php else : ?>
                <form method="post" class="cc-form">
                    <?php wp_nonce_field('cc_frontend_action', 'cc_nonce'); ?>
                    <input type="hidden" name="cc_action" value="local_login">
                    <input type="hidden" name="cc_return_url" value="<?php echo esc_url($current_url); ?>"><?php echo "\n"; ?>

                    <label for="cc_local_user_id"><strong>Bruger</strong></label>
                    <select name="cc_local_user_id" id="cc_local_user_id" required>
                        <option value="">Vaelg bruger</option>
                        <?php foreach ($entries as $entry) : ?>
                            <?php $user = get_userdata((int) $entry['user_id']); ?>
                            <?php if (!$user) { continue; } ?>
                            <option value="<?php echo esc_attr((string) $user->ID); ?>"><?php echo esc_html((string) $user->display_name); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label for="cc_local_passcode"><strong>Adgangskode</strong></label>
                    <input type="password" id="cc_local_passcode" name="cc_local_passcode" class="cc-input" required>

                    <button class="cc-button" type="submit">Log ind</button>
                </form>
            <?php endif; ?>
        </section>
    </div>
    <?php

    return ob_get_clean();
}

function cc_render_shortcode() {
    wp_enqueue_style('cc-styles');

    $user_id = cc_get_effective_user_id();
    if ($user_id <= 0) {
        return cc_render_local_login_prompt();
    }

    $is_local_login = !is_user_logged_in();
    $current_user = get_userdata($user_id);
    $group_id = cc_current_user_group_id($user_id);

    if (!$group_id) {
        return '<div class="cc-card"><p>Din konto er endnu ikke tilknyttet 4-personers gruppen. Bed en administrator om at tilfoeje dig.</p></div>';
    }

    $days = cc_get_itinerary_days($group_id);
    $quiz_day = cc_get_quiz_day($group_id);
    $quiz_questions = $quiz_day ? cc_get_quiz_questions_for_day((int) $quiz_day->id) : array();
    $quiz_attempt = $quiz_day ? cc_get_attempt_for_day((int) $quiz_day->id, $user_id) : null;
    $leaderboard = $quiz_day ? cc_get_leaderboard_for_day($group_id, (int) $quiz_day->id) : array();
    $feed_posts = cc_get_feed_posts($group_id);
    $polls = cc_get_polls_for_group($group_id);
    $background_image_url = cc_get_background_image_url();
    if ($background_image_url !== '') {
        $background_image_url = set_url_scheme($background_image_url, is_ssl() ? 'https' : 'http');
    }
    $overlay_alpha = number_format(cc_get_background_overlay_opacity() / 100, 2, '.', '');
    $card_alpha = number_format(cc_get_background_card_opacity() / 100, 2, '.', '');

    $port_lookup = array();
    foreach ($days as $day_item) {
        if ((int) $day_item->is_sea_day === 1 || empty($day_item->port_name)) {
            continue;
        }
        $port_name_key = (string) $day_item->port_name;
        if (!isset($port_lookup[$port_name_key])) {
            $port_lookup[$port_name_key] = $day_item;
        }
    }

    $requested_port_name = isset($_GET['cc_port']) ? sanitize_text_field(wp_unslash($_GET['cc_port'])) : '';
    $is_specific_port_view = $requested_port_name !== '';
    $selected_port_day = null;
    if ($requested_port_name !== '' && isset($port_lookup[$requested_port_name])) {
        $selected_port_day = $port_lookup[$requested_port_name];
    } elseif ($quiz_day && (int) $quiz_day->is_sea_day === 0 && !empty($quiz_day->port_name) && isset($port_lookup[(string) $quiz_day->port_name])) {
        $selected_port_day = $port_lookup[(string) $quiz_day->port_name];
    }

    $selected_port_weather = $selected_port_day ? cc_get_open_meteo_weather_for_port($selected_port_day) : null;

    $allowed_views = array('home', 'itinerary', 'mustdo', 'feed', 'quiz', 'poll', 'shippos');
    $current_view = isset($_GET['cc_view']) ? sanitize_key(wp_unslash($_GET['cc_view'])) : 'home';
    if (!in_array($current_view, $allowed_views, true)) {
        $current_view = 'home';
    }

    $base_url = get_permalink();
    $home_url = add_query_arg('cc_view', 'home', $base_url);
    $itinerary_url = add_query_arg('cc_view', 'itinerary', $base_url);
    $must_do_url = add_query_arg('cc_view', 'mustdo', $base_url);
    $feed_url = add_query_arg('cc_view', 'feed', $base_url);
    $quiz_url = add_query_arg('cc_view', 'quiz', $base_url);
    $poll_url = add_query_arg('cc_view', 'poll', $base_url);
    $ship_position_url = add_query_arg('cc_view', 'shippos', $base_url);

    $wrap_classes = 'cc-wrap';
    $wrap_style = '--cc-card-opacity:' . $card_alpha . ';';
    if ($background_image_url !== '') {
        $wrap_classes .= ' cc-wrap-has-bg';
        $wrap_style .= sprintf(
            ' background-image: linear-gradient(rgba(12, 22, 36, %1$s), rgba(12, 22, 36, %1$s)), url("%2$s"); background-position: center; background-repeat: no-repeat; background-size: cover;',
            $overlay_alpha,
            esc_url($background_image_url)
        );
    }

    ob_start();
    ?>
    <div class="<?php echo esc_attr($wrap_classes); ?>"<?php echo $wrap_style !== '' ? ' style="' . esc_attr($wrap_style) . '"' : ''; ?>>
        <?php cc_render_notice_from_query(); ?>

        <?php if ($is_local_login) : ?>
            <section class="cc-card">
                <p>
                    Logget ind som <strong><?php echo esc_html($current_user ? (string) $current_user->display_name : 'Ukendt bruger'); ?></strong> via lokal plugin-login.
                </p>
                <form method="post" class="cc-form" style="margin:0;">
                    <?php wp_nonce_field('cc_frontend_action', 'cc_nonce'); ?>
                    <input type="hidden" name="cc_action" value="local_logout">
                    <input type="hidden" name="cc_return_url" value="<?php echo esc_url($home_url); ?>">
                    <button class="cc-button" type="submit">Log ud</button>
                </form>
            </section>
        <?php endif; ?>

        <section class="cc-card">
            <h2>Krydstogt 2026 kontrolpanel</h2>
            <p>Privat rum for alle 4 rejsende. Alle opdateringer her deles med hele gruppen.</p>
            <details class="cc-hub-menu">
                <summary class="cc-hub-menu-toggle" role="button" aria-label="Vis eller skjul menu">
                    <span class="cc-hub-menu-icon" aria-hidden="true">&#9776;</span>
                    <span class="cc-hub-menu-label">Menu</span>
                    <span class="cc-hub-menu-state cc-hub-menu-state-open">Skjul</span>
                    <span class="cc-hub-menu-state cc-hub-menu-state-closed">Vis</span>
                </summary>

                <div class="cc-hub-grid">
                    <a class="cc-hub-button <?php echo $current_view === 'home' ? 'cc-hub-button-active' : ''; ?>" href="<?php echo esc_url($home_url); ?>">
                        <span class="cc-hub-title">Hjem</span>
                        <span class="cc-hub-subtitle">Overblik og hurtige handlinger</span>
                    </a>
                    <a class="cc-hub-button <?php echo $current_view === 'itinerary' ? 'cc-hub-button-active' : ''; ?>" href="<?php echo esc_url($itinerary_url); ?>">
                        <span class="cc-hub-title">Rejseplan</span>
                        <span class="cc-hub-subtitle">Datoer, havne, ankomst og afgang</span>
                    </a>
                    <a class="cc-hub-button <?php echo $current_view === 'mustdo' ? 'cc-hub-button-active' : ''; ?>" href="<?php echo esc_url($must_do_url); ?>">
                        <span class="cc-hub-title">Dagens havn</span>
                        <span class="cc-hub-subtitle">Must-do sevardigheder i hver havn</span>
                    </a>
                    <a class="cc-hub-button <?php echo $current_view === 'feed' ? 'cc-hub-button-active' : ''; ?>" href="<?php echo esc_url($feed_url); ?>">
                        <span class="cc-hub-title">Gruppefeed</span>
                        <span class="cc-hub-subtitle">Delte opdateringer til alle 4 rejsende</span>
                    </a>
                    <a class="cc-hub-button <?php echo $current_view === 'quiz' ? 'cc-hub-button-active' : ''; ?>" href="<?php echo esc_url($quiz_url); ?>">
                        <span class="cc-hub-title">Dagens quiz</span>
                        <span class="cc-hub-subtitle">5 multiple-choice spoergsmaal</span>
                    </a>
                    <a class="cc-hub-button <?php echo $current_view === 'poll' ? 'cc-hub-button-active' : ''; ?>" href="<?php echo esc_url($poll_url); ?>">
                        <span class="cc-hub-title">Afstemning</span>
                        <span class="cc-hub-subtitle">Hurtige afstemninger for gruppen</span>
                    </a>
                    <a class="cc-hub-button <?php echo $current_view === 'shippos' ? 'cc-hub-button-active' : ''; ?>" href="<?php echo esc_url($ship_position_url); ?>">
                        <span class="cc-hub-title">Skibets position</span>
                        <span class="cc-hub-subtitle">Live AIS-kort via VesselFinder</span>
                    </a>
                </div>
            </details>
            <script>
            (function () {
                var menu = document.querySelector('.cc-hub-menu');
                if (!menu) {
                    return;
                }

                var links = menu.querySelectorAll('.cc-hub-button');
                links.forEach(function (link) {
                    link.addEventListener('click', function () {
                        menu.removeAttribute('open');
                    });
                });
            })();
            </script>
        </section>

        <?php if ($current_view === 'home') : ?>
            <section class="cc-card">
                <h3>Velkommen</h3>
                <p>Brug de store knapper i menuen ovenfor til at gå direkte til hver del af pluginet. Denne enkelt-side struktur er hurtig og mobilvenlig.</p>
            </section>
        <?php endif; ?>

        <?php if ($current_view === 'itinerary') : ?>
        <section class="cc-card">
            <h3>Rejseplan og havneinformation</h3>
            <div class="cc-table-wrap">
                <table class="cc-table">
                    <thead>
                        <tr>
                            <th>Dag</th>
                            <th>Dato</th>
                            <th>Havn</th>
                            <th>Ankomst</th>
                            <th>Afgang</th>
                            <th>Alle ombord</th>
                            <th>Havnenoter</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($days as $day) : ?>
                        <tr>
                            <td><?php echo esc_html((string) $day->day_number); ?></td>
                            <td><?php echo esc_html(wp_date('M j, Y', strtotime($day->sail_date))); ?></td>
                            <td>
                                <?php if ((int) $day->is_sea_day === 1) : ?>
                                    Til soes
                                <?php else : ?>
                                    <?php $port_info_url = add_query_arg(array('cc_view' => 'mustdo', 'cc_port' => (string) $day->port_name), $base_url); ?>
                                    <strong><?php echo esc_html((string) $day->port_name); ?></strong><br>
                                    <span class="cc-muted"><?php echo esc_html((string) $day->city . ', ' . (string) $day->country); ?></span><br>
                                    <a class="cc-inline-link" href="<?php echo esc_url($port_info_url); ?>">Se havneinfo</a>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($day->arrival_time ? wp_date('H:i', strtotime($day->arrival_time)) : '-'); ?></td>
                            <td><?php echo esc_html($day->departure_time ? wp_date('H:i', strtotime($day->departure_time)) : '-'); ?></td>
                            <td><?php echo esc_html($day->all_aboard_time ? wp_date('H:i', strtotime($day->all_aboard_time)) : '-'); ?></td>
                            <td>
                                <?php if ((int) $day->is_sea_day === 0) : ?>
                                    <?php $must_do_places = cc_get_must_do_sights_for_port((string) $day->port_name); ?>
                                    <div class="cc-port-notes">
                                        <div><strong>Terminal:</strong> <?php echo esc_html((string) $day->terminal_address); ?></div>
                                        <div><strong>Tidszone:</strong> <?php echo esc_html((string) $day->timezone); ?></div>
                                        <div><strong>Noednummer:</strong> <?php echo esc_html((string) $day->emergency_number); ?></div>
                                        <div class="cc-muted"><?php echo esc_html((string) $day->notes); ?></div>
                                        <?php if (!empty($must_do_places)) : ?>
                                            <details class="cc-port-must-do">
                                                <summary>Must-do sevardigheder</summary>
                                                <ul class="cc-port-must-do-list">
                                                    <?php foreach ($must_do_places as $place) : ?>
                                                        <li><?php echo esc_html((string) $place); ?></li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </details>
                                        <?php endif; ?>
                                    </div>
                                <?php else : ?>
                                    -
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($current_view === 'mustdo') : ?>
        <section class="cc-card">
            <h3><?php echo $is_specific_port_view ? 'Havneoplysninger og must-do sevardigheder' : 'Dagens havn: oplysninger og must-do sevardigheder'; ?></h3>
            <?php if (!$selected_port_day) : ?>
                <p>Der er ingen havn at vise lige nu (soedag).</p>
            <?php else : ?>
                <?php $today_must_do = cc_get_must_do_sights_for_port((string) $selected_port_day->port_name); ?>
                <p>
                    Dag <strong><?php echo esc_html((string) $selected_port_day->day_number); ?></strong>
                    (<?php echo esc_html(wp_date('M j, Y', strtotime($selected_port_day->sail_date))); ?>)
                    - <strong><?php echo esc_html((string) $selected_port_day->port_name); ?></strong>
                </p>

                <div class="cc-port-notes">
                    <div><strong>By/land:</strong> <?php echo esc_html((string) $selected_port_day->city . ', ' . (string) $selected_port_day->country); ?></div>
                    <div><strong>Terminal:</strong> <?php echo esc_html((string) $selected_port_day->terminal_address); ?></div>
                    <div><strong>Tidszone:</strong> <?php echo esc_html((string) $selected_port_day->timezone); ?></div>
                    <div><strong>Noednummer:</strong> <?php echo esc_html((string) $selected_port_day->emergency_number); ?></div>
                    <div class="cc-muted"><?php echo esc_html((string) $selected_port_day->notes); ?></div>
                </div>

                <?php if ($selected_port_weather) : ?>
                    <div class="cc-port-notes" style="margin-top: 12px;">
                        <div><strong><?php echo esc_html($selected_port_weather['is_today'] ? 'Vejr i dag:' : 'Vejrudsigt for ' . wp_date('M j, Y', strtotime((string) $selected_port_weather['date'])) . ':'); ?></strong> <?php echo esc_html(cc_weather_code_label($selected_port_weather['current_weather_code'] ?? $selected_port_weather['daily_weather_code'] ?? null)); ?></div>
                        <div><strong>Temperatur:</strong> <?php echo esc_html(!empty($selected_port_weather['is_today']) ? ('lige nu ' . cc_format_weather_temp($selected_port_weather['current_temperature'] ?? null)) : cc_format_weather_temp($selected_port_weather['daily_temperature_max'] ?? null)); ?> (maks. <?php echo esc_html(cc_format_weather_temp($selected_port_weather['daily_temperature_max'] ?? null)); ?>, min. <?php echo esc_html(cc_format_weather_temp($selected_port_weather['daily_temperature_min'] ?? null)); ?>)</div>
                        <?php if (!empty($selected_port_weather['is_today'])) : ?>
                            <div><strong>Føles som:</strong> <?php echo esc_html(cc_format_weather_temp($selected_port_weather['current_feels_like'] ?? null)); ?></div>
                            <div><strong>Vind:</strong> <?php echo esc_html(cc_format_weather_speed($selected_port_weather['current_wind_speed'] ?? $selected_port_weather['daily_wind_speed_max'] ?? null)); ?>, vindstød <?php echo esc_html(cc_format_weather_speed($selected_port_weather['current_wind_gusts'] ?? $selected_port_weather['daily_wind_gusts_max'] ?? null)); ?></div>
                        <?php else : ?>
                            <div><strong>Vind:</strong> <?php echo esc_html(cc_format_weather_speed($selected_port_weather['daily_wind_speed_max'] ?? null)); ?>, vindstød <?php echo esc_html(cc_format_weather_speed($selected_port_weather['daily_wind_gusts_max'] ?? null)); ?></div>
                        <?php endif; ?>
                        <div><strong>Nedbør:</strong> <?php echo esc_html(isset($selected_port_weather['daily_precipitation_sum']) ? round((float) $selected_port_weather['daily_precipitation_sum'], 1) . ' mm' : '-'); ?>, sandsynlighed <?php echo esc_html(isset($selected_port_weather['daily_precipitation_probability_max']) ? (string) $selected_port_weather['daily_precipitation_probability_max'] . '%' : '-'); ?></div>
                        <?php if (!empty($selected_port_weather['used_today_fallback'])) : ?>
                            <div class="cc-muted">Sejldatoen ligger uden for Open-Meteos forecast-horisont, saa her vises dagens vejr i havnen i stedet.</div>
                        <?php endif; ?>
                        <div class="cc-muted">Kilde: <a class="cc-inline-link" href="<?php echo esc_url($selected_port_weather['source_url']); ?>" target="_blank" rel="noopener noreferrer">Open-Meteo</a></div>
                    </div>
                <?php else : ?>
                    <p class="cc-muted" style="margin-top: 12px;">Vejr kunne ikke hentes lige nu fra Open-Meteo for denne havn.</p>
                <?php endif; ?>

                <ul class="cc-port-must-do-list">
                    <?php foreach ($today_must_do as $place) : ?>
                        <li><?php echo esc_html((string) $place); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if ($current_view === 'feed') : ?>
        <section class="cc-card">
            <h3>Gruppens WhatsApp chat</h3>
            <p>Alle gruppediskussioner foregaar i vores private WhatsApp gruppe.</p>
            <?php 
                $whatsapp_url = cc_get_whatsapp_url();
                if ($whatsapp_url !== '' && strpos($whatsapp_url, 'chat.whatsapp.com') !== false) : 
            ?>
                <a class="cc-button" href="<?php echo esc_url($whatsapp_url); ?>" target="_blank" rel="noopener noreferrer">
                    Gaa til WhatsApp gruppe
                </a>
            <?php else : ?>
                <p class="cc-muted">WhatsApp gruppe-link er ikke konfigureret endnu. Kontakt en administrator.</p>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if ($current_view === 'quiz') : ?>
        <section class="cc-card">
            <h3>Dagens havnequiz (kun multiple choice)</h3>
            <?php if (!$quiz_day) : ?>
                <p>Ingen havnedag fundet til quiz.</p>
            <?php else : ?>
                <p>
                    Quiz-dag: <strong>Dag <?php echo esc_html((string) $quiz_day->day_number); ?></strong>
                    (<?php echo esc_html(wp_date('M j, Y', strtotime($quiz_day->sail_date))); ?>)
                    <?php if (!empty($quiz_day->port_name)) : ?>
                        - <?php echo esc_html((string) $quiz_day->port_name); ?>
                    <?php endif; ?>
                </p>

                <?php if ($quiz_attempt) : ?>
                    <div class="cc-notice cc-notice-success">
                        Du har allerede gennemfoert dit ene rangerede forsoeg for denne dag: <?php echo esc_html((string) $quiz_attempt->score); ?>/<?php echo esc_html((string) $quiz_attempt->total_questions); ?>.
                    </div>
                    <?php
                    $submitted_answers = json_decode((string) $quiz_attempt->answers_json, true);
                    if (!is_array($submitted_answers)) {
                        $submitted_answers = array();
                    }
                    ?>
                    <?php if (!empty($quiz_questions)) : ?>
                        <div class="cc-quiz-review">
                            <h4>Dine svar og sjove fakta</h4>
                            <?php foreach ($quiz_questions as $index => $question) : ?>
                                <?php
                                $selected = isset($submitted_answers[$question->id]) ? (string) $submitted_answers[$question->id] : '';
                                ?>
                                <div class="cc-quiz-question">
                                    <p><strong><?php echo esc_html(($index + 1) . '. ' . $question->question_text); ?></strong></p>
                                    <p>
                                        Dit svar: <?php echo esc_html($selected !== '' ? $selected : 'Ikke besvaret'); ?>
                                    </p>
                                    <p class="cc-muted"><strong>Sjov fakta:</strong> <?php echo esc_html((string) $question->fun_fact); ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if (!$quiz_attempt && count($quiz_questions) === 5) : ?>
                    <form method="post" class="cc-form">
                        <?php wp_nonce_field('cc_frontend_action', 'cc_nonce'); ?>
                        <input type="hidden" name="cc_action" value="submit_quiz">
                        <input type="hidden" name="cc_return_url" value="<?php echo esc_url($quiz_url); ?>">
                        <input type="hidden" name="cc_quiz_day_id" value="<?php echo esc_attr((string) $quiz_day->id); ?>">

                        <?php foreach ($quiz_questions as $index => $question) : ?>
                            <fieldset class="cc-quiz-question">
                                <legend><?php echo esc_html(($index + 1) . '. ' . $question->question_text); ?></legend>
                                <label><input type="radio" name="cc_answer_<?php echo esc_attr((string) $question->id); ?>" value="A" required> <?php echo esc_html((string) $question->option_a); ?></label>
                                <label><input type="radio" name="cc_answer_<?php echo esc_attr((string) $question->id); ?>" value="B"> <?php echo esc_html((string) $question->option_b); ?></label>
                                <label><input type="radio" name="cc_answer_<?php echo esc_attr((string) $question->id); ?>" value="C"> <?php echo esc_html((string) $question->option_c); ?></label>
                                <label><input type="radio" name="cc_answer_<?php echo esc_attr((string) $question->id); ?>" value="D"> <?php echo esc_html((string) $question->option_d); ?></label>
                            </fieldset>
                        <?php endforeach; ?>

                        <button class="cc-button" type="submit">Indsend rangeret forsoeg</button>
                    </form>
                <?php elseif (!$quiz_attempt) : ?>
                    <p class="cc-muted">Quiz er ikke tilgaengelig for denne dag. Tilfoej praecis 5 spoergsmaal i admin.</p>
                <?php endif; ?>

                <?php if (!empty($leaderboard)) : ?>
                    <h4>Gruppens rangliste for denne dag</h4>
                    <ol class="cc-leaderboard">
                        <?php foreach ($leaderboard as $row) : ?>
                            <li>
                                <strong><?php echo esc_html((string) $row->display_name); ?></strong>
                                - <?php echo esc_html((string) $row->score); ?>/<?php echo esc_html((string) $row->total_questions); ?>
                                <span class="cc-muted">(<?php echo esc_html(wp_date('H:i', strtotime($row->created_at))); ?>)</span>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if ($current_view === 'poll') : ?>
        <section class="cc-card">
            <h3>Hurtige afstemninger</h3>

            <details class="cc-poll-create-details">
                <summary class="cc-poll-create-summary">Opret ny afstemning</summary>

                <form method="post" class="cc-form cc-poll-create-form">
                    <?php wp_nonce_field('cc_frontend_action', 'cc_nonce'); ?>
                    <input type="hidden" name="cc_action" value="create_poll">
                    <input type="hidden" name="cc_return_url" value="<?php echo esc_url($poll_url); ?>">

                    <div class="cc-form-group">
                        <label for="cc_poll_question"><strong>Spoergsmaal</strong></label>
                        <input type="text" id="cc_poll_question" name="cc_poll_question" class="cc-input" placeholder="Fx: Hvad vil vi spise til aftensmad?" maxlength="255" required>
                    </div>

                    <div class="cc-form-group">
                        <label><strong>Svarmuligheder</strong> <span class="cc-muted">(2–6 muligheder)</span></label>
                        <?php for ($i = 1; $i <= 4; $i++) : ?>
                            <input type="text" name="cc_poll_options[]" class="cc-input cc-poll-option-input" placeholder="Mulighed <?php echo esc_attr((string) $i); ?>" maxlength="255"<?php echo $i <= 2 ? ' required' : ''; ?>>
                        <?php endfor; ?>
                    </div>

                    <button class="cc-button" type="submit">Opret afstemning</button>
                </form>
            </details>

            <?php if (empty($polls)) : ?>
                <p class="cc-muted" style="margin-top: 1.5rem;">Ingen afstemninger endnu. Opret den foerste ovenfor.</p>
            <?php else : ?>
                <div class="cc-poll-list" style="margin-top: 1.5rem;">
                    <?php foreach ($polls as $poll) : ?>
                        <?php
                        $poll_id = (int) $poll->id;
                        $poll_options = cc_get_poll_options($poll_id);
                        $user_vote = cc_get_user_vote_for_poll($poll_id, $user_id);
                        $has_voted = $user_vote !== null;
                        $total_votes = 0;
                        foreach ($poll_options as $opt) {
                            $total_votes += (int) $opt->vote_count;
                        }
                        ?>
                        <div class="cc-poll-item">
                            <div class="cc-poll-header">
                                <strong class="cc-poll-question"><?php echo esc_html((string) $poll->question); ?></strong>
                                <span class="cc-muted cc-poll-meta">
                                    Oprettet af <?php echo esc_html((string) $poll->display_name); ?>
                                    · <?php echo esc_html(wp_date('j. M H:i', strtotime($poll->created_at))); ?>
                                    · <?php echo esc_html((string) $total_votes); ?> stemme<?php echo $total_votes !== 1 ? 'r' : ''; ?>
                                </span>
                                <?php if ((int) $poll->user_id === $user_id) : ?>
                                    <form method="post" class="cc-poll-delete-form" onsubmit="return confirm('Slet denne afstemning?');">
                                        <?php wp_nonce_field('cc_frontend_action', 'cc_nonce'); ?>
                                        <input type="hidden" name="cc_action" value="delete_poll">
                                        <input type="hidden" name="cc_return_url" value="<?php echo esc_url($poll_url); ?>">
                                        <input type="hidden" name="cc_poll_id" value="<?php echo esc_attr((string) $poll_id); ?>">
                                        <button class="cc-button cc-button-danger cc-button-small" type="submit">Slet</button>
                                    </form>
                                <?php endif; ?>
                            </div>

                            <?php if (!$has_voted) : ?>
                                <form method="post" class="cc-form cc-poll-vote-form">
                                    <?php wp_nonce_field('cc_frontend_action', 'cc_nonce'); ?>
                                    <input type="hidden" name="cc_action" value="vote_poll">
                                    <input type="hidden" name="cc_return_url" value="<?php echo esc_url($poll_url); ?>">
                                    <input type="hidden" name="cc_poll_id" value="<?php echo esc_attr((string) $poll_id); ?>">

                                    <div class="cc-poll-options">
                                        <?php foreach ($poll_options as $option) : ?>
                                            <label class="cc-poll-option-label">
                                                <input type="radio" name="cc_poll_option_id" value="<?php echo esc_attr((string) $option->id); ?>" required>
                                                <?php echo esc_html((string) $option->option_text); ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>

                                    <button class="cc-button cc-button-small" type="submit">Afgiv stemme</button>
                                </form>
                            <?php else : ?>
                                <div class="cc-poll-results">
                                    <?php foreach ($poll_options as $option) : ?>
                                        <?php
                                        $vote_count = (int) $option->vote_count;
                                        $pct = $total_votes > 0 ? round($vote_count / $total_votes * 100) : 0;
                                        $is_my_vote = (int) $user_vote->option_id === (int) $option->id;
                                        ?>
                                        <div class="cc-poll-result-row<?php echo $is_my_vote ? ' cc-poll-my-vote' : ''; ?>">
                                            <div class="cc-poll-result-label">
                                                <?php if ($is_my_vote) : ?><span class="cc-poll-checkmark">&#10003;</span> <?php endif; ?>
                                                <?php echo esc_html((string) $option->option_text); ?>
                                            </div>
                                            <div class="cc-poll-result-bar-wrap">
                                                <div class="cc-poll-result-bar" style="width: <?php echo esc_attr((string) $pct); ?>%"></div>
                                                <span class="cc-poll-result-pct"><?php echo esc_html((string) $pct); ?>% (<?php echo esc_html((string) $vote_count); ?>)</span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>

                <?php if ($current_view === 'shippos') : ?>
                <section class="cc-card">
                        <h3>Skibets position</h3>
                        <script type="text/javascript">
                              var ccViewportWidth = Math.max(document.documentElement.clientWidth || 0, window.innerWidth || 0);
                              var width="100%";
                              var height = ccViewportWidth <= 480 ? "260" : (ccViewportWidth <= 768 ? "320" : "400");
                              var zoom = ccViewportWidth <= 480 ? "6" : "7";
                            var imo=9387085;
                        </script><script type="text/javascript" src="https://www.vesselfinder.com/aismap.js"></script>
                </section>
                <?php endif; ?>
    </div>
    <?php

    return ob_get_clean();
}

// ---- Web Push / VAPID --------------------------------------------------------

function cc_base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function cc_base64url_decode($data) {
    return base64_decode(strtr((string) $data, '-_', '+/'));
}

function cc_vapid_extract_public_uncompressed_from_private($private_pem) {
    $private_key = openssl_pkey_get_private((string) $private_pem);
    if (!$private_key) {
        return null;
    }

    $details = openssl_pkey_get_details($private_key);
    if (!$details || !isset($details['ec']['x'], $details['ec']['y'])) {
        return null;
    }

    $x = str_pad((string) $details['ec']['x'], 32, "\x00", STR_PAD_LEFT);
    $y = str_pad((string) $details['ec']['y'], 32, "\x00", STR_PAD_LEFT);

    return "\x04" . $x . $y;
}

/**
 * Generate or retrieve the VAPID key pair (P-256 / prime256v1).
 * Returns array with 'private_pem' and 'public_uncompressed' (65-byte raw string),
 * or null if the OpenSSL extension does not support EC keys.
 */
function cc_vapid_get_keys() {
    $stored = get_option('cc_vapid_keys', null);
    if ($stored !== null) {
        if (!is_array($stored) || empty($stored['private_pem'])) {
            // Do not silently rotate keys when stored config exists but is invalid.
            return null;
        }

        $private_pem = (string) $stored['private_pem'];

        if (!empty($stored['public_b64'])) {
            $decoded = cc_base64url_decode((string) $stored['public_b64']);
            if (is_string($decoded) && strlen($decoded) === 65) {
                return array(
                    'private_pem' => $private_pem,
                    'public_uncompressed' => $decoded,
                    'public_b64' => (string) $stored['public_b64'],
                );
            }
        }

        if (!empty($stored['public_uncompressed']) && strlen((string) $stored['public_uncompressed']) === 65) {
            $public_uncompressed = (string) $stored['public_uncompressed'];
            $public_b64 = cc_base64url_encode($public_uncompressed);
            update_option('cc_vapid_keys', array(
                'private_pem' => $private_pem,
                'public_b64' => $public_b64,
            ), false);

            return array(
                'private_pem' => $private_pem,
                'public_uncompressed' => $public_uncompressed,
                'public_b64' => $public_b64,
            );
        }

        $derived_public = cc_vapid_extract_public_uncompressed_from_private($private_pem);
        if (is_string($derived_public) && strlen($derived_public) === 65) {
            $public_b64 = cc_base64url_encode($derived_public);
            update_option('cc_vapid_keys', array(
                'private_pem' => $private_pem,
                'public_b64' => $public_b64,
            ), false);

            return array(
                'private_pem' => $private_pem,
                'public_uncompressed' => $derived_public,
                'public_b64' => $public_b64,
            );
        }

        // Stored key exists but cannot be recovered: fail closed (no auto-regeneration).
        return null;
    }

    if (!function_exists('openssl_pkey_new')) {
        return null;
    }

    $curves = function_exists('openssl_get_curve_names') ? openssl_get_curve_names() : array();
    if (!in_array('prime256v1', $curves, true)) {
        return null;
    }

    $key = openssl_pkey_new(array(
        'curve_name'       => 'prime256v1',
        'private_key_type' => OPENSSL_KEYTYPE_EC,
    ));
    if (!$key) {
        return null;
    }

    $private_pem = '';
    if (!openssl_pkey_export($key, $private_pem)) {
        return null;
    }

    $details = openssl_pkey_get_details($key);
    if (!$details || !isset($details['ec']['x'], $details['ec']['y'])) {
        return null;
    }

    // EC coordinates are raw binary; pad each to exactly 32 bytes.
    $x = str_pad((string) $details['ec']['x'], 32, "\x00", STR_PAD_LEFT);
    $y = str_pad((string) $details['ec']['y'], 32, "\x00", STR_PAD_LEFT);
    $public_uncompressed = "\x04" . $x . $y; // uncompressed point, 65 bytes

    $public_b64 = cc_base64url_encode($public_uncompressed);
    update_option('cc_vapid_keys', array(
        'private_pem' => $private_pem,
        'public_b64' => $public_b64,
    ), false);

    return array(
        'private_pem' => $private_pem,
        'public_uncompressed' => $public_uncompressed,
        'public_b64' => $public_b64,
    );
}

/**
 * Convert a DER-encoded ECDSA signature to the raw r||s (64-byte) format
 * required by VAPID JWT (ES256).
 */
function cc_ecdsa_der_to_raw($der) {
    $offset = 0;
    if (ord($der[$offset++]) !== 0x30) {
        return false;
    }

    // Outer length (1 or 2 bytes).
    $outer_len = ord($der[$offset++]);
    if ($outer_len & 0x80) {
        $lb = $outer_len & 0x7f;
        $outer_len = 0;
        for ($i = 0; $i < $lb; $i++) {
            $outer_len = ($outer_len << 8) | ord($der[$offset++]);
        }
    }

    // Integer r.
    if (ord($der[$offset++]) !== 0x02) {
        return false;
    }
    $r_len = ord($der[$offset++]);
    $r = substr($der, $offset, $r_len);
    $offset += $r_len;

    // Integer s.
    if (ord($der[$offset++]) !== 0x02) {
        return false;
    }
    $s_len = ord($der[$offset++]);
    $s = substr($der, $offset, $s_len);

    // DER may prepend a 0x00 byte for sign; strip it.
    if (strlen($r) === 33 && ord($r[0]) === 0x00) {
        $r = substr($r, 1);
    }
    if (strlen($s) === 33 && ord($s[0]) === 0x00) {
        $s = substr($s, 1);
    }

    return str_pad($r, 32, "\x00", STR_PAD_LEFT)
         . str_pad($s, 32, "\x00", STR_PAD_LEFT);
}

/**
 * Build a VAPID JWT (ES256) for the given push endpoint.
 */
function cc_vapid_build_jwt($push_endpoint, $private_key_pem) {
    $parts = wp_parse_url($push_endpoint);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
        return false;
    }
    $audience = $parts['scheme'] . '://' . $parts['host'];

    $header  = cc_base64url_encode((string) wp_json_encode(array('typ' => 'JWT', 'alg' => 'ES256')));
    $payload = cc_base64url_encode((string) wp_json_encode(array(
        'aud' => $audience,
        'exp' => time() + 43200,
        'sub' => 'mailto:' . (string) get_option('admin_email'),
    )));
    $signing_input = $header . '.' . $payload;

    $private_key = openssl_pkey_get_private($private_key_pem);
    if (!$private_key) {
        return false;
    }

    $signature_der = '';
    if (!openssl_sign($signing_input, $signature_der, $private_key, OPENSSL_ALGO_SHA256)) {
        return false;
    }

    $signature_raw = cc_ecdsa_der_to_raw($signature_der);
    if ($signature_raw === false) {
        return false;
    }

    return $signing_input . '.' . cc_base64url_encode($signature_raw);
}

/**
 * Fire-and-forget VAPID push to a single endpoint (no encrypted payload).
 * The service worker fetches poll details itself on the push event.
 */
function cc_send_web_push($endpoint, $vapid_public_uncompressed, $private_key_pem) {
    $jwt = cc_vapid_build_jwt($endpoint, $private_key_pem);
    if (!$jwt) {
        return false;
    }

    $public_key_b64 = cc_base64url_encode((string) $vapid_public_uncompressed);
    $authorization_header = cc_build_webpush_authorization_header($endpoint, $jwt, $public_key_b64);

    wp_remote_post($endpoint, array(
        'timeout'  => 8,
        'blocking' => false, // fire-and-forget; do not delay the page redirect
        'headers'  => array(
            'Authorization' => $authorization_header,
            // Keep backwards compatibility with push services that still read VAPID key from Crypto-Key.
            'Crypto-Key'    => 'p256ecdsa=' . $public_key_b64,
            'TTL'           => '86400',
            'Urgency'       => 'normal',
        ),
        'body'     => '',
    ));

    return true;
}

function cc_build_webpush_authorization_header($endpoint, $jwt, $public_key_b64) {
    $host = wp_parse_url((string) $endpoint, PHP_URL_HOST);
    $host = is_string($host) ? strtolower($host) : '';

    // FCM expects the WebPush JWT style in Authorization.
    if ($host === 'fcm.googleapis.com' || substr($host, -19) === '.fcm.googleapis.com') {
        return 'WebPush ' . (string) $jwt;
    }

    // RFC8292-compatible format for other push services.
    return 'vapid t=' . (string) $jwt . ',k=' . (string) $public_key_b64;
}

/**
 * Send a push notification to every group member except the poll creator.
 */
function cc_send_poll_push_notifications($group_id, $creator_user_id) {
    $vapid_keys = cc_vapid_get_keys();
    if (!$vapid_keys) {
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'cc_push_subscriptions';
    $subscriptions = $wpdb->get_results($wpdb->prepare(
        "SELECT endpoint FROM $table WHERE group_id = %d AND user_id != %d",
        (int) $group_id,
        (int) $creator_user_id
    ));

    if (empty($subscriptions)) {
        return;
    }

    foreach ($subscriptions as $sub) {
        cc_send_web_push(
            (string) $sub->endpoint,
            (string) $vapid_keys['public_uncompressed'],
            (string) $vapid_keys['private_pem']
        );
    }
}

/**
 * Save (or update) a push subscription for a user.
 */
function cc_upsert_push_subscription($user_id, $group_id, $endpoint, $p256dh, $auth) {
    global $wpdb;

    $table = $wpdb->prefix . 'cc_push_subscriptions';

    // Prefer deduplication by subscription keys (device/browser instance).
    $existing_by_keys_id = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $table WHERE user_id = %d AND group_id = %d AND p256dh = %s AND auth = %s ORDER BY id DESC LIMIT 1",
        (int) $user_id,
        (int) $group_id,
        (string) $p256dh,
        (string) $auth
    ));

    if ($existing_by_keys_id > 0) {
        $wpdb->update(
            $table,
            array(
                'endpoint'   => $endpoint,
                'group_id'   => (int) $group_id,
                'created_at' => current_time('mysql'),
            ),
            array('id' => $existing_by_keys_id),
            array('%s', '%d', '%s'),
            array('%d')
        );
        cc_prune_push_subscriptions((int) $user_id, (int) $group_id, 4);
        return;
    }

    // Update keys if this endpoint already exists for this user.
    $existing_id = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $table WHERE user_id = %d AND endpoint = %s LIMIT 1",
        (int) $user_id,
        $endpoint
    ));

    if ($existing_id > 0) {
        $wpdb->update(
            $table,
            array(
                'p256dh'   => $p256dh,
                'auth'     => $auth,
                'group_id' => (int) $group_id,
                // Touch timestamp so active subscriptions are retained by pruning.
                'created_at' => current_time('mysql'),
            ),
            array('id' => $existing_id),
            array('%s', '%s', '%d', '%s'),
            array('%d')
        );
        cc_prune_push_subscriptions((int) $user_id, (int) $group_id, 4);
        return;
    }

    $wpdb->insert(
        $table,
        array(
            'user_id'    => (int) $user_id,
            'group_id'   => (int) $group_id,
            'endpoint'   => $endpoint,
            'p256dh'     => $p256dh,
            'auth'       => $auth,
            'created_at' => current_time('mysql'),
        ),
        array('%d', '%d', '%s', '%s', '%s', '%s')
    );

    cc_prune_push_subscriptions((int) $user_id, (int) $group_id, 4);
}

/**
 * Keep only the newest N subscriptions per user+group to avoid uncontrolled growth.
 */
function cc_prune_push_subscriptions($user_id, $group_id, $keep_latest = 4) {
    global $wpdb;

    $keep_latest = max(1, (int) $keep_latest);
    $table = $wpdb->prefix . 'cc_push_subscriptions';

    // Remove duplicate rows for the same logical subscription keys, keep newest id.
    $key_rows = $wpdb->get_results($wpdb->prepare(
        "SELECT p256dh, auth, MAX(id) AS keep_id, COUNT(*) AS cnt
         FROM $table
         WHERE user_id = %d AND group_id = %d
         GROUP BY p256dh, auth
         HAVING COUNT(*) > 1",
        (int) $user_id,
        (int) $group_id
    ));

    if (!empty($key_rows)) {
        foreach ($key_rows as $row) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM $table
                 WHERE user_id = %d
                   AND group_id = %d
                   AND p256dh = %s
                   AND auth = %s
                   AND id != %d",
                (int) $user_id,
                (int) $group_id,
                (string) $row->p256dh,
                (string) $row->auth,
                (int) $row->keep_id
            ));
        }
    }

    // Remove duplicate rows for the same endpoint and user, keep the newest id.
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT endpoint, MAX(id) AS keep_id, COUNT(*) AS cnt
         FROM $table
         WHERE user_id = %d AND group_id = %d
         GROUP BY endpoint
         HAVING COUNT(*) > 1",
        (int) $user_id,
        (int) $group_id
    ));

    if (!empty($rows)) {
        foreach ($rows as $row) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM $table
                 WHERE user_id = %d
                   AND group_id = %d
                   AND endpoint = %s
                   AND id != %d",
                (int) $user_id,
                (int) $group_id,
                (string) $row->endpoint,
                (int) $row->keep_id
            ));
        }
    }

    $to_delete = $wpdb->get_col($wpdb->prepare(
        "SELECT id FROM $table
         WHERE user_id = %d AND group_id = %d
         ORDER BY created_at DESC, id DESC
         LIMIT 999 OFFSET %d",
        (int) $user_id,
        (int) $group_id,
        $keep_latest
    ));

    if (!empty($to_delete)) {
        foreach ($to_delete as $id) {
            $wpdb->delete($table, array('id' => (int) $id), array('%d'));
        }
    }
}

/**
 * Handle POST /?cc_push_subscribe=1 — store the browser push subscription.
 */
function cc_serve_push_subscribe() {
    nocache_headers();
    header('Content-Type: application/json; charset=' . get_option('blog_charset'));

    if ((string) $_SERVER['REQUEST_METHOD'] !== 'POST') {
        status_header(405);
        echo wp_json_encode(array('ok' => false, 'error' => 'method_not_allowed'));
        exit;
    }

    $user_id = cc_get_effective_user_id();
    if ($user_id <= 0) {
        status_header(401);
        echo wp_json_encode(array('ok' => false, 'error' => 'not_logged_in'));
        exit;
    }

    $group_id = cc_current_user_group_id($user_id);
    if ($group_id <= 0) {
        status_header(403);
        echo wp_json_encode(array('ok' => false, 'error' => 'no_group'));
        exit;
    }

    $raw  = (string) file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!is_array($data)
        || empty($data['endpoint'])
        || empty($data['keys']['p256dh'])
        || empty($data['keys']['auth'])
    ) {
        status_header(400);
        echo wp_json_encode(array('ok' => false, 'error' => 'invalid_data'));
        exit;
    }

    $endpoint = esc_url_raw((string) $data['endpoint']);
    $p256dh   = sanitize_text_field((string) $data['keys']['p256dh']);
    $auth     = sanitize_text_field((string) $data['keys']['auth']);

    if ($endpoint === '' || $p256dh === '' || $auth === '') {
        status_header(400);
        echo wp_json_encode(array('ok' => false, 'error' => 'invalid_fields'));
        exit;
    }

    cc_upsert_push_subscription($user_id, $group_id, $endpoint, $p256dh, $auth);

    echo wp_json_encode(array('ok' => true));
    exit;
}
