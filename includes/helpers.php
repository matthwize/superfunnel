<?php
if (!defined('ABSPATH')) {
    exit;
}

function superfunnel_get_events_table_name() {
    global $wpdb;
    return $wpdb->prefix . SUPERFUNNEL_EVENTS_TABLE;
}

function superfunnel_get_now_mysql() {
    return current_time('mysql');
}

function superfunnel_sanitize_date($date) {
    $date = sanitize_text_field(wp_unslash((string) $date));
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : '';
}

function superfunnel_get_date_filters() {
    $start_date = isset($_GET['start_date']) ? superfunnel_sanitize_date($_GET['start_date']) : '';
    $end_date   = isset($_GET['end_date']) ? superfunnel_sanitize_date($_GET['end_date']) : '';

    if (!$start_date && !$end_date) {
        $today = current_time('Y-m-d');
        $start_date = $today;
        $end_date = $today;
    }

    if ($start_date && !$end_date) {
        $end_date = $start_date;
    }
    if ($end_date && !$start_date) {
        $start_date = $end_date;
    }

    return ['start_date' => $start_date, 'end_date' => $end_date];
}

function superfunnel_previous_period($start_date, $end_date) {
    $start_ts = strtotime($start_date);
    $end_ts   = strtotime($end_date);

    if (!$start_ts || !$end_ts) {
        $y = date('Y-m-d', strtotime('-1 day', current_time('timestamp')));
        return ['start_date' => $y, 'end_date' => $y, 'label' => 'vs igår'];
    }

    $days = max(1, (int) floor(($end_ts - $start_ts) / DAY_IN_SECONDS) + 1);

    $prev_end_ts = $start_ts - DAY_IN_SECONDS;
    $prev_start_ts = $prev_end_ts - (($days - 1) * DAY_IN_SECONDS);

    $label = ($days === 1 && $end_date === current_time('Y-m-d')) ? 'vs igår' : 'vs föreg. period';

    return [
        'start_date' => date('Y-m-d', $prev_start_ts),
        'end_date'   => date('Y-m-d', $prev_end_ts),
        'label'      => $label,
    ];
}

function superfunnel_cache_key($slug, $start_date = '', $end_date = '', $extra = '') {
    $raw = SUPERFUNNEL_VERSION . '|' . $slug . '|' . $start_date . '|' . $end_date . '|' . $extra;
    return 'sf_' . md5($raw);
}

function superfunnel_cache_get($key) {
    $val = get_transient($key);
    return $val === false ? null : $val;
}

function superfunnel_cache_set($key, $value, $ttl_seconds) {
    set_transient($key, $value, max(30, (int) $ttl_seconds));
}

function superfunnel_cache_delete_keys(array $keys) {
    foreach ($keys as $k) {
        delete_transient($k);
    }
}

function superfunnel_get_config($key, $fallback_option, $default = '') {
    $map = [
        SUPERFUNNEL_OPT_META_APP_ID => 'SUPERFUNNEL_META_APP_ID',
        SUPERFUNNEL_OPT_META_APP_SECRET => 'SUPERFUNNEL_META_APP_SECRET',
        SUPERFUNNEL_OPT_GOOGLE_CLIENT_ID => 'SUPERFUNNEL_GOOGLE_CLIENT_ID',
        SUPERFUNNEL_OPT_GOOGLE_CLIENT_SECRET => 'SUPERFUNNEL_GOOGLE_CLIENT_SECRET',
        SUPERFUNNEL_OPT_GOOGLE_DEV_TOKEN => 'SUPERFUNNEL_GOOGLE_DEV_TOKEN',
    ];

    $const = $map[$fallback_option] ?? null;

    if ($const && defined($const) && constant($const) !== '') {
        return (string) constant($const);
    }

    return (string) get_option($fallback_option, $default);
}

function superfunnel_normalize_path($path) {
    $path = trim(wp_unslash((string) $path));

    if ($path === '') {
        return '/';
    }

    $parsed = wp_parse_url($path);
    if (!empty($parsed['path'])) {
        $path = $parsed['path'];
    }

    $path = preg_replace('/[?#].*$/', '', $path);
    $path = $path ?: '/';

    if (strpos($path, '/') !== 0) {
        $path = '/' . $path;
    }

    $path = preg_replace('#/+#', '/', $path);

    if ($path !== '/') {
        $path = rtrim($path, '/');
        $path = $path ?: '/';
    }

    if (strpos($path, 'order-received/') !== false) {
        $path = preg_replace('#(.*order-received)/.*#', '$1', $path);
    }

    return substr($path, 0, 500);
}

function superfunnel_normalize_session_id($session_id) {
    $session_id = trim(wp_unslash((string) $session_id));
    $session_id = preg_replace('/[^A-Za-z0-9_-]/', '', $session_id);
    $session_id = substr($session_id, 0, 120);
    return strlen($session_id) >= 3 ? $session_id : '';
}

function superfunnel_normalize_page_token($page_token) {
    $page_token = trim(wp_unslash((string) $page_token));
    $page_token = preg_replace('/[^A-Za-z0-9._:-]/', '', $page_token);
    $page_token = substr($page_token, 0, 120);
    return strlen($page_token) >= 10 ? $page_token : '';
}

function superfunnel_normalize_step_number($step_number) {
    $step_number = absint($step_number);
    if ($step_number < 1) $step_number = 1;
    if ($step_number > 1000000) $step_number = 1000000;
    return $step_number;
}

function superfunnel_should_ignore($path) {
    $ignore = (string) get_option(SUPERFUNNEL_OPT_IGNORE, '');
    if (!$ignore) return false;

    $parts = array_filter(array_map('trim', explode(',', $ignore)));
    foreach ($parts as $part) {
        if ($part !== '' && stripos($path, $part) !== false) {
            return true;
        }
    }
    return false;
}

function superfunnel_is_known_bot_request() {
    $ua = strtolower((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($ua === '') return true;

    $fragments = [
        'bot','crawl','spider','headless','slurp','preview','facebookexternalhit',
        'monitor','uptime','lighthouse','pagespeed','gtmetrix','pingdom',
        'curl','wget','python-requests',
    ];

    foreach ($fragments as $fragment) {
        if (strpos($ua, $fragment) !== false) {
            return true;
        }
    }
    return false;
}

function superfunnel_origin_is_allowed() {
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin === '') return true;

    $origin_host = wp_parse_url($origin, PHP_URL_HOST);
    $site_host = wp_parse_url(home_url(), PHP_URL_HOST);

    if (!$origin_host || !$site_host) return true;

    return strtolower($origin_host) === strtolower($site_host);
}

function superfunnel_get_default_page_types() {
    return [
        'produkt' => 'produkt',
        'kassa'   => 'kassa,checkout',
        'kop'     => 'order-received',
    ];
}

function superfunnel_get_page_types() {
    $saved = get_option(SUPERFUNNEL_OPT_PAGE_TYPES, []);
    if (!is_array($saved)) $saved = [];
    return wp_parse_args($saved, superfunnel_get_default_page_types());
}

function superfunnel_match_path_parts($path, $csv_parts) {
    if ($csv_parts === '') return false;

    $parts = array_filter(array_map('trim', explode(',', (string) $csv_parts)));
    foreach ($parts as $part) {
        if ($part !== '' && stripos($path, $part) !== false) {
            return true;
        }
    }
    return false;
}

function superfunnel_get_page_type($path) {
    $path = superfunnel_normalize_path($path);
    if ($path === '/') return 'START';

    $types = superfunnel_get_page_types();

    if (superfunnel_match_path_parts($path, $types['produkt'])) return 'PRODUKT';
    if (superfunnel_match_path_parts($path, $types['kassa'])) return 'KASSA';
    if (superfunnel_match_path_parts($path, $types['kop'])) return 'KÖP';

    return 'ÖVRIGA';
}

function superfunnel_format_money($value) {
    $value = (float) $value;
    return function_exists('wc_price') ? wc_price($value, ['decimals' => 0]) : number_format_i18n($value, 0);
}

function superfunnel_format_x($value, $decimals = 2) {
    return number_format_i18n((float) $value, (int) $decimals) . 'x';
}

function superfunnel_format_percent($value, $decimals = 1) {
    return number_format_i18n((float) $value, (int) $decimals) . '%';
}

function superfunnel_delta_pill($current, $previous, $label, $higher_is_better = true, $variant = 'default') {
    $current = (float) $current;
    $previous = (float) $previous;

    $tone = 'neutral';
    $text = '—';

    if ($current == 0.0 && $previous == 0.0) {
        $tone = 'neutral';
        $text = '—';
    } elseif ($previous == 0.0) {
        $tone = $current > 0 ? ($higher_is_better ? 'good' : 'bad') : 'neutral';
        $text = ($current > 0 ? '+∞' : '—');
    } else {
        $pct = (($current / $previous) - 1.0) * 100.0;
        $text = ($pct > 0 ? '+' : '') . number_format_i18n($pct, 1) . '%';

        if (abs($pct) < 0.05) {
            $tone = 'neutral';
        } else {
            $is_good = $higher_is_better ? ($pct > 0) : ($pct < 0);
            $tone = $is_good ? 'good' : 'bad';
        }
    }

    $variant = (string) $variant;
    $variant_class = $variant !== 'default' ? ' sf-delta--' . $variant : '';

    return '<span class="sf-delta sf-delta--' . esc_attr($tone) . $variant_class . '">' .
        '<span class="sf-delta__value">' . esc_html($text) . '</span>' .
        '<span class="sf-delta__label">' . esc_html($label) . '</span>' .
    '</span>';
}


function superfunnel_render_metric_card($title, $value_html, $subtext = '', $delta_html = '') {
    return '
    <div class="sf-card">
        <div class="sf-card__top">
            <div class="sf-card__title">' . esc_html($title) . '</div>
            ' . ($delta_html ? $delta_html : '') . '
        </div>
        <div class="sf-card__value">' . wp_kses_post($value_html) . '</div>
        ' . ($subtext !== '' ? '<div class="sf-card__sub">' . esc_html($subtext) . '</div>' : '') . '
    </div>';
}

function superfunnel_render_dropoff_card($title, $visits, $dropoffs) {
    $visits = (int) $visits;
    $dropoffs = (int) $dropoffs;

    $pct = $visits > 0 ? (($dropoffs / $visits) * 100.0) : 0.0;
    $pct = max(0.0, min(100.0, $pct));

    $ratio = $dropoffs . '/' . $visits;
    $label = $ratio . ' · ' . superfunnel_format_percent($pct, 1);

    return '
    <div class="sf-card sf-drop-card">
        <div class="sf-card__top">
            <div class="sf-card__title">' . esc_html($title) . '</div>
        </div>
        <div class="sf-card__value">' . esc_html(number_format_i18n($visits)) . '</div>
        <div class="sf-drop-bar" aria-hidden="true">
            <div class="sf-drop-bar__fill" style="width:' . esc_attr($pct) . '%"></div>
        </div>
        <div class="sf-drop-meta">Dropoff <strong>' . esc_html($label) . '</strong></div>
    </div>
    ';
}


function superfunnel_render_page_type_badge($type) {
    $styles = [
        'START' => 'sf-badge--start',
        'PRODUKT' => 'sf-badge--produkt',
        'KASSA' => 'sf-badge--kassa',
        'KÖP' => 'sf-badge--kop',
        'ÖVRIGA' => 'sf-badge--ovriga',
    ];

    $class = $styles[$type] ?? $styles['ÖVRIGA'];

    return '<span class="sf-badge ' . esc_attr($class) . '">' . esc_html($type) . '</span>';
}
