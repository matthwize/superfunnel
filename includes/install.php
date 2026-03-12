<?php
if (!defined('ABSPATH')) {
    exit;
}

function superfunnel_deactivate() {
    flush_rewrite_rules();
}

function superfunnel_maybe_upgrade() {
    $installed = (string) get_option(SUPERFUNNEL_OPT_VERSION, '');
    if ($installed !== SUPERFUNNEL_VERSION) {
        superfunnel_install();
    }
}

function superfunnel_install() {
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $table_name = superfunnel_get_events_table_name();
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table_name} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        session_id VARCHAR(120) NOT NULL,
        page_token VARCHAR(120) NOT NULL,
        step_number BIGINT UNSIGNED NOT NULL DEFAULT 1,
        path VARCHAR(500) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY page_token_unique (page_token),
        KEY session_idx (session_id),
        KEY path_idx (path(191)),
        KEY created_at_idx (created_at),
        KEY step_idx (session_id, step_number),
        KEY session_created_idx (session_id, created_at)
    ) {$charset_collate};";

    dbDelta($sql);

    // Options (autoload=no)
    $defaults = [
        SUPERFUNNEL_OPT_IGNORE => '',
        SUPERFUNNEL_OPT_PAGE_TYPES => superfunnel_get_default_page_types(),

        SUPERFUNNEL_OPT_COST_SHIPPING => 0,
        SUPERFUNNEL_OPT_COST_PAYMENT => 0,
        SUPERFUNNEL_OPT_COST_PICKPACK => 0,
        SUPERFUNNEL_OPT_COST_RETURNS => 0,
        SUPERFUNNEL_OPT_FIXED_COSTS => [],

        SUPERFUNNEL_OPT_META_APP_ID => '',
        SUPERFUNNEL_OPT_META_APP_SECRET => '',
        SUPERFUNNEL_OPT_META_TOKEN => '',
        SUPERFUNNEL_OPT_META_ACCOUNTS => [],
        SUPERFUNNEL_OPT_META_ACCOUNT => '',

        SUPERFUNNEL_OPT_GOOGLE_CLIENT_ID => '',
        SUPERFUNNEL_OPT_GOOGLE_CLIENT_SECRET => '',
        SUPERFUNNEL_OPT_GOOGLE_CUSTOMER => '',
        SUPERFUNNEL_OPT_GOOGLE_MANAGER => '',
        SUPERFUNNEL_OPT_GOOGLE_DEV_TOKEN => '',
        SUPERFUNNEL_OPT_GOOGLE_TOKEN => '',
        SUPERFUNNEL_OPT_GOOGLE_REFRESH_TOKEN => '',
        SUPERFUNNEL_OPT_GOOGLE_TOKEN_EXPIRES_AT => 0,
    ];

    foreach ($defaults as $key => $value) {
        if (get_option($key, null) === null) {
            add_option($key, $value, '', 'no');
        }
    }

    update_option(SUPERFUNNEL_OPT_VERSION, SUPERFUNNEL_VERSION, false);

    flush_rewrite_rules();
}
