<?php
if (!defined('ABSPATH')) {
    exit;
}

function superfunnel_get_variable_cost_per_order() {
    $shipping = (float) get_option(SUPERFUNNEL_OPT_COST_SHIPPING, 0);
    $payment  = (float) get_option(SUPERFUNNEL_OPT_COST_PAYMENT, 0);
    $pickpack = (float) get_option(SUPERFUNNEL_OPT_COST_PICKPACK, 0);
    $returns  = (float) get_option(SUPERFUNNEL_OPT_COST_RETURNS, 0);

    return $shipping + $payment + $pickpack + $returns;
}

function superfunnel_get_fixed_cost_per_day() {
    $fixed = get_option(SUPERFUNNEL_OPT_FIXED_COSTS, []);
    if (!is_array($fixed) || !$fixed) return 0.0;

    $total = 0.0;
    foreach ($fixed as $row) {
        $monthly = (float) ($row['amount'] ?? 0);
        if ($monthly > 0) {
            $total += ($monthly / 30.0);
        }
    }
    return $total;
}

function superfunnel_wc_table_exists($table) {
    global $wpdb;
    $full = $wpdb->prefix . ltrim($table, $wpdb->prefix);
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $full));
    return $exists === $full;
}

function superfunnel_get_wc_metrics($start_date, $end_date) {
    $cache_key = superfunnel_cache_key('wc_metrics', $start_date, $end_date);
    $cached = superfunnel_cache_get($cache_key);
    if ($cached !== null) return $cached;

    if (!class_exists('WooCommerce')) {
        $empty = [
            'orders_count' => 0,
            'revenue_total' => 0.0,
            'tax_total' => 0.0,
            'revenue_ex_vat' => 0.0,
            'product_cost_total' => 0.0,
        ];
        superfunnel_cache_set($cache_key, $empty, 5 * MINUTE_IN_SECONDS);
        return $empty;
    }

    global $wpdb;

    $start_dt = $start_date . ' 00:00:00';
    $end_dt   = $end_date . ' 23:59:59';

    $statuses = ["wc-processing", "wc-completed"];

    $lookup = $wpdb->prefix . 'wc_order_product_lookup';
    $has_lookup = ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $lookup)) === $lookup);

    $stats = $wpdb->prefix . 'wc_order_stats';
    $has_stats = ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $stats)) === $stats);

    $hpos = $wpdb->prefix . 'wc_orders';
    $has_hpos = ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $hpos)) === $hpos);

    $orders_count = 0;
    $revenue_total = 0.0;
    $tax_total = 0.0;
    $revenue_ex_vat = 0.0;
    $product_cost_total = 0.0;

    // Prefer WooCommerce native analytics table for correct net/tax totals.
    $stats_date_col = null;

    if ($has_stats) {
        $cols = $wpdb->get_col("SHOW COLUMNS FROM {$stats}", 0);
        $colset = array_fill_keys($cols, true);

        $order_id_col = isset($colset['order_id']) ? 'order_id' : null;
        $status_col = isset($colset['status']) ? 'status' : null;
        $stats_date_col = isset($colset['date_created_gmt']) ? 'date_created_gmt' : (isset($colset['date_created']) ? 'date_created' : null);

        $total_col = isset($colset['total_sales']) ? 'total_sales' : null;
        $net_col   = isset($colset['net_total']) ? 'net_total' : null;
        $tax_col   = isset($colset['tax_total']) ? 'tax_total' : null;

        if ($order_id_col && $status_col && $stats_date_col && $total_col) {
            $sql = "
                SELECT
                    COUNT(DISTINCT {$order_id_col}) AS orders_count,
                    COALESCE(SUM({$total_col}),0) AS revenue_total" .
                    ($net_col ? ", COALESCE(SUM({$net_col}),0) AS revenue_ex_vat" : "") .
                    ($tax_col ? ", COALESCE(SUM({$tax_col}),0) AS tax_total" : "") . "
                FROM {$stats}
                WHERE {$status_col} IN ('" . implode("','", array_map('esc_sql', $statuses)) . "')
                  AND {$stats_date_col} >= %s AND {$stats_date_col} <= %s
            ";

            $row = $wpdb->get_row($wpdb->prepare($sql, $start_dt, $end_dt));

            $orders_count = (int) ($row->orders_count ?? 0);
            $revenue_total = (float) ($row->revenue_total ?? 0);

            if ($net_col) {
                $revenue_ex_vat = (float) ($row->revenue_ex_vat ?? 0);
            }

            if ($tax_col) {
                $tax_total = (float) ($row->tax_total ?? 0);
            }

            if (!$net_col) {
                $revenue_ex_vat = max(0.0, $revenue_total - $tax_total);
            }

            if (!$tax_col) {
                $tax_total = max(0.0, $revenue_total - $revenue_ex_vat);
            }
        }
    }

    // Fallback to orders table / legacy posts if analytics table not present or empty.
    if ($orders_count <= 0 && ($has_hpos || isset($wpdb->posts))) {
        if ($has_hpos) {
            $cols = $wpdb->get_col("SHOW COLUMNS FROM {$hpos}", 0);
            $colset = array_fill_keys($cols, true);

            $tax_col = null;
            foreach (['tax_amount', 'total_tax_amount', 'tax_total'] as $c) {
                if (isset($colset[$c])) {
                    $tax_col = $c;
                    break;
                }
            }

            $tax_expr = $tax_col ? "COALESCE(SUM({$tax_col}),0)" : "0";

            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT
                    COUNT(id) AS orders_count,
                    COALESCE(SUM(total_amount),0) AS revenue_total,
                    {$tax_expr} AS tax_total
                 FROM {$hpos}
                 WHERE status IN ('wc-processing','wc-completed')
                   AND date_created_gmt >= %s AND date_created_gmt <= %s",
                $start_dt,
                $end_dt
            ));

            $orders_count = (int) ($row->orders_count ?? 0);
            $revenue_total = (float) ($row->revenue_total ?? 0);
            $tax_total = (float) ($row->tax_total ?? 0);
            $revenue_ex_vat = max(0.0, $revenue_total - $tax_total);
        } else {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT
                    COUNT(p.ID) AS orders_count,
                    COALESCE(SUM(CASE WHEN pm_total.meta_value IS NULL THEN 0 ELSE pm_total.meta_value END),0) AS revenue_total,
                    COALESCE(SUM(
                        (CASE WHEN pm_tax.meta_value IS NULL THEN 0 ELSE pm_tax.meta_value END) +
                        (CASE WHEN pm_shiptax.meta_value IS NULL THEN 0 ELSE pm_shiptax.meta_value END)
                    ),0) AS tax_total
                 FROM {$wpdb->posts} p
                 LEFT JOIN {$wpdb->postmeta} pm_total ON pm_total.post_id=p.ID AND pm_total.meta_key='_order_total'
                 LEFT JOIN {$wpdb->postmeta} pm_tax ON pm_tax.post_id=p.ID AND pm_tax.meta_key='_order_tax'
                 LEFT JOIN {$wpdb->postmeta} pm_shiptax ON pm_shiptax.post_id=p.ID AND pm_shiptax.meta_key='_order_shipping_tax'
                 WHERE p.post_type='shop_order'
                   AND p.post_status IN ('wc-processing','wc-completed')
                   AND p.post_date >= %s AND p.post_date <= %s",
                $start_dt,
                $end_dt
            ));

            $orders_count = (int) ($row->orders_count ?? 0);
            $revenue_total = (float) ($row->revenue_total ?? 0);
            $tax_total = (float) ($row->tax_total ?? 0);
            $revenue_ex_vat = max(0.0, $revenue_total - $tax_total);
        }
    }

    // Product cost totals
    if ($orders_count > 0) {
        $pm = $wpdb->postmeta;

        if ($has_lookup) {
            if ($has_stats && $stats_date_col) {
                $sql_cost = $wpdb->prepare(
                    "SELECT COALESCE(SUM(l.product_qty * COALESCE(pm_var.meta_value, pm_prod.meta_value, 0)),0) AS product_cost_total
                     FROM {$lookup} l
                     INNER JOIN {$stats} s ON s.order_id = l.order_id
                     LEFT JOIN {$pm} pm_var ON pm_var.post_id = l.variation_id AND pm_var.meta_key = '_cost_per_product'
                     LEFT JOIN {$pm} pm_prod ON pm_prod.post_id = l.product_id AND pm_prod.meta_key = '_cost_per_product'
                     WHERE s.status IN ('wc-processing','wc-completed')
                       AND s.{$stats_date_col} >= %s AND s.{$stats_date_col} <= %s",
                    $start_dt,
                    $end_dt
                );
                $product_cost_total = (float) $wpdb->get_var($sql_cost);
            } elseif ($has_hpos) {
                $sql_cost = $wpdb->prepare(
                    "SELECT COALESCE(SUM(l.product_qty * COALESCE(pm_var.meta_value, pm_prod.meta_value, 0)),0) AS product_cost_total
                     FROM {$lookup} l
                     INNER JOIN {$hpos} o ON o.id = l.order_id
                     LEFT JOIN {$pm} pm_var
                       ON pm_var.post_id = l.variation_id
                      AND pm_var.meta_key = '_cost_per_product'
                     LEFT JOIN {$pm} pm_prod
                       ON pm_prod.post_id = l.product_id
                      AND pm_prod.meta_key = '_cost_per_product'
                     WHERE o.status IN ('wc-processing','wc-completed')
                       AND o.date_created_gmt >= %s AND o.date_created_gmt <= %s",
                    $start_dt,
                    $end_dt
                );
                $product_cost_total = (float) $wpdb->get_var($sql_cost);
            } else {
                $sql_cost = $wpdb->prepare(
                    "SELECT COALESCE(SUM(l.product_qty * COALESCE(pm_var.meta_value, pm_prod.meta_value, 0)),0) AS product_cost_total
                     FROM {$lookup} l
                     INNER JOIN {$wpdb->posts} p ON p.ID = l.order_id
                     LEFT JOIN {$pm} pm_var
                       ON pm_var.post_id = l.variation_id
                      AND pm_var.meta_key = '_cost_per_product'
                     LEFT JOIN {$pm} pm_prod
                       ON pm_prod.post_id = l.product_id
                      AND pm_prod.meta_key = '_cost_per_product'
                     WHERE p.post_type='shop_order'
                       AND p.post_status IN ('wc-processing','wc-completed')
                       AND p.post_date >= %s AND p.post_date <= %s",
                    $start_dt,
                    $end_dt
                );
                $product_cost_total = (float) $wpdb->get_var($sql_cost);
            }
        } else {
            // Fallback without lookup table
            $order_items = $wpdb->prefix . 'woocommerce_order_items';
            $itemmeta = $wpdb->prefix . 'woocommerce_order_itemmeta';

            if ($has_hpos) {
                $sql_cost = $wpdb->prepare(
                    "SELECT COALESCE(SUM(CAST(qty.meta_value AS DECIMAL(18,4)) * COALESCE(pm_var.meta_value, pm_prod.meta_value, 0)),0) AS product_cost_total
                     FROM {$order_items} oi
                     INNER JOIN {$hpos} o ON o.id = oi.order_id
                     INNER JOIN {$itemmeta} pid ON pid.order_item_id = oi.order_item_id AND pid.meta_key = '_product_id'
                     LEFT JOIN {$itemmeta} vid ON vid.order_item_id = oi.order_item_id AND vid.meta_key = '_variation_id'
                     INNER JOIN {$itemmeta} qty ON qty.order_item_id = oi.order_item_id AND qty.meta_key = '_qty'
                     LEFT JOIN {$pm} pm_var ON pm_var.post_id = vid.meta_value AND pm_var.meta_key = '_cost_per_product'
                     LEFT JOIN {$pm} pm_prod ON pm_prod.post_id = pid.meta_value AND pm_prod.meta_key = '_cost_per_product'
                     WHERE oi.order_item_type='line_item'
                       AND o.status IN ('wc-processing','wc-completed')
                       AND o.date_created_gmt >= %s AND o.date_created_gmt <= %s",
                    $start_dt,
                    $end_dt
                );
                $product_cost_total = (float) $wpdb->get_var($sql_cost);
            } else {
                $sql_cost = $wpdb->prepare(
                    "SELECT COALESCE(SUM(CAST(qty.meta_value AS DECIMAL(18,4)) * COALESCE(pm_var.meta_value, pm_prod.meta_value, 0)),0) AS product_cost_total
                     FROM {$order_items} oi
                     INNER JOIN {$wpdb->posts} p ON p.ID = oi.order_id
                     INNER JOIN {$itemmeta} pid ON pid.order_item_id = oi.order_item_id AND pid.meta_key = '_product_id'
                     LEFT JOIN {$itemmeta} vid ON vid.order_item_id = oi.order_item_id AND vid.meta_key = '_variation_id'
                     INNER JOIN {$itemmeta} qty ON qty.order_item_id = oi.order_item_id AND qty.meta_key = '_qty'
                     LEFT JOIN {$pm} pm_var ON pm_var.post_id = vid.meta_value AND pm_var.meta_key = '_cost_per_product'
                     LEFT JOIN {$pm} pm_prod ON pm_prod.post_id = pid.meta_value AND pm_prod.meta_key = '_cost_per_product'
                     WHERE oi.order_item_type='line_item'
                       AND p.post_type='shop_order'
                       AND p.post_status IN ('wc-processing','wc-completed')
                       AND p.post_date >= %s AND p.post_date <= %s",
                    $start_dt,
                    $end_dt
                );
                $product_cost_total = (float) $wpdb->get_var($sql_cost);
            }
        }
    }

    $result = [
        'orders_count' => (int) $orders_count,
        'revenue_total' => (float) $revenue_total,
        'tax_total' => (float) $tax_total,
        'revenue_ex_vat' => (float) $revenue_ex_vat,
        'product_cost_total' => (float) $product_cost_total,
    ];

    superfunnel_cache_set($cache_key, $result, 5 * MINUTE_IN_SECONDS);

    return $result;
}


function superfunnel_get_poas_totals($start_date, $end_date, array $meta_stats, array $google_stats) {
    $cache_key = superfunnel_cache_key('poas_total', $start_date, $end_date, md5(wp_json_encode([$meta_stats, $google_stats])));
    $cached = superfunnel_cache_get($cache_key);
    if ($cached !== null) return $cached;

    $store = superfunnel_get_wc_metrics($start_date, $end_date);

    $variable_per_order = superfunnel_get_variable_cost_per_order();
    $variable_total = $variable_per_order * (float) ($store['orders_count'] ?? 0);

    $contribution = (float) ($store['revenue_ex_vat'] ?? 0)
        - (float) ($store['product_cost_total'] ?? 0)
        - (float) $variable_total;

    $ad_spend = (float) ($meta_stats['spend'] ?? 0) + (float) ($google_stats['spend'] ?? 0);

    $poas = $ad_spend > 0 ? ($contribution / $ad_spend) : 0.0;

    $start_ts = strtotime($start_date);
    $end_ts = strtotime($end_date);
    $days = ($start_ts && $end_ts) ? max(1, (int) floor(($end_ts - $start_ts) / DAY_IN_SECONDS) + 1) : 1;

    $fixed_total = superfunnel_get_fixed_cost_per_day() * $days;

    $profit = $contribution - $ad_spend - $fixed_total;

    $result = [
        'store' => $store,
        'variable_cost_total' => $variable_total,
        'contribution' => $contribution,
        'ad_spend' => $ad_spend,
        'poas' => $poas,
        'fixed_total' => $fixed_total,
        'profit' => $profit,
    ];

    superfunnel_cache_set($cache_key, $result, 5 * MINUTE_IN_SECONDS);

    return $result;
}

function superfunnel_compute_channel_finance(array $channel_stats, array $store_metrics) {
    $spend = (float) ($channel_stats['spend'] ?? 0);
    $purchases = (float) ($channel_stats['purchases'] ?? 0);
    $revenue = (float) ($channel_stats['revenue'] ?? 0);

    $roas = $spend > 0 ? ($revenue / $spend) : 0.0;
    $cpa = $purchases > 0 ? ($spend / $purchases) : 0.0;

    $ex_vat_ratio = 1.0;
    $store_revenue = (float) ($store_metrics['revenue_total'] ?? 0);
    $store_ex_vat = (float) ($store_metrics['revenue_ex_vat'] ?? 0);
    if ($store_revenue > 0) {
        $ex_vat_ratio = max(0.0, min(1.0, $store_ex_vat / $store_revenue));
    }

    $revenue_ex_vat = $revenue * $ex_vat_ratio;

    $avg_product_cost_per_order = 0.0;
    $orders_count = (float) ($store_metrics['orders_count'] ?? 0);
    if ($orders_count > 0) {
        $avg_product_cost_per_order = (float) ($store_metrics['product_cost_total'] ?? 0) / $orders_count;
    }

    $variable_per_order = superfunnel_get_variable_cost_per_order();

    $product_cost = $purchases * $avg_product_cost_per_order;
    $variable_cost = $purchases * $variable_per_order;

    $contribution = $revenue_ex_vat - $product_cost - $variable_cost;

    $poas = $spend > 0 ? ($contribution / $spend) : 0.0;

    return [
        'spend' => $spend,
        'purchases' => $purchases,
        'revenue' => $revenue,
        'roas' => $roas,
        'cpa' => $cpa,
        'poas' => $poas,
        'revenue_ex_vat' => $revenue_ex_vat,
        'product_cost' => $product_cost,
        'variable_cost' => $variable_cost,
        'contribution' => $contribution,
    ];
}
