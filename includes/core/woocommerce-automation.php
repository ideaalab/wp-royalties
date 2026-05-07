<?php

if (!defined('ABSPATH')) {
    exit;
}

// Logging utility for royalties process debugging.
function ial_royalties_log($message)
{
    if (class_exists('WC_Logger') && is_callable('wc_get_logger')) {
        wc_get_logger()->info($message, array('source' => 'ial-royalties-automation'));
    }
}

// Setup automation hooks.
function ial_royalties_automation_setup()
{
    if (!class_exists('WooCommerce')) {
        return;
    }
    add_action('woocommerce_order_status_changed', 'ial_royalties_log_on_status_change', 20, 4);
}
add_action('init', 'ial_royalties_automation_setup');

// Main automation function, triggered on order status change.
function ial_royalties_log_on_status_change($order_id, $old_status, $new_status, $order)
{
    // Get trigger status from settings and normalize it (remove 'wc-' prefix).
    $trigger_status_key = get_option('ial_royalties_trigger_status', 'wc-completed');
    $trigger_status = str_replace('wc-', '', $trigger_status_key);
    $new_status_clean = str_replace('wc-', '', $new_status);

    // Compare new status with plugin trigger status.
    if ($new_status_clean !== $trigger_status) {
        return;
    }

    // Idempotency Check: Prevent processing the same order multiple times.
    if ($order->get_meta('_ial_royalty_processed', true) === 'yes') {
        ial_royalties_log("Order #{$order_id} has already been processed for royalties. Skipping.");
        return;
    }

    // Get all product and variation IDs from the order.
    $order_product_ids = array();
    foreach ($order->get_items() as $item) {
        $order_product_ids[] = $item->get_product_id();
        $order_product_ids[] = $item->get_variation_id();
    }
    $order_product_ids = array_unique(array_filter($order_product_ids));

    if (empty($order_product_ids)) {
        return;
    }

    // Efficient query for all rules that apply to any product in the order.
    $rules_query = new WP_Query(array(
        'post_type' => 'ial_user_prod_assoc',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'meta_query' => array(
            'relation' => 'AND',
            array(
                'key' => 'product',
                'value' => $order_product_ids,
                'compare' => 'IN',
            ),
            array(
                'key' => 'collaborator_user',
                'value' => '',
                'compare' => '!=',
            ),
        ),
    ));

    if (!$rules_query->have_posts()) {
        ial_royalties_log("No matching royalty rules found for products in Order #{$order_id}.");
        // Mark as processed even if no rules, to prevent re-checks.
        $order->update_meta_data('_ial_royalty_processed', 'yes');
        $order->save();
        return;
    }

    $processed_a_record = false;

    // Process each item in the order.
    foreach ($order->get_items() as $item) {
        $product_id = $item->get_product_id();
        $variation_id = $item->get_variation_id();
        $item_ids_to_check = array_unique(array_filter(array($product_id, $variation_id)));

        // Check each rule to see if it applies to this item.
        foreach ($rules_query->posts as $rule_id) {
            $rule_product_ids = get_post_meta($rule_id, 'product', false);
            $rule_product_ids = array_map('intval', $rule_product_ids);

            // Check if current item matches this specific rule.
            $matches = array_intersect($item_ids_to_check, $rule_product_ids);
            if (empty($matches)) {
                continue;
            }

            $quantity = $item->get_quantity();
            $rule_type = get_post_meta($rule_id, 'royalty_type', true);
            $total_royalty = 0.0;
            $unit_royalty = 0.0;

            if ('fixed' === $rule_type || 'fixed_per_unit' === $rule_type) {
                $amount = (float) get_post_meta($rule_id, 'royalty_amount_fixed', true);
                $unit_royalty = $amount;
                $total_royalty = $quantity * $amount;
            } elseif ('percentage' === $rule_type) {
                $percentage = (float) get_post_meta($rule_id, 'royalty_amount_percentage', true);
                $base_type = get_post_meta($rule_id, 'royalty_percentage_base', true);

                if ('net' === $base_type) {
                    $base_amount = $item->get_total();
                } else {
                    $base_amount = $item->get_subtotal() + $item->get_subtotal_tax();
                }

                $total_royalty = ($base_amount * $percentage) / 100;
                $unit_royalty = ($quantity > 0) ? $total_royalty / $quantity : 0;
            }

            // Round to standard currency precision.
            $total_royalty = round($total_royalty, wc_get_price_decimals());

            if ($total_royalty <= 0) {
                continue; // Do not record zero-value royalties.
            }

            // Create Royalty Record
            $collaborator_user = get_post_meta($rule_id, 'collaborator_user', true);
            $recorded_product_id = !empty($variation_id) ? $variation_id : $product_id;

            $record_data = array(
                'post_type' => 'ial_royalty_record',
                'post_status' => 'publish',
                'post_title' => sprintf('Royalty for %s', $item->get_name()),
                'post_author' => $order->get_customer_id() ?: 1,
            );
            $new_record_id = wp_insert_post($record_data);

            if (!is_wp_error($new_record_id)) {
                $sale_date = $order->get_date_completed() ? $order->get_date_completed()->format('Ymd') : current_time('Ymd');

                update_post_meta($new_record_id, 'collaborator_user', $collaborator_user);
                update_post_meta($new_record_id, 'product', $recorded_product_id);
                update_post_meta($new_record_id, 'order', $order_id);
                update_post_meta($new_record_id, 'units', $quantity);
                update_post_meta($new_record_id, 'royalty_per_unit', $unit_royalty);
                update_post_meta($new_record_id, 'royalty_total', $total_royalty);
                update_post_meta($new_record_id, 'sale_date', $sale_date);
                update_post_meta($new_record_id, 'paid', 0);
                update_post_meta($new_record_id, 'record_source', 'automatic');

                ial_royalties_log("Created Royalty Record #{$new_record_id} for Order #{$order_id}. Rule: #{$rule_id}, Amount: {$total_royalty}");

                // Action hook for other components (like emails).
                do_action('ial_royalty_record_created', $new_record_id, $order_id);

                // Clear admin menu badge cache.
                IdeaaLab_Royalties::clear_badge_cache();

                $processed_a_record = true;
            }
        }
    }

    // Finalize. Mark order as processed to prevent re-runs.
    $order->update_meta_data('_ial_royalty_processed', 'yes');
    $order->save();

    ial_royalties_log("Finished processing royalties for Order #{$order_id}.");
}
