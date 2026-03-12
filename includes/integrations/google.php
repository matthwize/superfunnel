<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_init', 'superfunnel_handle_google_oauth_return');

function superfunnel_google_client_id() {
    return superfunnel_get_config('google_client_id', SUPERFUNNEL_OPT_GOOGLE_CLIENT_ID, '');
}

function superfunnel_google_client_secret() {
    return superfunnel_get_config('google_client_secret', SUPERFUNNEL_OPT_GOOGLE_CLIENT_SECRET, '');
}

function superfunnel_google_dev_token() {
    return superfunnel_get_config('google_dev_token', SUPERFUNNEL_OPT_GOOGLE_DEV_TOKEN, '');
}

function superfunnel_get_google_connect_url() {
    $client_id = superfunnel_google_client_id();
    if (!$client_id) return '';

    $redirect = set_url_scheme(admin_url('admin.php?page=superfunnel&sf_google_auth=1'), 'https');
    $scope = 'https://www.googleapis.com/auth/adwords';

    $user_id = get_current_user_id();
    $state = wp_generate_password(20, false, false);
    set_transient('sf_google_state_' . $user_id, $state, 10 * MINUTE_IN_SECONDS);

    return 'https://accounts.google.com/o/oauth2/v2/auth?'
        . 'client_id=' . rawurlencode($client_id)
        . '&redirect_uri=' . rawurlencode($redirect)
        . '&response_type=code'
        . '&scope=' . rawurlencode($scope)
        . '&access_type=offline'
        . '&prompt=consent'
        . '&state=' . rawurlencode($state);
}

function superfunnel_handle_google_oauth_return() {
    if (!is_admin() || !current_user_can('manage_options')) return;
    if (!isset($_GET['page']) || $_GET['page'] !== 'superfunnel') return;
    if (!isset($_GET['sf_google_auth'], $_GET['code'], $_GET['state'])) return;

    $client_id = superfunnel_google_client_id();
    $client_secret = superfunnel_google_client_secret();
    if (!$client_id || !$client_secret) {
        wp_safe_redirect(admin_url('admin.php?page=superfunnel&integrations_error=1'));
        exit;
    }

    $user_id = get_current_user_id();
    $expected_state = (string) get_transient('sf_google_state_' . $user_id);
    delete_transient('sf_google_state_' . $user_id);

    $state = sanitize_text_field(wp_unslash($_GET['state']));
    if ($expected_state === '' || !hash_equals($expected_state, $state)) {
        wp_safe_redirect(admin_url('admin.php?page=superfunnel&integrations_error=1'));
        exit;
    }

    $code = sanitize_text_field(wp_unslash($_GET['code']));
    $redirect = set_url_scheme(admin_url('admin.php?page=superfunnel&sf_google_auth=1'), 'https');

    $res = wp_remote_post('https://oauth2.googleapis.com/token', [
        'timeout' => 12,
        'body' => [
            'code' => $code,
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'redirect_uri' => $redirect,
            'grant_type' => 'authorization_code',
        ],
    ]);

    if (is_wp_error($res)) {
        wp_safe_redirect(admin_url('admin.php?page=superfunnel&integrations_error=1'));
        exit;
    }

    $body = json_decode(wp_remote_retrieve_body($res), true);

    if (!empty($body['access_token'])) {
        update_option(SUPERFUNNEL_OPT_GOOGLE_TOKEN, sanitize_text_field($body['access_token']), false);
    }
    if (!empty($body['refresh_token'])) {
        update_option(SUPERFUNNEL_OPT_GOOGLE_REFRESH_TOKEN, sanitize_text_field($body['refresh_token']), false);
    }
    if (!empty($body['expires_in'])) {
        update_option(SUPERFUNNEL_OPT_GOOGLE_TOKEN_EXPIRES_AT, time() + (int) $body['expires_in'], false);
    }

    wp_safe_redirect(admin_url('admin.php?page=superfunnel&integrations_saved=1'));
    exit;
}

function superfunnel_google_refresh_token_if_needed() {
    $refresh_token = (string) get_option(SUPERFUNNEL_OPT_GOOGLE_REFRESH_TOKEN, '');
    if (!$refresh_token) return;

    $token = (string) get_option(SUPERFUNNEL_OPT_GOOGLE_TOKEN, '');
    $expires_at = (int) get_option(SUPERFUNNEL_OPT_GOOGLE_TOKEN_EXPIRES_AT, 0);

    if ($token && $expires_at && ($expires_at - 300) > time()) {
        return;
    }

    $client_id = superfunnel_google_client_id();
    $client_secret = superfunnel_google_client_secret();
    if (!$client_id || !$client_secret) return;

    $res = wp_remote_post('https://oauth2.googleapis.com/token', [
        'timeout' => 12,
        'body' => [
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'refresh_token' => $refresh_token,
            'grant_type' => 'refresh_token',
        ],
    ]);

    if (is_wp_error($res)) return;

    $body = json_decode(wp_remote_retrieve_body($res), true);

    if (!empty($body['access_token'])) {
        update_option(SUPERFUNNEL_OPT_GOOGLE_TOKEN, sanitize_text_field($body['access_token']), false);
    }
    if (!empty($body['expires_in'])) {
        update_option(SUPERFUNNEL_OPT_GOOGLE_TOKEN_EXPIRES_AT, time() + (int) $body['expires_in'], false);
    }
}

function superfunnel_get_google_stats_for_range($start_date, $end_date) {
    $cache_key = superfunnel_cache_key('google_stats', $start_date, $end_date);
    $cached = superfunnel_cache_get($cache_key);
    if ($cached !== null) return $cached;

    superfunnel_google_refresh_token_if_needed();

    $token = (string) get_option(SUPERFUNNEL_OPT_GOOGLE_TOKEN, '');
    $customer_id = (string) get_option(SUPERFUNNEL_OPT_GOOGLE_CUSTOMER, '');
    $manager_id = (string) get_option(SUPERFUNNEL_OPT_GOOGLE_MANAGER, '');
    $developer_token = (string) get_option(SUPERFUNNEL_OPT_GOOGLE_DEV_TOKEN, '');

    if (!$developer_token) {
        $developer_token = superfunnel_google_dev_token();
    }

    if (!$token || !$customer_id || !$developer_token) {
        $empty = ['spend' => 0.0, 'purchases' => 0.0, 'revenue' => 0.0];
        superfunnel_cache_set($cache_key, $empty, 10 * MINUTE_IN_SECONDS);
        return $empty;
    }

    $customer_id = str_replace('-', '', $customer_id);
    $manager_id = str_replace('-', '', $manager_id);

    $date_filter = "segments.date BETWEEN '{$start_date}' AND '{$end_date}'";

    $query = "
SELECT
  metrics.cost_micros,
  metrics.conversions,
  metrics.conversions_value
FROM campaign
WHERE {$date_filter}
";

    $res = wp_remote_post("https://googleads.googleapis.com/v22/customers/{$customer_id}/googleAds:search", [
        'timeout' => 14,
        'headers' => [
            'Authorization' => "Bearer {$token}",
            'developer-token' => $developer_token,
            'login-customer-id' => $manager_id,
            'Content-Type' => 'application/json',
        ],
        'body' => wp_json_encode(['query' => $query]),
    ]);

    if (is_wp_error($res)) {
        $empty = ['spend' => 0.0, 'purchases' => 0.0, 'revenue' => 0.0];
        superfunnel_cache_set($cache_key, $empty, 5 * MINUTE_IN_SECONDS);
        return $empty;
    }

    $data = json_decode(wp_remote_retrieve_body($res), true);

    if (!empty($data['error']) || empty($data['results'])) {
        $empty = ['spend' => 0.0, 'purchases' => 0.0, 'revenue' => 0.0];
        superfunnel_cache_set($cache_key, $empty, 5 * MINUTE_IN_SECONDS);
        return $empty;
    }

    $spend_micros = 0.0;
    $purchases = 0.0;
    $revenue = 0.0;

    foreach ($data['results'] as $row) {
        $m = $row['metrics'] ?? [];
        $spend_micros += (float) ($m['costMicros'] ?? 0);
        $purchases += (float) ($m['conversions'] ?? 0);
        $revenue += (float) ($m['conversionsValue'] ?? 0);
    }

    $result = [
        'spend' => $spend_micros / 1000000.0,
        'purchases' => $purchases,
        'revenue' => $revenue,
    ];

    superfunnel_cache_set($cache_key, $result, 10 * MINUTE_IN_SECONDS);

    return $result;
}
