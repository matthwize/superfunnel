<?php
if (!defined('ABSPATH')) {
    exit;
}

function superfunnel_get_closed_session_cutoff_mysql() {
    $timestamp = current_time('timestamp') - (SUPERFUNNEL_SESSION_TIMEOUT_MINUTES * MINUTE_IN_SECONDS);
    return gmdate('Y-m-d H:i:s', $timestamp + ((float) get_option('gmt_offset') * HOUR_IN_SECONDS));
}

function superfunnel_build_date_where($start_date, $end_date, $alias = '') {
    $field = $alias ? $alias . '.created_at' : 'created_at';

    $sql = [];
    $params = [];

    if (!empty($start_date)) {
        $sql[] = "{$field} >= %s";
        $params[] = $start_date . ' 00:00:00';
    }

    if (!empty($end_date)) {
        $sql[] = "{$field} <= %s";
        $params[] = $end_date . ' 23:59:59';
    }

    if (empty($sql)) {
        $sql[] = '1=1';
    }

    return ['sql' => implode(' AND ', $sql), 'params' => $params];
}

function superfunnel_get_sql_condition_for_type($type_key, $alias = '') {
    global $wpdb;

    $types = superfunnel_get_page_types();
    $field = $alias ? $alias . '.path' : 'path';

    if ($type_key === 'start') {
        return ['sql' => "{$field} = %s", 'params' => ['/']];
    }

    $csv = (string) ($types[$type_key] ?? '');
    $parts = array_filter(array_map('trim', explode(',', $csv)));

    if (empty($parts)) {
        return ['sql' => '1=0', 'params' => []];
    }

    $conds = [];
    $params = [];

    foreach ($parts as $part) {
        $conds[] = "{$field} LIKE %s";
        $params[] = '%' . $wpdb->esc_like($part) . '%';
    }

    return ['sql' => '(' . implode(' OR ', $conds) . ')', 'params' => $params];
}

function superfunnel_get_buyers_subquery_sql($start_date, $end_date) {
    $table = superfunnel_get_events_table_name();
    $date = superfunnel_build_date_where($start_date, $end_date, 'b');
    $buy  = superfunnel_get_sql_condition_for_type('kop', 'b');

    $sql = "
        SELECT DISTINCT b.session_id
        FROM {$table} b
        WHERE {$date['sql']} AND {$buy['sql']}
    ";

    return ['sql' => $sql, 'params' => array_merge($date['params'], $buy['params'])];
}

function superfunnel_get_visit_pairs_subquery_sql($start_date, $end_date) {
    $table = superfunnel_get_events_table_name();
    $date = superfunnel_build_date_where($start_date, $end_date, 'e');

    $sql = "
        SELECT
            e.session_id,
            e.path,
            MAX(e.created_at) AS updated_at
        FROM {$table} e
        LEFT JOIN (
            SELECT session_id, MIN(created_at) AS purchase_time
            FROM {$table}
            WHERE path LIKE '%order-received%'
            GROUP BY session_id
        ) purchases ON purchases.session_id = e.session_id
        WHERE {$date['sql']}
          AND (purchases.purchase_time IS NULL OR e.created_at <= purchases.purchase_time)
        GROUP BY e.session_id, e.path
    ";

    return ['sql' => $sql, 'params' => $date['params']];
}

function superfunnel_get_last_event_ids_subquery_sql($start_date, $end_date) {
    $table = superfunnel_get_events_table_name();
    $date = superfunnel_build_date_where($start_date, $end_date, 'e');
    $cutoff = superfunnel_get_closed_session_cutoff_mysql();

    $sql = "
        SELECT e.session_id, MAX(e.id) AS last_id
        FROM {$table} e
        WHERE {$date['sql']} AND e.created_at <= %s
        GROUP BY e.session_id
    ";

    return ['sql' => $sql, 'params' => array_merge($date['params'], [$cutoff])];
}

function superfunnel_get_terminal_nonbuyer_sessions_subquery_sql($start_date, $end_date) {
    $table = superfunnel_get_events_table_name();
    $last_ids = superfunnel_get_last_event_ids_subquery_sql($start_date, $end_date);
    $buyers = superfunnel_get_buyers_subquery_sql($start_date, $end_date);

    $sql = "
        SELECT t.session_id, t.path, t.created_at
        FROM {$table} t
        INNER JOIN ({$last_ids['sql']}) last_ids ON last_ids.last_id = t.id
        LEFT JOIN ({$buyers['sql']}) buyers ON buyers.session_id = t.session_id
        WHERE buyers.session_id IS NULL
    ";

    return ['sql' => $sql, 'params' => array_merge($last_ids['params'], $buyers['params'])];
}

function superfunnel_get_page_report_rows($start_date, $end_date, $limit = 500) {
    global $wpdb;

    $limit = max(50, min(5000, (int) $limit));

    $cache_key = superfunnel_cache_key('page_rows_' . $limit, $start_date, $end_date);
    $cached = superfunnel_cache_get($cache_key);
    if ($cached !== null) {
        return $cached;
    }

    $visit_pairs = superfunnel_get_visit_pairs_subquery_sql($start_date, $end_date);
    $buyers = superfunnel_get_buyers_subquery_sql($start_date, $end_date);
    $terminal = superfunnel_get_terminal_nonbuyer_sessions_subquery_sql($start_date, $end_date);

    $sql = "
        SELECT
            vp.path,
            COUNT(DISTINCT vp.session_id) AS visits,
            COUNT(DISTINCT CASE WHEN buyers.session_id IS NOT NULL THEN vp.session_id END) AS buyers,
            COUNT(DISTINCT CASE WHEN term.session_id IS NOT NULL THEN vp.session_id END) AS dropoffs,
            MAX(vp.updated_at) AS updated_at
        FROM ({$visit_pairs['sql']}) vp
        LEFT JOIN ({$buyers['sql']}) buyers ON buyers.session_id = vp.session_id
        LEFT JOIN ({$terminal['sql']}) term ON term.session_id = vp.session_id AND term.path = vp.path
        GROUP BY vp.path
        ORDER BY visits DESC, vp.path ASC
        LIMIT %d
    ";

    $params = array_merge(
        $visit_pairs['params'],
        $buyers['params'],
        $terminal['params'],
        [$limit]
    );

    $rows = $wpdb->get_results($wpdb->prepare($sql, $params));

    superfunnel_cache_set($cache_key, $rows, 5 * MINUTE_IN_SECONDS);

    return $rows;
}

function superfunnel_get_page_report_totals($start_date, $end_date) {
    global $wpdb;

    $cache_key = superfunnel_cache_key('page_totals', $start_date, $end_date);
    $cached = superfunnel_cache_get($cache_key);
    if ($cached !== null) {
        return $cached;
    }

    $visit_pairs = superfunnel_get_visit_pairs_subquery_sql($start_date, $end_date);
    $buyers = superfunnel_get_buyers_subquery_sql($start_date, $end_date);
    $terminal = superfunnel_get_terminal_nonbuyer_sessions_subquery_sql($start_date, $end_date);

    $total_visits_sql = "SELECT COUNT(*) FROM ({$visit_pairs['sql']}) vp";
    $total_paths_sql = "SELECT COUNT(DISTINCT vp.path) FROM ({$visit_pairs['sql']}) vp";
    $total_buyers_sql = "SELECT COUNT(*) FROM ({$buyers['sql']}) buyers";
    $total_dropoffs_sql = "SELECT COUNT(*) FROM ({$terminal['sql']}) term";

    $result = [
        'total_visits'   => (int) $wpdb->get_var($wpdb->prepare($total_visits_sql, $visit_pairs['params'])),
        'total_paths'    => (int) $wpdb->get_var($wpdb->prepare($total_paths_sql, $visit_pairs['params'])),
        'total_buyers'   => (int) $wpdb->get_var($wpdb->prepare($total_buyers_sql, $buyers['params'])),
        'total_dropoffs' => (int) $wpdb->get_var($wpdb->prepare($total_dropoffs_sql, $terminal['params'])),
    ];

    superfunnel_cache_set($cache_key, $result, 5 * MINUTE_IN_SECONDS);

    return $result;
}

function superfunnel_get_unique_users($start_date, $end_date) {
    global $wpdb;

    $cache_key = superfunnel_cache_key('unique_users', $start_date, $end_date);
    $cached = superfunnel_cache_get($cache_key);
    if ($cached !== null) return (int) $cached;

    $table = superfunnel_get_events_table_name();
    $date = superfunnel_build_date_where($start_date, $end_date, 'e');

    $sql = "SELECT COUNT(DISTINCT e.session_id) FROM {$table} e WHERE {$date['sql']}";

    $val = (int) $wpdb->get_var($wpdb->prepare($sql, $date['params']));

    superfunnel_cache_set($cache_key, $val, 5 * MINUTE_IN_SECONDS);

    return $val;
}

function superfunnel_count_sessions_for_condition($start_date, $end_date, $condition_sql, $condition_params, $source = 'visits') {
    global $wpdb;

    if ($source === 'terminal') {
        $subquery = superfunnel_get_terminal_nonbuyer_sessions_subquery_sql($start_date, $end_date);
        $alias = 's';
    } else {
        $table = superfunnel_get_events_table_name();
        $date = superfunnel_build_date_where($start_date, $end_date, 'e');

        $subquery = [
            'sql' => "SELECT DISTINCT e.session_id, e.path FROM {$table} e WHERE {$date['sql']}",
            'params' => $date['params'],
        ];
        $alias = 's';
    }

    $sql = "SELECT COUNT(DISTINCT {$alias}.session_id) FROM ({$subquery['sql']}) {$alias} WHERE {$condition_sql}";
    $params = array_merge($subquery['params'], $condition_params);

    return (int) $wpdb->get_var($wpdb->prepare($sql, $params));
}

function superfunnel_get_funnel_stats($start_date, $end_date) {
    $cache_key = superfunnel_cache_key('funnel_stats', $start_date, $end_date);
    $cached = superfunnel_cache_get($cache_key);
    if ($cached !== null) {
        return $cached;
    }

    $start_visits = superfunnel_get_sql_condition_for_type('start', 's');
    $produkt_visits = superfunnel_get_sql_condition_for_type('produkt', 's');
    $kassa_visits = superfunnel_get_sql_condition_for_type('kassa', 's');

    $ovrigt_visits = [
        'sql' => "s.path != %s AND s.path NOT LIKE %s AND s.path NOT LIKE %s AND s.path NOT LIKE %s",
        'params' => ['/', '%produkt%', '%kassa%', '%order-received%'],
    ];

    $buyers = superfunnel_get_buyers_subquery_sql($start_date, $end_date);
    global $wpdb;
    $buyers_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ({$buyers['sql']}) buyers", $buyers['params']));

    $result = [
        'start_visits'   => superfunnel_count_sessions_for_condition($start_date, $end_date, $start_visits['sql'], $start_visits['params'], 'visits'),
        'produkt_visits' => superfunnel_count_sessions_for_condition($start_date, $end_date, $produkt_visits['sql'], $produkt_visits['params'], 'visits'),
        'kassa_visits'   => superfunnel_count_sessions_for_condition($start_date, $end_date, $kassa_visits['sql'], $kassa_visits['params'], 'visits'),
        'ovrigt_visits'  => superfunnel_count_sessions_for_condition($start_date, $end_date, $ovrigt_visits['sql'], $ovrigt_visits['params'], 'visits'),

        'start_drop'     => superfunnel_count_sessions_for_condition($start_date, $end_date, $start_visits['sql'], $start_visits['params'], 'terminal'),
        'produkt_drop'   => superfunnel_count_sessions_for_condition($start_date, $end_date, $produkt_visits['sql'], $produkt_visits['params'], 'terminal'),
        'kassa_drop'     => superfunnel_count_sessions_for_condition($start_date, $end_date, $kassa_visits['sql'], $kassa_visits['params'], 'terminal'),
        'ovrigt_drop'    => superfunnel_count_sessions_for_condition($start_date, $end_date, $ovrigt_visits['sql'], $ovrigt_visits['params'], 'terminal'),

        'buyers'         => $buyers_count,
    ];

    superfunnel_cache_set($cache_key, $result, 5 * MINUTE_IN_SECONDS);

    return $result;
}

