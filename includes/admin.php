<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', 'superfunnel_admin_menu');
add_action('admin_init', 'superfunnel_handle_admin_actions');
add_action('admin_enqueue_scripts', 'superfunnel_admin_assets');

function superfunnel_admin_assets($hook) {
    if ($hook !== 'toplevel_page_superfunnel') {
        return;
    }

    $css_path = SUPERFUNNEL_PLUGIN_DIR . 'assets/admin.css';
    $css_ver  = file_exists($css_path) ? (string) filemtime($css_path) : SUPERFUNNEL_VERSION;

    wp_enqueue_style(
        'superfunnel-admin',
        SUPERFUNNEL_PLUGIN_URL . 'assets/admin.css',
        [],
        $css_ver
    );
}

function superfunnel_admin_menu() {
    add_menu_page(
        'Superfunnel',
        'Superfunnel',
        'manage_options',
        'superfunnel',
        'superfunnel_admin_page',
        'dashicons-chart-line',
        58
    );
}

function superfunnel_handle_admin_actions() {
    if (!is_admin() || !current_user_can('manage_options')) {
        return;
    }

    if (empty($_GET['page']) || $_GET['page'] !== 'superfunnel') {
        return;
    }

    if (!empty($_GET['sf_refresh']) && $_GET['sf_refresh'] === '1') {
        check_admin_referer('superfunnel_refresh_cache');

        $filters = superfunnel_get_date_filters();
        $prev = superfunnel_previous_period($filters['start_date'], $filters['end_date']);

        $keys = [
            superfunnel_cache_key('page_rows_500', $filters['start_date'], $filters['end_date']),
            superfunnel_cache_key('page_totals', $filters['start_date'], $filters['end_date']),
            superfunnel_cache_key('unique_users', $filters['start_date'], $filters['end_date']),
            superfunnel_cache_key('funnel_stats', $filters['start_date'], $filters['end_date']),
            superfunnel_cache_key('wc_metrics', $filters['start_date'], $filters['end_date']),
            superfunnel_cache_key('meta_stats', $filters['start_date'], $filters['end_date']),
            superfunnel_cache_key('google_stats', $filters['start_date'], $filters['end_date']),

            superfunnel_cache_key('page_rows_500', $prev['start_date'], $prev['end_date']),
            superfunnel_cache_key('page_totals', $prev['start_date'], $prev['end_date']),
            superfunnel_cache_key('unique_users', $prev['start_date'], $prev['end_date']),
            superfunnel_cache_key('funnel_stats', $prev['start_date'], $prev['end_date']),
            superfunnel_cache_key('wc_metrics', $prev['start_date'], $prev['end_date']),
            superfunnel_cache_key('meta_stats', $prev['start_date'], $prev['end_date']),
            superfunnel_cache_key('google_stats', $prev['start_date'], $prev['end_date']),
        ];

        superfunnel_cache_delete_keys(array_unique($keys));

        wp_safe_redirect(admin_url('admin.php?page=superfunnel&refreshed=1'));
        exit;
    }

    if (isset($_POST['superfunnel_ignore_urls'])) {
        check_admin_referer('superfunnel_save_ignore');

        update_option(
            SUPERFUNNEL_OPT_IGNORE,
            sanitize_text_field(wp_unslash($_POST['superfunnel_ignore_urls']))
        );

        wp_safe_redirect(admin_url('admin.php?page=superfunnel&saved=1'));
        exit;
    }

    if (isset($_POST['superfunnel_page_type_save'])) {
        check_admin_referer('superfunnel_save_page_types');

        $page_types = [
            'produkt' => sanitize_text_field(wp_unslash($_POST['superfunnel_page_type_produkt'] ?? '')),
            'kassa'   => sanitize_text_field(wp_unslash($_POST['superfunnel_page_type_kassa'] ?? '')),
            'kop'     => sanitize_text_field(wp_unslash($_POST['superfunnel_page_type_kop'] ?? '')),
        ];

        update_option(SUPERFUNNEL_OPT_PAGE_TYPES, $page_types);

        wp_safe_redirect(admin_url('admin.php?page=superfunnel&page_types_saved=1'));
        exit;
    }

    if (isset($_POST['superfunnel_save_costs'])) {
        check_admin_referer('superfunnel_save_costs');

        update_option(SUPERFUNNEL_OPT_COST_SHIPPING, floatval($_POST['superfunnel_cost_shipping'] ?? 0));
        update_option(SUPERFUNNEL_OPT_COST_PAYMENT, floatval($_POST['superfunnel_cost_payment'] ?? 0));
        update_option(SUPERFUNNEL_OPT_COST_PICKPACK, floatval($_POST['superfunnel_cost_pickpack'] ?? 0));
        update_option(SUPERFUNNEL_OPT_COST_RETURNS, floatval($_POST['superfunnel_cost_returns'] ?? 0));

        $names  = $_POST['superfunnel_fixed_name'] ?? [];
        $amount = $_POST['superfunnel_fixed_amount'] ?? [];

        $fixed_costs = [];

        foreach ($names as $i => $name) {
            $name = sanitize_text_field(wp_unslash($name));
            $value = floatval($amount[$i] ?? 0);

            if ($name !== '' && $value > 0) {
                $fixed_costs[] = ['name' => $name, 'amount' => $value];
            }
        }

        update_option(SUPERFUNNEL_OPT_FIXED_COSTS, $fixed_costs);

        wp_safe_redirect(admin_url('admin.php?page=superfunnel&costs_saved=1'));
        exit;
    }

    if (isset($_POST['superfunnel_save_integrations'])) {
        check_admin_referer('superfunnel_save_integrations');

        // Meta creds
        if (!defined('SUPERFUNNEL_META_APP_ID')) {
            update_option(SUPERFUNNEL_OPT_META_APP_ID, sanitize_text_field(wp_unslash($_POST['superfunnel_meta_app_id'] ?? '')), false);
        }
        if (!defined('SUPERFUNNEL_META_APP_SECRET')) {
            update_option(SUPERFUNNEL_OPT_META_APP_SECRET, sanitize_text_field(wp_unslash($_POST['superfunnel_meta_app_secret'] ?? '')), false);
        }

        // Google creds
        if (!defined('SUPERFUNNEL_GOOGLE_CLIENT_ID')) {
            update_option(SUPERFUNNEL_OPT_GOOGLE_CLIENT_ID, sanitize_text_field(wp_unslash($_POST['superfunnel_google_client_id'] ?? '')), false);
        }
        if (!defined('SUPERFUNNEL_GOOGLE_CLIENT_SECRET')) {
            update_option(SUPERFUNNEL_OPT_GOOGLE_CLIENT_SECRET, sanitize_text_field(wp_unslash($_POST['superfunnel_google_client_secret'] ?? '')), false);
        }
        if (!defined('SUPERFUNNEL_GOOGLE_DEV_TOKEN')) {
            update_option(SUPERFUNNEL_OPT_GOOGLE_DEV_TOKEN, sanitize_text_field(wp_unslash($_POST['superfunnel_google_dev_token'] ?? '')), false);
        }

        update_option(SUPERFUNNEL_OPT_GOOGLE_CUSTOMER, sanitize_text_field(wp_unslash($_POST['superfunnel_google_customer'] ?? '')), false);
        update_option(SUPERFUNNEL_OPT_GOOGLE_MANAGER, sanitize_text_field(wp_unslash($_POST['superfunnel_google_manager'] ?? '')), false);

        if (isset($_POST['superfunnel_meta_account'])) {
            update_option(SUPERFUNNEL_OPT_META_ACCOUNT, sanitize_text_field(wp_unslash($_POST['superfunnel_meta_account'])), false);
        }

        if (isset($_POST['superfunnel_disconnect_meta'])) {
            update_option(SUPERFUNNEL_OPT_META_TOKEN, '', false);
            update_option(SUPERFUNNEL_OPT_META_ACCOUNTS, [], false);
            update_option(SUPERFUNNEL_OPT_META_ACCOUNT, '', false);
        }

        if (isset($_POST['superfunnel_disconnect_google'])) {
            update_option(SUPERFUNNEL_OPT_GOOGLE_TOKEN, '', false);
            update_option(SUPERFUNNEL_OPT_GOOGLE_REFRESH_TOKEN, '', false);
            update_option(SUPERFUNNEL_OPT_GOOGLE_TOKEN_EXPIRES_AT, 0, false);
        }

        wp_safe_redirect(admin_url('admin.php?page=superfunnel&integrations_saved=1'));
        exit;
    }

    if (!empty($_POST['superfunnel_reset'])) {
        check_admin_referer('superfunnel_reset_action', 'superfunnel_reset_nonce');

        global $wpdb;
        $table = superfunnel_get_events_table_name();
        $wpdb->query("TRUNCATE TABLE {$table}");

        wp_safe_redirect(admin_url('admin.php?page=superfunnel&reset=1'));
        exit;
    }

    if (!empty($_GET['superfunnel_export']) && $_GET['superfunnel_export'] === 'csv') {
        check_admin_referer('superfunnel_export_csv');
        superfunnel_export_csv();
        exit;
    }
}

function superfunnel_export_csv() {
    $filters = superfunnel_get_date_filters();
        $rows = superfunnel_get_page_report_rows($filters['start_date'], $filters['end_date'], 50000);

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=superfunnel-export.csv');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Sida', 'Typ', 'Besök', 'Köpare', 'Konvertering', 'Dropoff', 'Senast']);

    foreach ($rows as $row) {
        $visits = (int) ($row->visits ?? 0);
        $buyers = (int) ($row->buyers ?? 0);
        $dropoffs = (int) ($row->dropoffs ?? 0);

        $conv = $visits > 0 ? round(($buyers / $visits) * 100, 2) : 0;
        $drop = $visits > 0 ? round(($dropoffs / $visits) * 100, 2) : 0;

        fputcsv($output, [
            $row->path,
            superfunnel_get_page_type($row->path),
            $visits,
            $buyers,
            $conv . '%',
            $drop . '%',
            $row->updated_at,
        ]);
    }

    fclose($output);
    exit;
}

function superfunnel_render_channel_section($title, array $finance_now, array $finance_prev, $prev_label, $total_orders_now, $total_orders_prev) {
    $orders_now = (float) ($finance_now['purchases'] ?? 0);
    $orders_prev = (float) ($finance_prev['purchases'] ?? 0);

    $cards = [];

    $cards[] = superfunnel_render_metric_card(
        'Ad spend',
        superfunnel_format_money($finance_now['spend'] ?? 0),
        $title . ' annonser',
        superfunnel_delta_pill($finance_now['spend'] ?? 0, $finance_prev['spend'] ?? 0, $prev_label, false)
    );

    $cards[] = superfunnel_render_metric_card(
        'ROAS',
        superfunnel_format_x($finance_now['roas'] ?? 0, 2),
        'Revenue / Ad spend',
        superfunnel_delta_pill($finance_now['roas'] ?? 0, $finance_prev['roas'] ?? 0, $prev_label, true)
    );

    $cards[] = superfunnel_render_metric_card(
        'Cost per Purchase',
        superfunnel_format_money($finance_now['cpa'] ?? 0),
        $title,
        superfunnel_delta_pill($finance_now['cpa'] ?? 0, $finance_prev['cpa'] ?? 0, $prev_label, false)
    );

    $cards[] = superfunnel_render_metric_card(
        'POAS',
        superfunnel_format_x($finance_now['poas'] ?? 0, 2),
        'Contribution / Ad spend',
        superfunnel_delta_pill($finance_now['poas'] ?? 0, $finance_prev['poas'] ?? 0, $prev_label, true)
    );

    $cards[] = superfunnel_render_metric_card(
        $title . ' orders',
        number_format_i18n($orders_now, 0) . ' / ' . number_format_i18n((float) $total_orders_now, 0),
        $title . ' / Totala ordrar',
        superfunnel_delta_pill($orders_now, $orders_prev, $prev_label, true)
    );

    echo '<h2 class="sf-section-title">' . esc_html($title) . '</h2>';
    echo '<div class="sf-grid">' . implode('', $cards) . '</div>';
}

function superfunnel_admin_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $filters = superfunnel_get_date_filters();
    $prev = superfunnel_previous_period($filters['start_date'], $filters['end_date']);

    $limit = 500;
    $rows = superfunnel_get_page_report_rows($filters['start_date'], $filters['end_date'], 50000);
    $totals = superfunnel_get_page_report_totals($filters['start_date'], $filters['end_date']);
    $totals_prev = superfunnel_get_page_report_totals($prev['start_date'], $prev['end_date']);
    $funnel = superfunnel_get_funnel_stats($filters['start_date'], $filters['end_date']);

    $unique_users = superfunnel_get_unique_users($filters['start_date'], $filters['end_date']);

    $store = superfunnel_get_wc_metrics($filters['start_date'], $filters['end_date']);

    $meta = superfunnel_get_meta_stats_for_range($filters['start_date'], $filters['end_date']);
    $google = superfunnel_get_google_stats_for_range($filters['start_date'], $filters['end_date']);

    $poas_total = superfunnel_get_poas_totals($filters['start_date'], $filters['end_date'], $meta, $google);

    // Previous period
    $unique_users_prev = superfunnel_get_unique_users($prev['start_date'], $prev['end_date']);
    $store_prev = superfunnel_get_wc_metrics($prev['start_date'], $prev['end_date']);

    $meta_prev = superfunnel_get_meta_stats_for_range($prev['start_date'], $prev['end_date']);
    $google_prev = superfunnel_get_google_stats_for_range($prev['start_date'], $prev['end_date']);

    $poas_total_prev = superfunnel_get_poas_totals($prev['start_date'], $prev['end_date'], $meta_prev, $google_prev);

    // Channel finance (POAS per channel baserat på kanalens revenue + Woo ex moms ratio + snittkostnader/order)
    $meta_finance = superfunnel_compute_channel_finance($meta, $store);
    $google_finance = superfunnel_compute_channel_finance($google, $store);

    $meta_finance_prev = superfunnel_compute_channel_finance($meta_prev, $store_prev);
    $google_finance_prev = superfunnel_compute_channel_finance($google_prev, $store_prev);

    $total_orders = (int) ($store['orders_count'] ?? 0);
    $total_orders_prev = (int) ($store_prev['orders_count'] ?? 0);

    $profit = (float) ($poas_total['profit'] ?? 0);
    $profit_prev = (float) ($poas_total_prev['profit'] ?? 0);

    $revenue_total = (float) ($store['revenue_total'] ?? 0);
    $revenue_total_prev = (float) ($store_prev['revenue_total'] ?? 0);

    $marketing_spend = (float) ($meta['spend'] ?? 0) + (float) ($google['spend'] ?? 0);
    $marketing_spend_prev = (float) ($meta_prev['spend'] ?? 0) + (float) ($google_prev['spend'] ?? 0);

    $avg_pages = $unique_users > 0 ? (float) ($totals['total_visits'] ?? 0) / $unique_users : 0.0;
    $avg_pages_prev = $unique_users_prev > 0 ? (float) ($totals_prev['total_visits'] ?? 0) / $unique_users_prev : 0.0;

    $conversion_rate = $unique_users > 0 ? ((float) $total_orders / $unique_users) * 100.0 : 0.0;
    $conversion_rate_prev = $unique_users_prev > 0 ? ((float) $total_orders_prev / $unique_users_prev) * 100.0 : 0.0;

    $avg_order = $total_orders > 0 ? $revenue_total / $total_orders : 0.0;
    $avg_order_prev = $total_orders_prev > 0 ? $revenue_total_prev / $total_orders_prev : 0.0;

    $hero_is_profit = $profit >= 0;
    $hero_bg = $hero_is_profit ? 'sf-hero--profit' : 'sf-hero--loss';

    $refresh_url = wp_nonce_url(
        add_query_arg(['page' => 'superfunnel', 'sf_refresh' => '1'], admin_url('admin.php')),
        'superfunnel_refresh_cache'
    );

    $page_types = superfunnel_get_page_types();

    ?>
    
    <?php

?>
<div class="wrap sf-wrap">

    <div class="sf-toolbar">
        <div>
            <h1 class="sf-title">Superfunnel</h1>
            
           
        </div>
        
    </div>

    <div class="sf-range">
        <div class="sf-presets">
            <?php
            $today = current_time('Y-m-d');
            $yesterday = date('Y-m-d', strtotime('-1 day', strtotime($today)));

            $week_start = date('Y-m-d', strtotime('monday this week'));
            $week_end   = date('Y-m-d', strtotime('sunday this week'));

            $last_week_start = date('Y-m-d', strtotime('monday last week'));
            $last_week_end   = date('Y-m-d', strtotime('sunday last week'));

            $month_start = date('Y-m-01', strtotime($today));
            $month_end   = date('Y-m-t', strtotime($today));

            $last_month_start = date('Y-m-01', strtotime('first day of last month', strtotime($today)));
            $last_month_end   = date('Y-m-t', strtotime('last day of last month', strtotime($today)));

            $year_start = date('Y-01-01', strtotime($today));
            $year_end   = $today;

            $last_year = (int) date('Y', strtotime($today)) - 1;
            $last_year_start = $last_year . '-01-01';
            $last_year_end   = $last_year . '-12-31';

            $presets = [
                ['Idag', $today, $today],
                ['Igår', $yesterday, $yesterday],
                ['Denna vecka', $week_start, $week_end],
                ['Förra veckan', $last_week_start, $last_week_end],
                ['Denna månad', $month_start, $month_end],
                ['Förra månaden', $last_month_start, $last_month_end],
                ['Detta år', $year_start, $year_end],
                ['Förra året', $last_year_start, $last_year_end],
            ];

            foreach ($presets as $p) {
                [$label, $s, $e] = $p;
                $active = ($filters['start_date'] === $s && $filters['end_date'] === $e);
                $url = add_query_arg(['page' => 'superfunnel', 'start_date' => $s, 'end_date' => $e], admin_url('admin.php'));
                $style = $active ? 'background:#1d6fb8;color:#fff;border-color:#1d6fb8;' : '';
                echo '<a href="' . esc_url($url) . '" class="button" style="' . esc_attr($style) . '">' . esc_html($label) . '</a>';
            }
            ?>
        </div>

        <form method="get" class="sf-dateform">
            <input type="hidden" name="page" value="superfunnel">

            <div class="sf-field">
                <label>Från</label>
                <input type="date" name="start_date" value="<?php echo esc_attr($filters['start_date']); ?>">
            </div>

            <div class="sf-field">
                <label>Till</label>
                <input type="date" name="end_date" value="<?php echo esc_attr($filters['end_date']); ?>">
            </div>

            <div class="sf-filter__actions">
                <button type="submit" class="button button-primary">Filtrera</button>
                <a href="<?php echo esc_url(admin_url('admin.php?page=superfunnel')); ?>" class="button">Rensa</a>
            </div>
        </form>
    </div>

    <!-- FLYTTAD HIT: HERO ligger nu under datumen -->
    <div class="sf-hero <?php echo esc_attr($hero_bg); ?>">
        <div class="sf-hero__kpis">
            <div class="sf-hero__kpi">
                <div class="sf-hero__kpi-title">Resultat</div>
                <div class="sf-hero__kpi-value"><?php echo wp_kses_post(superfunnel_format_money($profit)); ?></div>
                <div class="sf-hero__kpi-delta"><?php echo superfunnel_delta_pill($profit, $profit_prev, $prev['label'], true, 'hero'); ?></div>
            </div>
            <div class="sf-hero__kpi">
                <div class="sf-hero__kpi-title">Ordrar</div>
                <div class="sf-hero__kpi-value"><?php echo esc_html(number_format_i18n($total_orders)); ?></div>
                <div class="sf-hero__kpi-delta"><?php echo superfunnel_delta_pill($total_orders, $total_orders_prev, $prev['label'], true, 'hero'); ?></div>
            </div>
            <div class="sf-hero__kpi">
                <div class="sf-hero__kpi-title">Försäljning</div>
                <div class="sf-hero__kpi-value"><?php echo wp_kses_post(superfunnel_format_money($revenue_total)); ?></div>
                <div class="sf-hero__kpi-delta"><?php echo superfunnel_delta_pill($revenue_total, $revenue_total_prev, $prev['label'], true, 'hero'); ?></div>
            </div>
        </div>
    </div>



        <h2 class="sf-section-title">Statistik</h2>
        <div class="sf-grid">
            <?php
            echo superfunnel_render_metric_card(
                'Unika användare',
                number_format_i18n($unique_users),
                'Totala sessioner',
                superfunnel_delta_pill($unique_users, $unique_users_prev, $prev['label'], true)
            );

            echo superfunnel_render_metric_card(
                'Konverteringsgrad',
                superfunnel_format_percent($conversion_rate, 1),
                'Ordrar / unika användare',
                superfunnel_delta_pill($conversion_rate, $conversion_rate_prev, $prev['label'], true)
            );

            echo superfunnel_render_metric_card(
                'Sidor per användare',
                number_format_i18n($avg_pages, 2),
                'Snitt (unika sidbesök / user)',
                superfunnel_delta_pill($avg_pages, $avg_pages_prev, $prev['label'], true)
            );

            echo superfunnel_render_metric_card(
                'Totalt spenderat i marknadsföring',
                superfunnel_format_money($marketing_spend),
                'Meta + Google',
                superfunnel_delta_pill($marketing_spend, $marketing_spend_prev, $prev['label'], false)
            );

            echo superfunnel_render_metric_card(
                'Snittordervärde',
                superfunnel_format_money($avg_order),
                'Per order',
                superfunnel_delta_pill($avg_order, $avg_order_prev, $prev['label'], true)
            );
            ?>
        </div>

        <?php
        superfunnel_render_channel_section('Meta', $meta_finance, $meta_finance_prev, $prev['label'], $total_orders, $total_orders_prev);
        superfunnel_render_channel_section('Google', $google_finance, $google_finance_prev, $prev['label'], $total_orders, $total_orders_prev);
        ?>

        <div class="sf-section">
            <h2>Sidrapport</h2>
            <p class="sf-help">
                Besök = unika sessions som besökt sidan. Köpare = sessions som besökt sidan och senare köpt.
                Dropoff = sessions som slutade på sidan (och aldrig köpte).
            </p>
            <div class="sf-table-wrap">
                <table class="sf-table">
                    <thead>
                        <tr>
                            <th>Sida</th>
                            <th>Konvertering</th>
                            <th>Dropoff</th>
                            <th class="sf-right">Besök</th>
                            <th class="sf-right">Senast</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($rows)) : ?>
                            <?php foreach ($rows as $row) : ?>
                                <?php
                                $visits = (int) ($row->visits ?? 0);
                                $buyers = (int) ($row->buyers ?? 0);
                                $dropoffs = (int) ($row->dropoffs ?? 0);

                                $conv = $visits > 0 ? ($buyers / $visits) * 100 : 0;
                                $drop = $visits > 0 ? ($dropoffs / $visits) * 100 : 0;

                                $type = superfunnel_get_page_type($row->path);
                                ?>
                                <tr>
                                    <td>
                                        <a class="sf-code-link" href="<?php echo esc_url(home_url($row->path)); ?>" target="_blank" rel="noopener noreferrer">
                                            <?php echo superfunnel_render_page_type_badge($type); ?>
                                            <code><?php echo esc_html($row->path); ?></code>
                                        </a>
                                    </td>
                                    <td><?php echo esc_html(number_format_i18n($conv, 1)); ?>%</td>
                                    <td><?php echo esc_html(number_format_i18n($drop, 1)); ?>%</td>
                                    <td class="sf-right"><?php echo esc_html(number_format_i18n($visits)); ?></td>
                                    <td class="sf-right"><?php echo esc_html($row->updated_at); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr><td colspan="5" class="sf-empty">Ingen data för valt intervall ännu.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="sf-export">
                <?php
                $export_url = add_query_arg([
                    'page' => 'superfunnel',
                    'superfunnel_export' => 'csv',
                    'start_date' => $filters['start_date'],
                    'end_date' => $filters['end_date'],
                                    ], admin_url('admin.php'));
                ?>
                <a class="button button-primary" href="<?php echo esc_url(wp_nonce_url($export_url, 'superfunnel_export_csv')); ?>">Exportera CSV</a>
            </div>
        </div>

        <div class="sf-section">
            <h2>Dropoffs</h2>
            <p class="sf-help">Dropoff räknas endast för sessions som aldrig köpte.</p>

            <?php
            $start_drop_pct = ($funnel['start_visits'] ?? 0) > 0 ? (($funnel['start_drop'] ?? 0) / $funnel['start_visits']) * 100 : 0;
            $produkt_drop_pct = ($funnel['produkt_visits'] ?? 0) > 0 ? (($funnel['produkt_drop'] ?? 0) / $funnel['produkt_visits']) * 100 : 0;
            $kassa_drop_pct = ($funnel['kassa_visits'] ?? 0) > 0 ? (($funnel['kassa_drop'] ?? 0) / $funnel['kassa_visits']) * 100 : 0;
            $ovrigt_drop_pct = ($funnel['ovrigt_visits'] ?? 0) > 0 ? (($funnel['ovrigt_drop'] ?? 0) / $funnel['ovrigt_visits']) * 100 : 0;
            ?>

            <div class="sf-grid sf-grid--funnel">
                <?php
                echo superfunnel_render_dropoff_card('Startsida', (int) ($funnel['start_visits'] ?? 0), (int) ($funnel['start_drop'] ?? 0));
                echo superfunnel_render_dropoff_card('Produktsidor', (int) ($funnel['produkt_visits'] ?? 0), (int) ($funnel['produkt_drop'] ?? 0));
                echo superfunnel_render_dropoff_card('Övriga sidor', (int) ($funnel['ovrigt_visits'] ?? 0), (int) ($funnel['ovrigt_drop'] ?? 0));
                echo superfunnel_render_dropoff_card('Kassa', (int) ($funnel['kassa_visits'] ?? 0), (int) ($funnel['kassa_drop'] ?? 0));
                ?>
            </div>

            <div class="sf-note">Fullbordade resor: <strong><?php echo esc_html(number_format_i18n((int) ($funnel['buyers'] ?? 0))); ?></strong></div>
        </div>

        <div class="sf-section">
            <h2>Inställningar</h2>

            <details class="sf-details" open>
                <summary>Ignore URL strings</summary>
                <form method="post" class="sf-form">
                    <?php wp_nonce_field('superfunnel_save_ignore'); ?>
                    <div class="sf-field">
                        <textarea name="superfunnel_ignore_urls"><?php echo esc_textarea(get_option(SUPERFUNNEL_OPT_IGNORE, '')); ?></textarea>
                        <div class="sf-muted">Separera med kommatecken. Ex: <code>swish,failed,wait</code></div>
                    </div>
                    <button type="submit" class="button button-primary">Spara</button>
                </form>
            </details>

            <details class="sf-details">
                <summary>Definiera sidor</summary>
                <form method="post" class="sf-form">
                    <?php wp_nonce_field('superfunnel_save_page_types'); ?>
                    <input type="hidden" name="superfunnel_page_type_save" value="1">

                    <div class="sf-field">
                        <label>Produkt</label>
                        <input type="text" name="superfunnel_page_type_produkt" value="<?php echo esc_attr($page_types['produkt']); ?>">
                    </div>

                    <div class="sf-field">
                        <label>Kassa</label>
                        <input type="text" name="superfunnel_page_type_kassa" value="<?php echo esc_attr($page_types['kassa']); ?>">
                    </div>

                    <div class="sf-field">
                        <label>Köp</label>
                        <input type="text" name="superfunnel_page_type_kop" value="<?php echo esc_attr($page_types['kop']); ?>">
                    </div>

                    <button type="submit" class="button button-primary">Spara</button>
                </form>
            </details>

            <details class="sf-details">
                <summary>Integrations</summary>
                <form method="post" class="sf-form">
                    <?php wp_nonce_field('superfunnel_save_integrations'); ?>
                    <input type="hidden" name="superfunnel_save_integrations" value="1">

                    <div class="sf-split">
                        <div>
                            <h3>Meta</h3>

                            <div class="sf-field">
                                <label>Meta App ID</label>
                                <input type="text" name="superfunnel_meta_app_id" value="<?php echo esc_attr(superfunnel_meta_app_id()); ?>" <?php disabled(defined('SUPERFUNNEL_META_APP_ID')); ?>>
                                <?php if (defined('SUPERFUNNEL_META_APP_ID')): ?>
                                    <div class="sf-muted">Styrs via <code>wp-config.php</code>.</div>
                                <?php endif; ?>
                            </div>

                            <div class="sf-field">
                                <label>Meta App Secret</label>
                                <input type="password" name="superfunnel_meta_app_secret" value="<?php echo esc_attr(superfunnel_meta_app_secret()); ?>" <?php disabled(defined('SUPERFUNNEL_META_APP_SECRET')); ?>>
                                <?php if (defined('SUPERFUNNEL_META_APP_SECRET')): ?>
                                    <div class="sf-muted">Styrs via <code>wp-config.php</code>.</div>
                                <?php endif; ?>
                            </div>

                            <?php
                            $meta_token = (string) get_option(SUPERFUNNEL_OPT_META_TOKEN, '');
                            if (!$meta_token) :
                                $connect_url = superfunnel_get_meta_connect_url();
                                ?>
                                <a class="button button-primary" href="<?php echo esc_url($connect_url); ?>">Connect Meta Ads</a>
                            <?php else : ?>
                                <div class="sf-muted" style="margin:8px 0 0;">✓ Meta connected</div>
                                <button class="button" name="superfunnel_disconnect_meta" value="1">Disconnect Meta</button>
                            <?php endif; ?>

                            <?php
                            $accounts = get_option(SUPERFUNNEL_OPT_META_ACCOUNTS, []);
                            $current_acc = (string) get_option(SUPERFUNNEL_OPT_META_ACCOUNT, '');
                            if (is_array($accounts) && !empty($accounts)) :
                                ?>
                                <div class="sf-field" style="margin-top:12px;">
                                    <label>Select Ad Account</label>
                                    <select name="superfunnel_meta_account">
                                        <?php foreach ($accounts as $acc) :
                                            $id = $acc['account_id'] ?? '';
                                            if (!$id) continue;
                                            ?>
                                            <option value="<?php echo esc_attr($id); ?>" <?php selected($current_acc, $id); ?>><?php echo esc_html('act_' . $id); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div>
                            <h3>Google Ads</h3>

                            <div class="sf-field">
                                <label>Google Client ID</label>
                                <input type="text" name="superfunnel_google_client_id" value="<?php echo esc_attr(superfunnel_google_client_id()); ?>" <?php disabled(defined('SUPERFUNNEL_GOOGLE_CLIENT_ID')); ?>>
                                <?php if (defined('SUPERFUNNEL_GOOGLE_CLIENT_ID')): ?>
                                    <div class="sf-muted">Styrs via <code>wp-config.php</code>.</div>
                                <?php endif; ?>
                            </div>

                            <div class="sf-field">
                                <label>Google Client Secret</label>
                                <input type="password" name="superfunnel_google_client_secret" value="<?php echo esc_attr(superfunnel_google_client_secret()); ?>" <?php disabled(defined('SUPERFUNNEL_GOOGLE_CLIENT_SECRET')); ?>>
                                <?php if (defined('SUPERFUNNEL_GOOGLE_CLIENT_SECRET')): ?>
                                    <div class="sf-muted">Styrs via <code>wp-config.php</code>.</div>
                                <?php endif; ?>
                            </div>

                            <div class="sf-field">
                                <label>Google Developer Token</label>
                                <input type="text" name="superfunnel_google_dev_token" value="<?php echo esc_attr(superfunnel_google_dev_token()); ?>" <?php disabled(defined('SUPERFUNNEL_GOOGLE_DEV_TOKEN')); ?>>
                                <?php if (defined('SUPERFUNNEL_GOOGLE_DEV_TOKEN')): ?>
                                    <div class="sf-muted">Styrs via <code>wp-config.php</code>.</div>
                                <?php endif; ?>
                            </div>

                            <div class="sf-field">
                                <label>Google Customer ID</label>
                                <input type="text" name="superfunnel_google_customer" value="<?php echo esc_attr(get_option(SUPERFUNNEL_OPT_GOOGLE_CUSTOMER, '')); ?>">
                            </div>

                            <div class="sf-field">
                                <label>Google Manager ID</label>
                                <input type="text" name="superfunnel_google_manager" value="<?php echo esc_attr(get_option(SUPERFUNNEL_OPT_GOOGLE_MANAGER, '')); ?>">
                            </div>

                            <?php
                            $google_token = (string) get_option(SUPERFUNNEL_OPT_GOOGLE_TOKEN, '');
                            $google_refresh = (string) get_option(SUPERFUNNEL_OPT_GOOGLE_REFRESH_TOKEN, '');
                            $google_connected = ($google_refresh !== '');

                            // Visa "connected" baserat på refresh token (krävs för stabil drift)
                            if ($google_connected && function_exists('superfunnel_google_refresh_token_if_needed')) {
                                superfunnel_google_refresh_token_if_needed();
                                $google_token = (string) get_option(SUPERFUNNEL_OPT_GOOGLE_TOKEN, '');
                            }

                            if (!$google_connected) :
                                $connect_url = superfunnel_get_google_connect_url();
                                if ($connect_url) : ?>
                                    <a class="button button-primary" href="<?php echo esc_url($connect_url); ?>">Connect Google Ads</a>
                                <?php else : ?>
                                    <div class="sf-muted" style="margin:8px 0 0;">Lägg in Google Client ID/Secret för att kunna koppla.</div>
                                <?php endif; ?>

                                <?php if ($google_token && !$google_refresh) : ?>
                                    <div class="sf-muted" style="margin:8px 0 0;color:#b45309;">
                                        ⚠️ Access token finns, men refresh token saknas. Klicka Connect för att få en refresh token.
                                    </div>
                                <?php endif; ?>
                            <?php else : ?>
                                <div class="sf-muted" style="margin:8px 0 0;">✓ Google connected (refresh token)</div>
                                <button class="button" name="superfunnel_disconnect_google" value="1">Disconnect Google</button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <button type="submit" class="button button-primary" style="margin-top:12px;">Spara integrations</button>
                </form>

                <div class="sf-muted" style="margin-top:12px;">
                    Tips: lägg credentials i <code>wp-config.php</code> istället för DB, t.ex.
                    <code>define('SUPERFUNNEL_META_APP_ID','...');</code>
                </div>
            </details>

            <details class="sf-details">
                <summary>Kostnader</summary>
                <?php
                $shipping = (float) get_option(SUPERFUNNEL_OPT_COST_SHIPPING, 0);
                $payment  = (float) get_option(SUPERFUNNEL_OPT_COST_PAYMENT, 0);
                $pickpack = (float) get_option(SUPERFUNNEL_OPT_COST_PICKPACK, 0);
                $returns  = (float) get_option(SUPERFUNNEL_OPT_COST_RETURNS, 0);
                $fixed = get_option(SUPERFUNNEL_OPT_FIXED_COSTS, []);
                $fixed_per_day = superfunnel_get_fixed_cost_per_day();
                ?>
                <form method="post" class="sf-form">
                    <?php wp_nonce_field('superfunnel_save_costs'); ?>
                    <input type="hidden" name="superfunnel_save_costs" value="1">

                    <div class="sf-cost-grid">
                        <div class="sf-field">
                            <label>Frakt (kr/order)</label>
                            <input type="number" step="0.01" name="superfunnel_cost_shipping" value="<?php echo esc_attr($shipping); ?>">
                        </div>
                        <div class="sf-field">
                            <label>Betalning (kr/order)</label>
                            <input type="number" step="0.01" name="superfunnel_cost_payment" value="<?php echo esc_attr($payment); ?>">
                        </div>
                        <div class="sf-field">
                            <label>Pick & Pack (kr/order)</label>
                            <input type="number" step="0.01" name="superfunnel_cost_pickpack" value="<?php echo esc_attr($pickpack); ?>">
                        </div>
                        <div class="sf-field">
                            <label>Retur (kr/order)</label>
                            <input type="number" step="0.01" name="superfunnel_cost_returns" value="<?php echo esc_attr($returns); ?>">
                        </div>
                    </div>

                    <div class="sf-muted">Rörlig kostnad/order: <strong><?php echo wp_kses_post(superfunnel_format_money($shipping + $payment + $pickpack + $returns)); ?></strong></div>

                    <hr class="sf-hr">

                    <div class="sf-cost-head">
                        <div>
                            <h3 style="margin:0;">Fasta kostnader</h3>
                            <div class="sf-muted">Totalt per dag : <strong><?php echo wp_kses_post(superfunnel_format_money($fixed_per_day)); ?></strong></div>
                        </div>
                        <button type="button" class="button" onclick="sfAddCostRow()">+ Lägg till rad</button>
                    </div>

                    <div class="sf-cost-table-wrap">
                        <table class="sf-cost-table">
                            <thead>
                                <tr><th>Namn</th><th>Kostnad / månad</th><th></th></tr>
                            </thead>
                            <tbody id="sf-fixed-costs">
                                <?php if (is_array($fixed) && !empty($fixed)) : ?>
                                    <?php foreach ($fixed as $row) : ?>
                                        <tr>
                                            <td><input type="text" name="superfunnel_fixed_name[]" value="<?php echo esc_attr($row['name'] ?? ''); ?>"></td>
                                            <td><input type="number" step="0.01" name="superfunnel_fixed_amount[]" value="<?php echo esc_attr($row['amount'] ?? 0); ?>"></td>
                                            <td><button type="button" class="button" onclick="sfRemoveRow(this)">Ta bort</button></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <tr>
                                    <td><input type="text" name="superfunnel_fixed_name[]" placeholder="Ex: Löner"></td>
                                    <td><input type="number" step="0.01" name="superfunnel_fixed_amount[]" placeholder="30000"></td>
                                    <td><button type="button" class="button" onclick="sfRemoveRow(this)">Ta bort</button></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <button type="submit" class="button button-primary" style="margin-top:12px;">Spara kostnader</button>
                </form>

                <script>
                    function sfAddCostRow(){
                        var tbody=document.getElementById('sf-fixed-costs');
                        var tr=document.createElement('tr');
                        tr.innerHTML=
                            '<td><input type="text" name="superfunnel_fixed_name[]" placeholder="Namn"></td>'+
                            '<td><input type="number" step="0.01" name="superfunnel_fixed_amount[]" placeholder="0"></td>'+
                            '<td><button type="button" class="button" onclick="sfRemoveRow(this)">Ta bort</button></td>';
                        tbody.appendChild(tr);
                    }
                    function sfRemoveRow(btn){
                        var tr = btn.closest('tr');
                        if (tr) tr.remove();
                    }
                </script>
            </details>
        </div>

        <div class="sf-section">
            <h2>Data</h2>

            <form method="post" onsubmit="return confirm('Är du säker på att du vill nollställa all Superfunnel-data?');">
                <?php wp_nonce_field('superfunnel_reset_action', 'superfunnel_reset_nonce'); ?>
                <input type="hidden" name="superfunnel_reset" value="1">
                <button type="submit" class="button">Nollställ all data</button>
            </form>

            <?php if (!empty($_GET['refreshed'])) : ?>
                <div class="notice notice-success is-dismissible"><p>Cache rensad.</p></div>
            <?php endif; ?>
            <?php if (!empty($_GET['saved'])) : ?>
                <div class="notice notice-success is-dismissible"><p>Ignore-lista sparad.</p></div>
            <?php endif; ?>
            <?php if (!empty($_GET['page_types_saved'])) : ?>
                <div class="notice notice-success is-dismissible"><p>Siddefinitioner sparade.</p></div>
            <?php endif; ?>
            <?php if (!empty($_GET['costs_saved'])) : ?>
                <div class="notice notice-success is-dismissible"><p>Kostnader sparade.</p></div>
            <?php endif; ?>
            <?php if (!empty($_GET['integrations_saved'])) : ?>
                <div class="notice notice-success is-dismissible"><p>Integrations sparade.</p></div>
            <?php endif; ?>
            <?php if (!empty($_GET['integrations_error'])) : ?>
                <div class="notice notice-error is-dismissible"><p>Integration misslyckades. Kontrollera credentials och försök igen.</p></div>
            <?php endif; ?>
            <?php if (!empty($_GET['reset'])) : ?>
                <div class="notice notice-success is-dismissible"><p>Superfunnel-data återställd.</p></div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}
