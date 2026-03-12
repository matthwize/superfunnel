<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_init', 'superfunnel_handle_meta_oauth_return');

function superfunnel_meta_app_id() {
    return superfunnel_get_config('meta_app_id', SUPERFUNNEL_OPT_META_APP_ID, '');
}

function superfunnel_meta_app_secret() {
    return superfunnel_get_config('meta_app_secret', SUPERFUNNEL_OPT_META_APP_SECRET, '');
}

function superfunnel_get_meta_connect_url() {
    $app_id = superfunnel_meta_app_id();
    if (!$app_id) return '';

    $redirect = admin_url('admin.php?page=superfunnel&sf_meta_connect=1');

    $user_id = get_current_user_id();
    $state = wp_generate_password(20, false, false);

    set_transient('sf_meta_state_' . $user_id, $state, 10 * MINUTE_IN_SECONDS);

    return 'https://www.facebook.com/v19.0/dialog/oauth?' . http_build_query([
        'client_id' => $app_id,
        'redirect_uri' => $redirect,
        'scope' => 'ads_read',
        'response_type' => 'code',
        'state' => $state,
    ]);
}

function superfunnel_handle_meta_oauth_return() {
    if (!is_admin() || !current_user_can('manage_options')) return;
    if (!isset($_GET['page']) || $_GET['page'] !== 'superfunnel') return;
    if (!isset($_GET['sf_meta_connect'], $_GET['code'], $_GET['state'])) return;

    $app_id = superfunnel_meta_app_id();
    $app_secret = superfunnel_meta_app_secret();
    if (!$app_id || !$app_secret) return;

    $user_id = get_current_user_id();
    $expected_state = (string) get_transient('sf_meta_state_' . $user_id);
    delete_transient('sf_meta_state_' . $user_id);

    $state = sanitize_text_field(wp_unslash($_GET['state']));
    if ($expected_state === '' || !hash_equals($expected_state, $state)) {
        wp_safe_redirect(admin_url('admin.php?page=superfunnel&integrations_error=1'));
        exit;
    }

    $redirect = admin_url('admin.php?page=superfunnel&sf_meta_connect=1');
    $code = sanitize_text_field(wp_unslash($_GET['code']));

    $response = wp_remote_get('https://graph.facebook.com/v19.0/oauth/access_token?' . http_build_query([
        'client_id' => $app_id,
        'client_secret' => $app_secret,
        'redirect_uri' => $redirect,
        'code' => $code,
    ]), ['timeout' => 12]);

    if (is_wp_error($response)) {
        wp_safe_redirect(admin_url('admin.php?page=superfunnel&integrations_error=1'));
        exit;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    $short_token = (string) ($body['access_token'] ?? '');
    if (!$short_token) {
        wp_safe_redirect(admin_url('admin.php?page=superfunnel&integrations_error=1'));
        exit;
    }

    $exchange = wp_remote_get('https://graph.facebook.com/v19.0/oauth/access_token?' . http_build_query([
        'grant_type' => 'fb_exchange_token',
        'client_id' => $app_id,
        'client_secret' => $app_secret,
        'fb_exchange_token' => $short_token,
    ]), ['timeout' => 12]);

    if (is_wp_error($exchange)) {
        wp_safe_redirect(admin_url('admin.php?page=superfunnel&integrations_error=1'));
        exit;
    }

    $ex_body = json_decode(wp_remote_retrieve_body($exchange), true);
    $token = (string) ($ex_body['access_token'] ?? '');
    if (!$token) {
        wp_safe_redirect(admin_url('admin.php?page=superfunnel&integrations_error=1'));
        exit;
    }

    update_option(SUPERFUNNEL_OPT_META_TOKEN, $token, false);

    $accounts_res = wp_remote_get('https://graph.facebook.com/v19.0/me/adaccounts?' . http_build_query([
        'access_token' => $token,
        'fields' => 'account_id,name',
        'limit' => 200,
    ]), ['timeout' => 12]);

    if (!is_wp_error($accounts_res)) {
        $accounts_body = json_decode(wp_remote_retrieve_body($accounts_res), true);
        $accounts = $accounts_body['data'] ?? [];
        if (is_array($accounts)) {
            update_option(SUPERFUNNEL_OPT_META_ACCOUNTS, $accounts, false);

            $current = (string) get_option(SUPERFUNNEL_OPT_META_ACCOUNT, '');
            if (!$current && !empty($accounts[0]['account_id'])) {
                update_option(SUPERFUNNEL_OPT_META_ACCOUNT, sanitize_text_field($accounts[0]['account_id']), false);
            }
        }
    }

    wp_safe_redirect(admin_url('admin.php?page=superfunnel&integrations_saved=1'));
    exit;
}

function superfunnel_get_meta_stats_for_range($start_date, $end_date) {
    $cache_key = superfunnel_cache_key('meta_stats', $start_date, $end_date);
    $cached = superfunnel_cache_get($cache_key);
    if ($cached !== null) return $cached;

    $token = (string) get_option(SUPERFUNNEL_OPT_META_TOKEN, '');
    $account = (string) get_option(SUPERFUNNEL_OPT_META_ACCOUNT, '');

    if (!$token || !$account) {
        $empty = ['spend' => 0.0, 'purchases' => 0.0, 'revenue' => 0.0, 'roas' => 0.0];
        superfunnel_cache_set($cache_key, $empty, 10 * MINUTE_IN_SECONDS);
        return $empty;
    }

    $url = 'https://graph.facebook.com/v19.0/act_' . rawurlencode($account) . '/insights?' . http_build_query([
        'access_token' => $token,
        'level' => 'account',
        'fields' => 'spend,actions,action_values,purchase_roas',
        'time_range' => wp_json_encode(['since' => $start_date, 'until' => $end_date]),
    ]);

    $res = wp_remote_get($url, ['timeout' => 12]);
    if (is_wp_error($res)) {
        $empty = ['spend' => 0.0, 'purchases' => 0.0, 'revenue' => 0.0, 'roas' => 0.0];
        superfunnel_cache_set($cache_key, $empty, 5 * MINUTE_IN_SECONDS);
        return $empty;
    }

    $body = json_decode(wp_remote_retrieve_body($res), true);
    $data = $body['data'][0] ?? null;

    if (!$data) {
        $empty = ['spend' => 0.0, 'purchases' => 0.0, 'revenue' => 0.0, 'roas' => 0.0];
        superfunnel_cache_set($cache_key, $empty, 5 * MINUTE_IN_SECONDS);
        return $empty;
    }

    $spend = (float) ($data['spend'] ?? 0);
    $purchases = 0.0;
    $revenue = 0.0;

    foreach ((array) ($data['actions'] ?? []) as $a) {
        if (($a['action_type'] ?? '') === 'purchase') {
            $purchases = (float) ($a['value'] ?? 0);
            break;
        }
    }

    foreach ((array) ($data['action_values'] ?? []) as $a) {
        if (($a['action_type'] ?? '') === 'purchase') {
            $revenue = (float) ($a['value'] ?? 0);
            break;
        }
    }

    if ($revenue <= 0 && !empty($data['purchase_roas'][0]['value'])) {
        $roas_fb = (float) $data['purchase_roas'][0]['value'];
        $revenue = $spend > 0 ? ($spend * $roas_fb) : 0.0;
    }

    $roas = $spend > 0 ? ($revenue / $spend) : 0.0;

    $result = [
        'spend' => $spend,
        'purchases' => $purchases,
        'revenue' => $revenue,
        'roas' => $roas,
    ];

    superfunnel_cache_set($cache_key, $result, 10 * MINUTE_IN_SECONDS);

    return $result;
}
