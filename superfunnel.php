<?php
/**
 * Plugin Name: Superfunnel
 * Plugin URI: https://example.com
 * Description: Lågvikts funnel-plugin med sessions, kvalificerade besök, dropoff och adminöversikt. Integrerar Meta/Google för ROAS/POAS.
 * Version: 4.2.2
 * Author: Matthias
 * Requires at least: 6.0
 * Requires PHP: 7.4 
 */

if (!defined('ABSPATH')) {
    exit;
}


<<<<<<< HEAD
=======
// --- GitHub updater (failsafe) ---
>>>>>>> 589cc91b89c52104c975630c9573ed71f43ef854
$checkerPath = __DIR__ . '/plugin-update-checker/plugin-update-checker.php';

if (file_exists($checkerPath)) {
    require_once $checkerPath;

    if (class_exists('\YahnisElsts\PluginUpdateChecker\v5\PucFactory')) {
        $updateChecker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
            'https://github.com/matthwize/superfunnel/',
            __FILE__,
            'superfunnel'
        );

        if (defined('SUPERFUNNEL_TOKEN') && SUPERFUNNEL_TOKEN) {
            $updateChecker->setAuthentication(SUPERFUNNEL_TOKEN);
        }
    }
}

define('SUPERFUNNEL_VERSION', '4.2.2');
define('SUPERFUNNEL_PLUGIN_FILE', __FILE__);
define('SUPERFUNNEL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SUPERFUNNEL_PLUGIN_URL', plugin_dir_url(__FILE__));

define('SUPERFUNNEL_EVENTS_TABLE', 'superfunnel_events');

define('SUPERFUNNEL_OPT_VERSION', 'superfunnel_version');
define('SUPERFUNNEL_OPT_IGNORE', 'superfunnel_ignore_urls');
define('SUPERFUNNEL_OPT_PAGE_TYPES', 'superfunnel_page_types');

define('SUPERFUNNEL_OPT_COST_SHIPPING', 'superfunnel_cost_shipping');
define('SUPERFUNNEL_OPT_COST_PAYMENT', 'superfunnel_cost_payment');
define('SUPERFUNNEL_OPT_COST_PICKPACK', 'superfunnel_cost_pickpack');
define('SUPERFUNNEL_OPT_COST_RETURNS', 'superfunnel_cost_returns');
define('SUPERFUNNEL_OPT_FIXED_COSTS', 'superfunnel_fixed_costs');

define('SUPERFUNNEL_OPT_META_APP_ID', 'superfunnel_meta_app_id');
define('SUPERFUNNEL_OPT_META_APP_SECRET', 'superfunnel_meta_app_secret');
define('SUPERFUNNEL_OPT_META_TOKEN', 'superfunnel_meta_token');
define('SUPERFUNNEL_OPT_META_ACCOUNTS', 'superfunnel_meta_accounts');
define('SUPERFUNNEL_OPT_META_ACCOUNT', 'superfunnel_meta_account');

define('SUPERFUNNEL_OPT_GOOGLE_CLIENT_ID', 'superfunnel_google_client_id');
define('SUPERFUNNEL_OPT_GOOGLE_CLIENT_SECRET', 'superfunnel_google_client_secret');
define('SUPERFUNNEL_OPT_GOOGLE_CUSTOMER', 'superfunnel_google_customer');
define('SUPERFUNNEL_OPT_GOOGLE_MANAGER', 'superfunnel_google_manager');
define('SUPERFUNNEL_OPT_GOOGLE_DEV_TOKEN', 'superfunnel_google_dev_token');
define('SUPERFUNNEL_OPT_GOOGLE_TOKEN', 'superfunnel_google_token');
define('SUPERFUNNEL_OPT_GOOGLE_REFRESH_TOKEN', 'superfunnel_google_refresh_token');
define('SUPERFUNNEL_OPT_GOOGLE_TOKEN_EXPIRES_AT', 'superfunnel_google_token_expires_at');

define('SUPERFUNNEL_SESSION_TIMEOUT_MINUTES', 30);
define('SUPERFUNNEL_QUALIFY_DELAY_MS', 1500);

require_once SUPERFUNNEL_PLUGIN_DIR . 'includes/helpers.php';
require_once SUPERFUNNEL_PLUGIN_DIR . 'includes/install.php';
require_once SUPERFUNNEL_PLUGIN_DIR . 'includes/tracking.php';
require_once SUPERFUNNEL_PLUGIN_DIR . 'includes/events.php';
require_once SUPERFUNNEL_PLUGIN_DIR . 'includes/woocommerce.php';
require_once SUPERFUNNEL_PLUGIN_DIR . 'includes/integrations/meta.php';
require_once SUPERFUNNEL_PLUGIN_DIR . 'includes/integrations/google.php';
require_once SUPERFUNNEL_PLUGIN_DIR . 'includes/admin.php';

register_activation_hook(__FILE__, 'superfunnel_install');
register_deactivation_hook(__FILE__, 'superfunnel_deactivate');

add_action('plugins_loaded', 'superfunnel_maybe_upgrade');
