<?php

if (!defined('ABSPATH')) {
    exit;
}

function ial_royalties_register_endpoint()
{
    if (!class_exists('WooCommerce')) {
        return;
    }
    add_rewrite_endpoint('royalties', EP_ROOT | EP_PAGES);
}
add_action('init', 'ial_royalties_register_endpoint');

function ial_royalties_add_menu_item($items)
{
    $current_user_id = get_current_user_id();
    if (!$current_user_id) {
        return $items;
    }

    $rules_query = new WP_Query(array(
        'post_type' => 'ial_user_prod_assoc',
        'post_status' => 'publish',
        'posts_per_page' => 1,
        'fields' => 'ids',
        'meta_query' => array(
            array(
                'key' => 'collaborator_user',
                'value' => $current_user_id,
            ),
        ),
    ));

    if (!$rules_query->have_posts()) {
        return $items;
    }

    $new_items = array();
    $inserted = false;

    foreach ($items as $key => $value) {
        $new_items[$key] = $value;
        if ('orders' === $key) {
            $new_items['royalties'] = __('Royalties', 'ial-royalties');
            $inserted = true;
        }
    }

    if (!$inserted) {
        $new_items['royalties'] = __('Royalties', 'ial-royalties');
    }

    return $new_items;
}
add_filter('woocommerce_account_menu_items', 'ial_royalties_add_menu_item', 20);

function ial_royalties_endpoint_content()
{
    ?>
    <h2><?php esc_html_e('Dashboard Royalties', 'ial-royalties'); ?></h2>
    <p><?php esc_html_e('Aquí puedes ver un resumen y el historial de las royalties que has generado.', 'ial-royalties'); ?>
    </p>
    <?php echo do_shortcode('[ial_collaborator_dashboard]'); ?>
<?php
}
add_action('woocommerce_account_royalties_endpoint', 'ial_royalties_endpoint_content');

function ial_royalties_detect_wc_activation($plugin)
{
    if ('woocommerce/woocommerce.php' === $plugin) {
        set_transient('ial_royalties_flush_needed', true, 60);
    }
}
add_action('activated_plugin', 'ial_royalties_detect_wc_activation');