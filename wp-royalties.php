<?php
/**
 * Plugin Name:       WP Royalties
 * Description:       Automates royalty calculation and payouts for WooCommerce product collaborators.
 * Version:           3.3.3
 * Author:            <a href="https://www.ideaalab.com/">IDEAA Lab</a> | <a href="mailto:michaeldide2@gmail.com">Michael Di Desidero</a>
 * License:           GPL v2 or later
 * Text Domain:       ial-royalties
 * Domain Path:       /languages
 * Requires at least: 5.8
 * Requires PHP:      7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

// Auto-update from GitHub
require_once __DIR__ . '/vendor/plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

PucFactory::buildUpdateChecker(
    'https://github.com/ideaalab/wp-royalties/',
    __FILE__,
    'wp-royalties'
);

define('IAL_ROYALTIES_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('IAL_ROYALTIES_VERSION', '3.3.3');

// Main plugin class for IdeaaLab Royalties.
final class IdeaaLab_Royalties
{

    private static $instance = null;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // Private constructor to prevent direct instantiation.
    private function __construct()
    {
        add_action('plugins_loaded', array($this, 'init_plugin'));
        register_activation_hook(__FILE__, array($this, 'handle_activation'));
        add_action('admin_init', array($this, 'check_version_and_flush'));

        add_action('trashed_post', array($this, 'handle_post_status_change'));
        add_action('untrashed_post', array($this, 'handle_post_status_change'));
        add_action('deleted_post', array($this, 'handle_post_status_change'));
        add_action('save_post_ial_royalty_record', array(__CLASS__, 'clear_badge_cache'));

        $plugin_basename = plugin_basename(__FILE__);
        add_filter("plugin_action_links_{$plugin_basename}", array($this, 'add_settings_link'));
    }

    // Initializes plugin components.
    public function init_plugin()
    {
        load_plugin_textdomain('ial-royalties', false, dirname(plugin_basename(__FILE__)) . '/languages/');
        $this->includes();
    }

    // Includes all required plugin files.
    private function includes()
    {
        // 1. CORE
        require_once IAL_ROYALTIES_PLUGIN_PATH . 'includes/core/helpers.php';
        require_once IAL_ROYALTIES_PLUGIN_PATH . 'includes/core/cpt-definitions.php';
        require_once IAL_ROYALTIES_PLUGIN_PATH . 'includes/core/woocommerce-automation.php';
        require_once IAL_ROYALTIES_PLUGIN_PATH . 'includes/core/class-emails.php';
        require_once IAL_ROYALTIES_PLUGIN_PATH . 'includes/frontend/woocommerce-account.php';

        // 2. ADMIN
        if (is_admin()) {
            require_once IAL_ROYALTIES_PLUGIN_PATH . 'includes/admin/meta-boxes.php'; // Native Meta Boxes
            require_once IAL_ROYALTIES_PLUGIN_PATH . 'includes/admin/admin-settings.php';
            require_once IAL_ROYALTIES_PLUGIN_PATH . 'includes/admin/admin-ui.php';
            require_once IAL_ROYALTIES_PLUGIN_PATH . 'includes/admin/admin-columns.php';
            require_once IAL_ROYALTIES_PLUGIN_PATH . 'includes/admin/admin-charts.php';
            require_once IAL_ROYALTIES_PLUGIN_PATH . 'includes/admin/admin-bulk-actions.php';
            require_once IAL_ROYALTIES_PLUGIN_PATH . 'includes/admin/admin-export.php';
        }

        // 3. FRONTEND
        if (!is_admin() || wp_doing_ajax()) {
            require_once IAL_ROYALTIES_PLUGIN_PATH . 'includes/frontend/shortcode-dashboard.php';
            require_once IAL_ROYALTIES_PLUGIN_PATH . 'includes/frontend/frontend-assets.php';
        }
    }

    public function handle_activation()
    {
        set_transient('ial_royalties_flush_needed', true, 60);
        self::clear_badge_cache();
    }

    public function check_version_and_flush()
    {
        $flush_needed = get_transient('ial_royalties_flush_needed');
        $current_version = get_option('ial_royalties_version', '0.0.0');

        if ($flush_needed || version_compare($current_version, IAL_ROYALTIES_VERSION, '!=')) {
            flush_rewrite_rules();
            delete_transient('ial_royalties_flush_needed');
            update_option('ial_royalties_version', IAL_ROYALTIES_VERSION);
        }
    }

    public function add_settings_link($links)
    {
        $settings_url = admin_url('edit.php?post_type=ial_user_prod_assoc&page=ial-royalties-settings');
        $settings_link = sprintf('<a href="%s">%s</a>', esc_url($settings_url), __('Ajustes', 'ial-royalties'));
        array_unshift($links, $settings_link);
        return $links;
    }

    // Checks post type before clearing cache on generic hooks.
    public function handle_post_status_change($post_id)
    {
        if ('ial_royalty_record' === get_post_type($post_id)) {
            self::clear_badge_cache();
        }
    }

    // Static method to clear unpaid count transient.
    public static function clear_badge_cache()
    {
        delete_transient('ial_royalties_unpaid_count');
    }
}

IdeaaLab_Royalties::get_instance();