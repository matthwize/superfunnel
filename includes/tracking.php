<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_enqueue_scripts', 'superfunnel_enqueue_tracking', 99);
add_action('init', 'superfunnel_register_fast_endpoint', 1);
add_filter('query_vars', 'superfunnel_register_query_vars');
add_action('parse_request', 'superfunnel_maybe_handle_fast_endpoint', 0);
add_action('rest_api_init', 'superfunnel_register_rest_routes');

function superfunnel_enqueue_tracking() {
    if (is_admin()) return;

    $track_admins = false;
    if (current_user_can('manage_options') && !$track_admins) {
        return;
    }

    wp_enqueue_script(
        'superfunnel-track',
        SUPERFUNNEL_PLUGIN_URL . 'assets/track.js',
        [],
        SUPERFUNNEL_VERSION,
        true
    );

    $cfg = [
        'timeoutMs' => (int) (SUPERFUNNEL_SESSION_TIMEOUT_MINUTES * MINUTE_IN_SECONDS * 1000),
        'qualifyDelayMs' => (int) SUPERFUNNEL_QUALIFY_DELAY_MS,
        'endpointFast' => add_query_arg('superfunnel_track', '1', home_url('/')),
                'endpointRest' => rest_url('superfunnel/v1/track'),
    ];

    wp_add_inline_script(
        'superfunnel-track',
        'window.SuperfunnelTrack=' . wp_json_encode($cfg) . ';',
        'before'
    );
}

function superfunnel_register_fast_endpoint() {
    add_rewrite_rule('^superfunnel-track/?$', 'index.php?superfunnel_track=1', 'top');
}

function superfunnel_register_query_vars($vars) {
    $vars[] = 'superfunnel_track';
    return $vars;
}

function superfunnel_maybe_handle_fast_endpoint($wp) {
    if (!isset($wp->query_vars['superfunnel_track'])) {
        return;
    }

    superfunnel_handle_track_request_fast();
    exit;
}

function superfunnel_register_rest_routes() {
    register_rest_route('superfunnel/v1', '/track', [
        'methods' => 'POST',
        'callback' => 'superfunnel_handle_track_request_rest',
        'permission_callback' => '__return_true',
    ]);
}

function superfunnel_handle_track_request_rest(WP_REST_Request $request) {
    $payload = [
        'path' => (string) $request->get_param('path'),
        'session_id' => (string) $request->get_param('session_id'),
        'page_token' => (string) $request->get_param('page_token'),
        'step_number' => (string) $request->get_param('step_number'),
    ];

    $ok = superfunnel_process_tracking_payload($payload);

    return new WP_REST_Response(null, $ok ? 204 : 204);
}

function superfunnel_handle_track_request_fast() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        status_header(405);
        exit;
    }

    $payload = [
        'path' => isset($_POST['path']) ? (string) $_POST['path'] : '',
        'session_id' => isset($_POST['session_id']) ? (string) $_POST['session_id'] : '',
        'page_token' => isset($_POST['page_token']) ? (string) $_POST['page_token'] : '',
        'step_number' => isset($_POST['step_number']) ? (string) $_POST['step_number'] : '',
    ];

    superfunnel_process_tracking_payload($payload);

    status_header(204);
    exit;
}

function superfunnel_process_tracking_payload(array $payload) {
    if (!superfunnel_origin_is_allowed()) {
        return false;
    }

    if (superfunnel_is_known_bot_request()) {
        return false;
    }

    $path = superfunnel_normalize_path($payload['path'] ?? '');
    $session_id = superfunnel_normalize_session_id($payload['session_id'] ?? '');
    $page_token = superfunnel_normalize_page_token($payload['page_token'] ?? '');
    $step_number = superfunnel_normalize_step_number($payload['step_number'] ?? 1);

    if ($path === '' || $session_id === '' || $page_token === '') {
        return false;
    }

    if (superfunnel_should_ignore($path)) {
        return false;
    }

    global $wpdb;
    $table = superfunnel_get_events_table_name();
    $now = superfunnel_get_now_mysql();

    $sql = $wpdb->prepare(
        "INSERT IGNORE INTO {$table}
            (session_id, page_token, step_number, path, created_at, updated_at)
         VALUES (%s, %s, %d, %s, %s, %s)",
        $session_id,
        $page_token,
        $step_number,
        $path,
        $now,
        $now
    );

    $wpdb->query($sql);

    return true;
}
