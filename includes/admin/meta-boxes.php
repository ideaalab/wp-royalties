<?php

if (!defined('ABSPATH')) {
    exit;
}

// Registers Meta Boxes
function ial_royalties_add_meta_boxes()
{
    // 1. User-Product Association (Rule)
    add_meta_box(
        'ial_assoc_details',
        __('Royalty Rule Details', 'ial-royalties'),
        'ial_render_assoc_metabox',
        'ial_user_prod_assoc',
        'normal',
        'high'
    );

    // 2. Registered Royalty
    add_meta_box(
        'ial_record_details',
        __('Royalty Record Details', 'ial-royalties'),
        'ial_render_record_metabox',
        'ial_royalty_record',
        'normal',
        'high'
    );

    // Hide Standard Custom Fields for all plugin CPTs
    remove_meta_box('postcustom', 'ial_user_prod_assoc', 'normal');
    remove_meta_box('postcustom', 'ial_royalty_record', 'normal');
}
add_action('add_meta_boxes', 'ial_royalties_add_meta_boxes');

// Render Association Meta Box
function ial_render_assoc_metabox($post)
{
    wp_nonce_field('ial_save_assoc_meta', 'ial_assoc_nonce');

    $product_id = get_post_meta($post->ID, 'product', true);
    $collaborator_user = get_post_meta($post->ID, 'collaborator_user', true);
    $royalty_type = get_post_meta($post->ID, 'royalty_type', true);
    $amount_fixed = get_post_meta($post->ID, 'royalty_amount_fixed', true);
    $amount_pct = get_post_meta($post->ID, 'royalty_amount_percentage', true);
    $pct_base = get_post_meta($post->ID, 'royalty_percentage_base', true);
    $notes = get_post_meta($post->ID, 'notes', true);

    // Get Products (WooCommerce Products)
    $products = get_posts(array('post_type' => 'product', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC', 'post_status' => 'publish'));

    // Get Users
    $users = get_users(array('orderby' => 'display_name'));

    // Currency Symbol
    $currency_symbol = function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '$';

    ?>
    <script>
        jQuery(document).ready(function ($) {
            function toggleRoyaltyInputs() {
                var type = $('#ial_royalty_type').val();
                if (!type) type = 'fixed';

                if (type === 'fixed' || type === 'fixed_per_unit') {
                    $('.ial-fixed-row').show();
                    $('.ial-percentage-row').hide();
                } else {
                    $('.ial-fixed-row').hide();
                    $('.ial-percentage-row').show();
                }
            }
            $('#ial_royalty_type').on('change', toggleRoyaltyInputs);
            toggleRoyaltyInputs();
        });
    </script>

    <table class="form-table">
        <tr>
            <th><label for="ial_assoc_product"><?php esc_html_e('Product', 'ial-royalties'); ?> *</label></th>
            <td>
                <select name="product" id="ial_assoc_product" class="regular-text" required>
                    <option value=""><?php esc_html_e('-- Select Product --', 'ial-royalties'); ?></option>
                    <?php foreach ($products as $p): ?>
                        <option value="<?php echo esc_attr($p->ID); ?>" <?php selected($product_id, $p->ID); ?>>
                            <?php echo esc_html($p->post_title); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="description"><?php esc_html_e('Select the WooCommerce product.', 'ial-royalties'); ?></p>
            </td>
        </tr>

        <tr>
            <th><label for="ial_collaborator_user"><?php esc_html_e('Collaborator', 'ial-royalties'); ?> *</label></th>
            <td>
                <select name="collaborator_user" id="ial_collaborator_user" class="regular-text" required>
                    <option value=""><?php esc_html_e('-- Select User --', 'ial-royalties'); ?></option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?php echo esc_attr($u->ID); ?>" <?php selected($collaborator_user, $u->ID); ?>>
                            <?php echo esc_html($u->display_name); ?> (<?php echo esc_html($u->user_email); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>

        <tr>
            <th><label for="ial_royalty_type"><?php esc_html_e('Royalty Type', 'ial-royalties'); ?></label></th>
            <td>
                <select name="royalty_type" id="ial_royalty_type" class="regular-text">
                    <option value="fixed" <?php selected($royalty_type, 'fixed'); ?>>
                        <?php esc_html_e('Fixed Amount per Sale', 'ial-royalties'); ?>
                    </option>
                    <option value="percentage" <?php selected($royalty_type, 'percentage'); ?>>
                        <?php esc_html_e('Percentage of Sale', 'ial-royalties'); ?>
                    </option>
                </select>
            </td>
        </tr>

        <tr class="ial-fixed-row">
            <th><label for="ial_royalty_amount_fixed"><?php esc_html_e('Fixed Amount', 'ial-royalties'); ?></label></th>
            <td>
                <input type="number" step="0.01" name="royalty_amount_fixed" id="ial_royalty_amount_fixed"
                    value="<?php echo esc_attr($amount_fixed); ?>" class="small-text">
                <span class="description"><?php echo esc_html($currency_symbol); ?></span>
            </td>
        </tr>

        <tr class="ial-percentage-row" style="display:none;">
            <th><label for="ial_royalty_amount_percentage"><?php esc_html_e('Percentage', 'ial-royalties'); ?></label>
            </th>
            <td>
                <input type="number" step="0.1" name="royalty_amount_percentage" id="ial_royalty_amount_percentage"
                    value="<?php echo esc_attr($amount_pct); ?>" class="small-text"> %
            </td>
        </tr>

        <tr class="ial-percentage-row" style="display:none;">
            <th><label for="ial_royalty_percentage_base"><?php esc_html_e('Percentage Base', 'ial-royalties'); ?></label>
            </th>
            <td>
                <select name="royalty_percentage_base" id="ial_royalty_percentage_base" class="regular-text">
                    <option value="price" <?php selected($pct_base, 'price'); ?>>
                        <?php esc_html_e('Product Price (Gross)', 'ial-royalties'); ?>
                    </option>
                    <option value="net" <?php selected($pct_base, 'net'); ?>>
                        <?php esc_html_e('Net Sales (Post-Taxes)', 'ial-royalties'); ?>
                    </option>
                </select>
            </td>
        </tr>

        <tr>
            <th><label for="ial_assoc_notes"><?php esc_html_e('Notes', 'ial-royalties'); ?></label></th>
            <td>
                <textarea name="notes" id="ial_assoc_notes" rows="3"
                    class="large-text"><?php echo esc_textarea($notes); ?></textarea>
            </td>
        </tr>
    </table>
    <?php
}

// Render Royalty Record Meta Box.
function ial_render_record_metabox($post)
{
    wp_nonce_field('ial_save_record_meta', 'ial_record_nonce');

    $assoc_id = get_post_meta($post->ID, 'association', true);
    $collaborator_user = get_post_meta($post->ID, 'collaborator_user', true);
    $product_id = get_post_meta($post->ID, 'product', true);
    $order_id = get_post_meta($post->ID, 'order', true);
    $units = get_post_meta($post->ID, 'units', true);
    $per_unit = get_post_meta($post->ID, 'royalty_per_unit', true);
    $total = get_post_meta($post->ID, 'royalty_total', true);
    $sale_date = get_post_meta($post->ID, 'sale_date', true);
    $paid_date = get_post_meta($post->ID, 'paid_date', true);
    $paid = get_post_meta($post->ID, 'paid', true);
    $source = get_post_meta($post->ID, 'record_source', true);
    $notes = get_post_meta($post->ID, 'notes', true);

    // Format dates for HTML5 input
    if ($sale_date) {
        $dt_sale = DateTime::createFromFormat('Ymd', $sale_date) ?: DateTime::createFromFormat('Y-m-d', $sale_date);
        if ($dt_sale)
            $sale_date = $dt_sale->format('Y-m-d');
    }
    if ($paid_date) {
        $dt_paid = DateTime::createFromFormat('Ymd', $paid_date) ?: DateTime::createFromFormat('Y-m-d', $paid_date);
        if ($dt_paid)
            $paid_date = $dt_paid->format('Y-m-d');
    }

    if ($post->post_status === 'auto-draft') {
        if (!$source)
            $source = 'manual';
    }

    // Build associations list for dropdown
    $associations = get_posts(array(
        'post_type' => 'ial_user_prod_assoc',
        'numberposts' => -1,
        'post_status' => 'publish',
        'orderby' => 'title',
        'order' => 'ASC',
    ));
    $assoc_options = array();
    foreach ($associations as $a) {
        $a_user_id = get_post_meta($a->ID, 'collaborator_user', true);
        $a_prod_id = get_post_meta($a->ID, 'product', true);
        $a_user = $a_user_id ? get_userdata($a_user_id) : null;
        $a_prod = $a_prod_id ? get_the_title($a_prod_id) : '—';
        $a_label = ($a_user ? $a_user->display_name : '—') . ' — ' . $a_prod;
        $assoc_options[] = array('id' => $a->ID, 'label' => $a_label, 'user' => $a_user_id, 'product' => $a_prod_id);
    }

    // For legacy records without stored association, match by user+product
    if (!$assoc_id && $collaborator_user && $product_id) {
        foreach ($assoc_options as $opt) {
            if ($opt['user'] == $collaborator_user && $opt['product'] == $product_id) {
                $assoc_id = $opt['id'];
                break;
            }
        }
    }

    ?>
    <script>
        jQuery(document).ready(function ($) {
            function toggleSourceInputs() {
                var source = $('input[name="record_source"]:checked').val();
                if (source === 'manual') {
                    $('.ial-order-row').hide();
                } else {
                    $('.ial-order-row').show();
                }
            }
            $('input[name="record_source"]').on('change', toggleSourceInputs);
            toggleSourceInputs();
        });
    </script>

    <table class="form-table">
        <tr>
            <th><label for="ial_association"><?php esc_html_e('Association', 'ial-royalties'); ?> *</label></th>
            <td>
                <select name="association" id="ial_association" class="regular-text" required>
                    <option value=""><?php esc_html_e('-- Select Association --', 'ial-royalties'); ?></option>
                    <?php foreach ($assoc_options as $opt): ?>
                        <option value="<?php echo esc_attr($opt['id']); ?>"
                            data-user="<?php echo esc_attr($opt['user']); ?>"
                            data-product="<?php echo esc_attr($opt['product']); ?>"
                            <?php selected($assoc_id, $opt['id']); ?>>
                            <?php echo esc_html($opt['label']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>

        <tr>
            <th><label><?php esc_html_e('Source', 'ial-royalties'); ?></label></th>
            <td>
                <label style="margin-right:15px;">
                    <input type="radio" name="record_source" value="automatic" <?php checked($source, 'automatic'); ?>>
                    <?php esc_html_e('Automatic', 'ial-royalties'); ?>
                </label>
                <label>
                    <input type="radio" name="record_source" value="manual" <?php checked($source, 'manual'); ?>>
                    <?php esc_html_e('Manual', 'ial-royalties'); ?>
                </label>
            </td>
        </tr>

        <tr class="ial-order-row">
            <th><label for="ial_order"><?php esc_html_e('Order ID', 'ial-royalties'); ?></label></th>
            <td>
                <input type="number" name="order" id="ial_order" value="<?php echo esc_attr($order_id); ?>"
                    class="small-text">
                <?php if ($order_id): ?>
                    <a href="<?php echo esc_url(admin_url('post.php?post=' . $order_id . '&action=edit')); ?>" target="_blank"
                        style="margin-left:10px;"><?php esc_html_e('View Order', 'ial-royalties'); ?></a>
                <?php endif; ?>
            </td>
        </tr>

        <tr>
            <th><label for="ial_units"><?php esc_html_e('Units Sold', 'ial-royalties'); ?></label></th>
            <td><input type="number" name="units" id="ial_units" value="<?php echo esc_attr($units); ?>" class="small-text">
            </td>
        </tr>

        <tr>
            <th><label for="ial_royalty_per_unit"><?php esc_html_e('Royalty per Unit', 'ial-royalties'); ?></label></th>
            <td><input type="number" step="0.01" name="royalty_per_unit" id="ial_royalty_per_unit"
                    value="<?php echo esc_attr($per_unit); ?>" class="small-text"></td>
        </tr>

        <tr>
            <th><label for="ial_royalty_total"><?php esc_html_e('Royalty Total', 'ial-royalties'); ?></label></th>
            <td><input type="number" step="0.01" name="royalty_total" id="ial_royalty_total"
                    value="<?php echo esc_attr($total); ?>" class="small-text"></td>
        </tr>

        <tr>
            <th><label for="ial_sale_date"><?php esc_html_e('Sale Date', 'ial-royalties'); ?> *</label></th>
            <td>
                <input type="date" name="sale_date" id="ial_sale_date" value="<?php echo esc_attr($sale_date); ?>"
                    class="regular-text" required>
            </td>
        </tr>

        <tr>
            <th><label for="ial_paid"><?php esc_html_e('Paid', 'ial-royalties'); ?></label></th>
            <td>
                <input type="checkbox" name="paid" id="ial_paid" value="1" <?php checked($paid, 1); ?>>
                <span class="description"><?php esc_html_e('Mark as paid.', 'ial-royalties'); ?></span>
                <?php
                $payment_method = get_post_meta($post->ID, 'payment_method', true);
                $wsw_tx_id = get_post_meta($post->ID, 'wsw_tx_id', true);
                if ($payment_method) {
                    $method_label = ('wallet' === $payment_method)
                        ? __('Wallet', 'ial-royalties')
                        : __('Manual', 'ial-royalties');
                    echo '<br><span class="description" style="margin-top:4px; display:inline-block;">';
                    printf(esc_html__('Method: %s', 'ial-royalties'), '<strong>' . esc_html($method_label) . '</strong>');
                    if ($wsw_tx_id) {
                        printf(' &mdash; WSW TX #%d', intval($wsw_tx_id));
                    }
                    echo '</span>';
                }
                ?>
            </td>
        </tr>

        <tr>
            <th><label for="ial_paid_date"><?php esc_html_e('Paid Date', 'ial-royalties'); ?></label></th>
            <td>
                <input type="date" name="paid_date" id="ial_paid_date" value="<?php echo esc_attr($paid_date); ?>"
                    class="regular-text">
            </td>
        </tr>

        <tr>
            <th><label for="ial_record_notes"><?php esc_html_e('Notas', 'ial-royalties'); ?></label></th>
            <td><textarea name="notes" id="ial_record_notes" rows="3"
                    class="large-text"><?php echo esc_textarea($notes); ?></textarea></td>
        </tr>
    </table>
    <?php
}

// Save Meta Data.
add_action('save_post', 'ial_save_royalties_meta');
function ial_save_royalties_meta($post_id)
{
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
        return;

    if (!current_user_can('edit_post', $post_id))
        return;

    // 1. Save Association
    if (isset($_POST['ial_assoc_nonce']) && wp_verify_nonce($_POST['ial_assoc_nonce'], 'ial_save_assoc_meta')) {
        if (isset($_POST['product']))
            update_post_meta($post_id, 'product', sanitize_text_field($_POST['product']));
        if (isset($_POST['collaborator_user']))
            update_post_meta($post_id, 'collaborator_user', sanitize_text_field($_POST['collaborator_user']));
        if (isset($_POST['royalty_type']))
            update_post_meta($post_id, 'royalty_type', sanitize_text_field($_POST['royalty_type']));
        if (isset($_POST['royalty_amount_fixed']))
            update_post_meta($post_id, 'royalty_amount_fixed', sanitize_text_field($_POST['royalty_amount_fixed']));
        if (isset($_POST['royalty_amount_percentage']))
            update_post_meta($post_id, 'royalty_amount_percentage', sanitize_text_field($_POST['royalty_amount_percentage']));
        if (isset($_POST['royalty_percentage_base']))
            update_post_meta($post_id, 'royalty_percentage_base', sanitize_text_field($_POST['royalty_percentage_base']));
        if (isset($_POST['notes']))
            update_post_meta($post_id, 'notes', sanitize_textarea_field($_POST['notes']));
    }

    // 2. Save Record
    if (isset($_POST['ial_record_nonce']) && wp_verify_nonce($_POST['ial_record_nonce'], 'ial_save_record_meta')) {
        $old_paid = get_post_meta($post_id, 'paid', true);
        $new_paid = isset($_POST['paid']) ? 1 : 0;

        if (isset($_POST['association'])) {
            $assoc_id = absint($_POST['association']);
            update_post_meta($post_id, 'association', $assoc_id);
            $assoc_user = get_post_meta($assoc_id, 'collaborator_user', true);
            $assoc_prod = get_post_meta($assoc_id, 'product', true);
            if ($assoc_user)
                update_post_meta($post_id, 'collaborator_user', $assoc_user);
            if ($assoc_prod)
                update_post_meta($post_id, 'product', $assoc_prod);
        }
        if (isset($_POST['order']))
            update_post_meta($post_id, 'order', sanitize_text_field($_POST['order']));
        if (isset($_POST['units']))
            update_post_meta($post_id, 'units', sanitize_text_field($_POST['units']));
        if (isset($_POST['royalty_per_unit']))
            update_post_meta($post_id, 'royalty_per_unit', sanitize_text_field($_POST['royalty_per_unit']));
        if (isset($_POST['royalty_total']))
            update_post_meta($post_id, 'royalty_total', sanitize_text_field($_POST['royalty_total']));
        if (isset($_POST['sale_date']))
            update_post_meta($post_id, 'sale_date', sanitize_text_field($_POST['sale_date']));
        if (isset($_POST['paid_date']))
            update_post_meta($post_id, 'paid_date', sanitize_text_field($_POST['paid_date']));

        if (isset($_POST['record_source']))
            update_post_meta($post_id, 'record_source', sanitize_text_field($_POST['record_source']));

        update_post_meta($post_id, 'paid', $new_paid);

        // Set payment_method on 0→1 transition when saved from meta-box.
        if (!$old_paid && $new_paid) {
            $existing_method = get_post_meta($post_id, 'payment_method', true);
            if (!$existing_method) {
                update_post_meta($post_id, 'payment_method', 'manual');
            }
        }

        if (isset($_POST['notes']))
            update_post_meta($post_id, 'notes', sanitize_textarea_field($_POST['notes']));

        if (!$old_paid && $new_paid) {
            do_action('ial_royalty_paid_status_changed', $post_id);
        }

        // Auto-generate title from association data
        $user_id = get_post_meta($post_id, 'collaborator_user', true);
        $prod_id = get_post_meta($post_id, 'product', true);
        $user = $user_id ? get_userdata($user_id) : null;
        $prod_name = $prod_id ? get_the_title($prod_id) : '';
        $title = 'Royalty';
        if ($user) $title .= ' — ' . $user->display_name;
        if ($prod_name) $title .= ' — ' . $prod_name;
        remove_action('save_post', 'ial_save_royalties_meta');
        wp_update_post(array('ID' => $post_id, 'post_title' => $title));
        add_action('save_post', 'ial_save_royalties_meta');
    }
}
