<?php

if (!defined('ABSPATH')) {
    exit;
}

function ial_royalties_format_eur_amount($amount)
{
    return number_format((float) $amount, 2, ',', '.') . ' €';
}

function ial_royalties_get_assoc_product_ids($post_id)
{
    $prod_id = (int) get_post_meta($post_id, 'product', true);
    if (!$prod_id) {
        return array();
    }

    $product_ids = array($prod_id);
    $children = get_posts(array(
        'post_parent' => $prod_id,
        'post_type' => 'product_variation',
        'fields' => 'ids',
        'numberposts' => -1,
    ));

    if (!empty($children)) {
        $product_ids = array_merge($product_ids, $children);
    }

    return array_map('intval', array_unique($product_ids));
}

// COLUMNS: User-Product Associations (Rules)
function ial_royalties_assoc_admin_columns($columns)
{
    unset($columns['date']);

    return array(
        'cb' => $columns['cb'],
        'title' => $columns['title'],
        'collaborator_user' => __('Colaborador', 'ial-royalties'),
        'product' => __('Producto', 'ial-royalties'),
        'rule_type' => __('Tipo de Regla', 'ial-royalties'),
        'rule_value' => __('Royalty', 'ial-royalties'),
        'generated_stats' => __('Total Generado', 'ial-royalties'),
        'paid_owed' => __('Paid / Owed', 'ial-royalties'),
        'notes' => __('Notas', 'ial-royalties'),
    );
}
add_filter('manage_ial_user_prod_assoc_posts_columns', 'ial_royalties_assoc_admin_columns');

function ial_royalties_assoc_admin_columns_data($column, $post_id)
{
    switch ($column) {
        case 'collaborator_user':
            $u_id = get_post_meta($post_id, 'collaborator_user', true);
            $u = get_userdata($u_id);
            if ($u) {
                echo '<a href="' . esc_url(get_edit_user_link($u_id)) . '"><strong>' . esc_html($u->display_name) . '</strong></a>';
            } else {
                echo '—';
            }
            break;

        case 'product':
            $pid = get_post_meta($post_id, 'product', true);
            if ($pid) {
                echo '<a href="' . esc_url(get_edit_post_link($pid)) . '">' . esc_html(get_the_title($pid)) . '</a>';
            } else {
                echo '—';
            }
            break;

        case 'rule_type':
            $type = get_post_meta($post_id, 'royalty_type', true);
            echo esc_html(ucfirst(str_replace('_', ' ', $type)));
            break;

        case 'rule_value':
            $t = get_post_meta($post_id, 'royalty_type', true);
            if ($t === 'fixed' || $t === 'fixed_per_unit') {
                echo esc_html(ial_royalties_format_eur_amount(get_post_meta($post_id, 'royalty_amount_fixed', true)));
            } elseif ($t === 'percentage') {
                $percentage = get_post_meta($post_id, 'royalty_amount_percentage', true);
                $base = get_post_meta($post_id, 'royalty_percentage_base', true);
                $base_label = '';

                if ($base === 'price') {
                    $base_label = __('bruto', 'ial-royalties');
                } elseif ($base === 'net') {
                    $base_label = __('neto', 'ial-royalties');
                }

                echo esc_html($percentage) . '%';

                if ($base_label !== '') {
                    echo ' <span style="color:#555;">(' . esc_html($base_label) . ')</span>';
                }
            } else {
                echo '—';
            }
            break;

        case 'generated_stats':
            global $wpdb;
            $collab_id = (int) get_post_meta($post_id, 'collaborator_user', true);
            $product_ids = ial_royalties_get_assoc_product_ids($post_id);

            if ($collab_id && !empty($product_ids)) {
                $ids_placeholder = implode(',', $product_ids);

                $sql = "SELECT SUM(mt1.meta_value) as total_units, SUM(mt2.meta_value) as total_amount
                    FROM {$wpdb->posts} p
                    INNER JOIN {$wpdb->postmeta} mt1 ON (p.ID = mt1.post_id AND mt1.meta_key = 'units')
                    INNER JOIN {$wpdb->postmeta} mt2 ON (p.ID = mt2.post_id AND mt2.meta_key = 'royalty_total')
                    INNER JOIN {$wpdb->postmeta} mt3 ON (p.ID = mt3.post_id AND mt3.meta_key = 'collaborator_user')
                    INNER JOIN {$wpdb->postmeta} mt4 ON (p.ID = mt4.post_id AND mt4.meta_key = 'product')
                    WHERE p.post_type = 'ial_royalty_record' AND p.post_status = 'publish'
                    AND mt3.meta_value = %d AND mt4.meta_value IN ($ids_placeholder)";

                $stats = $wpdb->get_row($wpdb->prepare($sql, $collab_id));

                if ($stats && (float) $stats->total_amount > 0) {
                    printf(
                        '<strong>%d</strong> %s / <strong>%s</strong>',
                        intval($stats->total_units),
                        __('unidades', 'ial-royalties'),
                        wc_price($stats->total_amount)
                    );
                } else {
                    echo '<span class="ial-muted">0</span>';
                }
            } else {
                echo '—';
            }
            break;

        case 'paid_owed':
            global $wpdb;
            $collab_id = (int) get_post_meta($post_id, 'collaborator_user', true);
            $product_ids = ial_royalties_get_assoc_product_ids($post_id);

            if ($collab_id && !empty($product_ids)) {
                $ids_placeholder = implode(',', $product_ids);

                $paid_sql = "SELECT SUM(mt_total.meta_value)
                    FROM {$wpdb->posts} p
                    INNER JOIN {$wpdb->postmeta} mt_total ON (p.ID = mt_total.post_id AND mt_total.meta_key = 'royalty_total')
                    INNER JOIN {$wpdb->postmeta} mt_user ON (p.ID = mt_user.post_id AND mt_user.meta_key = 'collaborator_user')
                    INNER JOIN {$wpdb->postmeta} mt_product ON (p.ID = mt_product.post_id AND mt_product.meta_key = 'product')
                    INNER JOIN {$wpdb->postmeta} mt_paid ON (p.ID = mt_paid.post_id AND mt_paid.meta_key = 'paid')
                    WHERE p.post_type = 'ial_royalty_record' AND p.post_status = 'publish'
                    AND mt_user.meta_value = %d AND mt_product.meta_value IN ($ids_placeholder)
                    AND mt_paid.meta_value = '1'";

                $owed_sql = "SELECT SUM(mt_total.meta_value)
                    FROM {$wpdb->posts} p
                    INNER JOIN {$wpdb->postmeta} mt_total ON (p.ID = mt_total.post_id AND mt_total.meta_key = 'royalty_total')
                    INNER JOIN {$wpdb->postmeta} mt_user ON (p.ID = mt_user.post_id AND mt_user.meta_key = 'collaborator_user')
                    INNER JOIN {$wpdb->postmeta} mt_product ON (p.ID = mt_product.post_id AND mt_product.meta_key = 'product')
                    LEFT JOIN {$wpdb->postmeta} mt_paid ON (p.ID = mt_paid.post_id AND mt_paid.meta_key = 'paid')
                    WHERE p.post_type = 'ial_royalty_record' AND p.post_status = 'publish'
                    AND mt_user.meta_value = %d AND mt_product.meta_value IN ($ids_placeholder)
                    AND (mt_paid.meta_value IS NULL OR mt_paid.meta_value != '1')";

                $paid_total = (float) $wpdb->get_var($wpdb->prepare($paid_sql, $collab_id));
                $owed_total = (float) $wpdb->get_var($wpdb->prepare($owed_sql, $collab_id));

                echo '<div style="line-height:1.45; white-space:nowrap;">';
                echo '<span style="display:block; color:#2e7d32;"><strong>' . esc_html__('Paid:', 'ial-royalties') . '</strong> ' . esc_html(ial_royalties_format_eur_amount($paid_total)) . '</span>';
                echo '<span style="display:block; color:#c62828;"><strong>' . esc_html__('Owed:', 'ial-royalties') . '</strong> ' . esc_html(ial_royalties_format_eur_amount($owed_total)) . '</span>';
                echo '</div>';
            } else {
                echo '—';
            }
            break;

        case 'notes':
            $notes = get_post_meta($post_id, 'notes', true);
            echo $notes ? esc_html(wp_trim_words($notes, 10)) : '—';
            break;
    }
}
add_action('manage_ial_user_prod_assoc_posts_custom_column', 'ial_royalties_assoc_admin_columns_data', 10, 2);

function ial_royalties_remove_assoc_mine_view($views)
{
    if (isset($views['mine'])) {
        unset($views['mine']);
    }

    return $views;
}
add_filter('views_edit-ial_user_prod_assoc', 'ial_royalties_remove_assoc_mine_view');

// COLUMNS: Registered Royalties (Log)
function ial_royalties_record_admin_columns($columns)
{
    unset($columns['title'], $columns['date']);
    return array(
        'cb' => $columns['cb'],
        'title' => __('Detalles', 'ial-royalties'),
        'order' => __('Pedido', 'ial-royalties'),
        'collaborator_user' => __('Colaborador', 'ial-royalties'),
        'product' => __('Producto', 'ial-royalties'),
        'royalty_total' => __('Total', 'ial-royalties'),
        'units' => __('Unidades', 'ial-royalties'),
        'sale_date' => __('Fecha', 'ial-royalties'),
        'paid' => __('Pagado', 'ial-royalties'),
        'notes' => __('Notas', 'ial-royalties'),
    );
}
add_filter('manage_ial_royalty_record_posts_columns', 'ial_royalties_record_admin_columns');

function ial_royalties_record_admin_columns_data($column, $post_id)
{
    switch ($column) {
        case 'order':
            $source = get_post_meta($post_id, 'record_source', true);
            if ($source === 'manual') {
                $old_order = get_post_meta($post_id, 'order', true);
                echo '<span style="color:#888; font-style:italic;">' . esc_html__('Manual', 'ial-royalties');
                if ($old_order) {
                    echo ' (#' . esc_html($old_order) . ')';
                }
                echo '</span>';
            } else {
                $oid = get_post_meta($post_id, 'order', true);
                if ($oid) {
                    printf('<a href="%s">#%s</a>', esc_url(admin_url('post.php?post=' . intval($oid) . '&action=edit')), esc_html($oid));
                } else {
                    echo '—';
                }
            }
            break;

        case 'collaborator_user':
            $u_id = get_post_meta($post_id, 'collaborator_user', true);
            $usr = get_userdata($u_id);
            if ($usr) {
                echo '<a href="' . esc_url(get_edit_user_link($u_id)) . '">' . esc_html($usr->display_name) . '</a>';
            } else {
                echo '—';
            }
            break;

        case 'product':
            $pid = get_post_meta($post_id, 'product', true);
            if (is_object($pid)) {
                $pid = $pid->ID;
            }

            if ($pid) {
                echo '<a href="' . esc_url(get_edit_post_link($pid)) . '">' . esc_html(get_the_title($pid)) . '</a>';
            } else {
                echo '—';
            }
            break;

        case 'royalty_total':
            echo '<strong>' . wc_price(get_post_meta($post_id, 'royalty_total', true)) . '</strong>';
            break;

        case 'units':
            echo esc_html(get_post_meta($post_id, 'units', true));
            break;

        case 'sale_date':
            $date_raw = get_post_meta($post_id, 'sale_date', true);
            if ($date_raw) {
                $date_obj = DateTime::createFromFormat('Y-m-d', $date_raw) ?: DateTime::createFromFormat('Ymd', $date_raw);
                echo $date_obj ? esc_html($date_obj->format(get_option('date_format'))) : esc_html($date_raw);
            } else {
                echo '—';
            }
            break;

        case 'paid':
            $is_paid = get_post_meta($post_id, 'paid', true);
            echo $is_paid ? '<span class="dashicons dashicons-yes" style="color:green"></span>' : '<span class="dashicons dashicons-no" style="color:red"></span>';
            break;

        case 'notes':
            $notes = get_post_meta($post_id, 'notes', true);
            echo $notes ? esc_html(wp_trim_words($notes, 8)) : '—';
            break;
    }
}
add_action('manage_ial_royalty_record_posts_custom_column', 'ial_royalties_record_admin_columns_data', 10, 2);

// FILTERS
function ial_royalties_add_admin_filters($post_type, $which)
{
    if ('ial_royalty_record' !== $post_type || 'top' !== $which) {
        return;
    }

    global $wpdb;

    // Get ONLY Collaborators who have an Association Rule
    $collab_ids = $wpdb->get_col("
        SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm
        INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
        WHERE p.post_type = 'ial_user_prod_assoc' AND p.post_status = 'publish'
        AND pm.meta_key = 'collaborator_user'
    ");

    $users = !empty($collab_ids) ? get_users(array('include' => $collab_ids, 'fields' => array('ID', 'display_name'))) : array();

    $selected_user = isset($_GET['filter_collab']) ? $_GET['filter_collab'] : '';
    echo '<select name="filter_collab">';
    echo '<option value="">' . esc_html__('Todos los Colaboradores', 'ial-royalties') . '</option>';
    foreach ($users as $user) {
        printf('<option value="%s" %s>%s</option>', esc_attr($user->ID), selected($selected_user, $user->ID, false), esc_html($user->display_name));
    }
    echo '</select>';

    // Get ONLY Products that have an Association Rule
    $product_ids = $wpdb->get_col("
        SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm
        INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
        WHERE p.post_type = 'ial_user_prod_assoc' AND p.post_status = 'publish'
        AND pm.meta_key = 'product'
    ");

    $products = !empty($product_ids) ? get_posts(array('post_type' => 'product', 'include' => $product_ids, 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC')) : array();

    $selected_prod = isset($_GET['filter_product']) ? $_GET['filter_product'] : '';
    echo '<select name="filter_product" class="wc-product-search" style="max-width: 200px;">';
    echo '<option value="">' . esc_html__('Todos los Productos', 'ial-royalties') . '</option>';
    foreach ($products as $prod) {
        printf('<option value="%s" %s>%s</option>', esc_attr($prod->ID), selected($selected_prod, $prod->ID, false), esc_html($prod->post_title));
    }
    echo '</select>';

    $selected_status = isset($_GET['filter_paid']) ? $_GET['filter_paid'] : '';
    echo '<select name="filter_paid">';
    echo '<option value="">' . esc_html__('Todos los Estados', 'ial-royalties') . '</option>';
    echo '<option value="yes" ' . selected($selected_status, 'yes', false) . '>' . esc_html__('Pagado', 'ial-royalties') . '</option>';
    echo '<option value="no" ' . selected($selected_status, 'no', false) . '>' . esc_html__('No Pagado', 'ial-royalties') . '</option>';
    echo '</select>';
}
add_action('restrict_manage_posts', 'ial_royalties_add_admin_filters', 10, 2);

function ial_royalties_filter_query($query)
{
    global $pagenow;
    if (is_admin() && $pagenow == 'edit.php' && isset($_GET['post_type']) && $_GET['post_type'] == 'ial_royalty_record' && $query->is_main_query()) {
        $meta_query = array();
        if (!empty($_GET['filter_collab'])) {
            $meta_query[] = array('key' => 'collaborator_user', 'value' => sanitize_text_field($_GET['filter_collab']), 'compare' => '=');
        }
        if (!empty($_GET['filter_product'])) {
            $meta_query[] = array('key' => 'product', 'value' => sanitize_text_field($_GET['filter_product']), 'compare' => '=');
        }
        if (!empty($_GET['filter_paid'])) {
            $is_paid = ($_GET['filter_paid'] === 'yes') ? 1 : 0;
            $meta_query[] = array('key' => 'paid', 'value' => $is_paid, 'compare' => '=');
        }
        if (!empty($meta_query)) {
            $query->set('meta_query', $meta_query);
        }
    }
}
add_filter('parse_query', 'ial_royalties_filter_query');
