<?php

if (!defined('ABSPATH')) {
    exit;
}

// Add Export button to Admin Toolbar.
function ial_royalties_add_export_button($which)
{
    global $typenow;
    if ('ial_royalty_record' === $typenow && 'top' === $which) {

        $export_url = add_query_arg(
            array(
                'ial_action_export_xls' => '1',
                '_wpnonce' => wp_create_nonce('ial_export_xls_action')
            )
        );

        // Preserve current filters in export URL
        foreach (array('m', 'filter_collab', 'filter_product', 'filter_paid') as $param) {
            if (!empty($_GET[$param])) {
                $export_url = add_query_arg($param, $_GET[$param], $export_url);
            }
        }

        ?>
        <div class="alignleft actions">
            <a href="<?php echo esc_url($export_url); ?>" class="button button-primary"
                title="<?php esc_attr_e('Download Excel', 'ial-royalties'); ?>">
                <span class="dashicons dashicons-media-spreadsheet" style="margin-top:3px;"></span>
                <?php esc_html_e('Export Excel', 'ial-royalties'); ?>
            </a>
        </div>
        <?php
    }
}
add_action('manage_posts_extra_tablenav', 'ial_royalties_add_export_button');

// Handle Export logic (HTML to XLS).
function ial_royalties_handle_export()
{

    if (!isset($_GET['ial_action_export_xls']))
        return;

    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have permissions to export this data.', 'ial-royalties'));
    }
    if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'ial_export_xls_action')) {
        wp_die(__('Security check failed.', 'ial-royalties'));
    }

    // Query Arguments
    $args = array(
        'post_type' => 'ial_royalty_record',
        'posts_per_page' => -1,
        'post_status' => 'publish',
        'meta_query' => array(),
    );

    // Apply Filters

    if (!empty($_GET['m'])) {
        $args['m'] = sanitize_text_field($_GET['m']);
    }

    if (!empty($_GET['filter_collab'])) {
        $args['meta_query'][] = array(
            'key' => 'collaborator_user',
            'value' => sanitize_text_field($_GET['filter_collab']),
            'compare' => '='
        );
    }

    if (!empty($_GET['filter_product'])) {
        $args['meta_query'][] = array(
            'key' => 'product',
            'value' => sanitize_text_field($_GET['filter_product']),
            'compare' => '='
        );
    }

    if (!empty($_GET['filter_paid'])) {
        $is_paid = ($_GET['filter_paid'] === 'yes') ? 1 : 0;
        $args['meta_query'][] = array(
            'key' => 'paid',
            'value' => $is_paid,
            'compare' => '='
        );
    }

    $query = new WP_Query($args);
    $filename = 'royalties-report-' . date('Y-m-d-His') . '.xls';

    if (ob_get_length())
        ob_end_clean();

    // Header to force download (Excel detects HTML content but opens it correctly with a warning)
    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=$filename");
    header("Pragma: no-cache");
    header("Expires: 0");
    header("Cache-Control: must-revalidate, post-check=0, pre-check=0");

    ?>
    <html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel"
        xmlns="http://www.w3.org/TR/REC-html40">

    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
        <style>
            body {
                font-family: Arial, sans-serif;
                font-size: 12px;
            }

            table {
                border-collapse: collapse;
                width: 100%;
            }

            th {
                background-color: #444;
                color: #fff;
                border: 1px solid #000;
                padding: 10px;
                font-weight: bold;
                text-align: left;
            }

            td {
                border: 1px solid #ddd;
                padding: 8px;
                vertical-align: top;
                mso-number-format: "\@";
            }

            .num {
                mso-number-format: "\#\,\#\#0\.00";
                text-align: right;
            }

            .date-col {
                mso-number-format: "Short Date";
            }

            .status-paid {
                background-color: #dff0d8;
                color: #3c763d;
                font-weight: bold;
            }

            .status-unpaid {
                background-color: #f2dede;
                color: #a94442;
            }
        </style>
    </head>

    <body>
        <h3><?php printf(esc_html__('Royalties Report - Generated on %s', 'ial-royalties'), date_i18n(get_option('date_format') . ' ' . get_option('time_format'))); ?>
        </h3>
        <table>
            <thead>
                <tr>
                    <th><?php esc_html_e('Order / Source', 'ial-royalties'); ?></th>
                    <th><?php esc_html_e('Sale Date', 'ial-royalties'); ?></th>
                    <th><?php esc_html_e('Collaborator', 'ial-royalties'); ?></th>
                    <th><?php esc_html_e('Product', 'ial-royalties'); ?></th>
                    <th><?php esc_html_e('Units', 'ial-royalties'); ?></th>
                    <th><?php esc_html_e('Total Amount (€)', 'ial-royalties'); ?></th>
                    <th><?php esc_html_e('Status', 'ial-royalties'); ?></th>
                    <th><?php esc_html_e('Notes', 'ial-royalties'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php
                if ($query->have_posts()) {
                    while ($query->have_posts()) {
                        $query->the_post();
                        $p_id = get_the_ID();

                        $source = get_post_meta($p_id, 'record_source', true);
                        $order_id = get_post_meta($p_id, 'order', true);
                        $sale_date = get_post_meta($p_id, 'sale_date', true);
                        $collab_data = get_post_meta($p_id, 'collaborator_user', true);
                        $prod_obj = get_post_meta($p_id, 'product', true);
                        $units = get_post_meta($p_id, 'units', true);
                        $total_raw = get_post_meta($p_id, 'royalty_total', true);
                        $is_paid = get_post_meta($p_id, 'paid', true);
                        $notes = get_post_meta($p_id, 'notes', true);

                        $order_display = ($source === 'manual') ? __('Manual', 'ial-royalties') : '#' . $order_id;

                        $formatted_date = $sale_date;
                        if ($sale_date) {
                            $date_obj = DateTime::createFromFormat('Ymd', $sale_date);
                            if ($date_obj)
                                $formatted_date = $date_obj->format('d/m/Y');
                        }

                        // Extract Collaborator Name safely
                        $collab_name = '—';
                        if (is_numeric($collab_data)) {
                            $u = get_userdata($collab_data);
                            if ($u)
                                $collab_name = $u->display_name;
                        } elseif (is_array($collab_data) && isset($collab_data['display_name'])) {
                            $collab_name = $collab_data['display_name'];
                        } elseif (is_object($collab_data)) {
                            $collab_name = $collab_data->display_name;
                        }

                        // Extract Product Title safely
                        $prod_name = '—';
                        if (is_numeric($prod_obj)) {
                            $prod_name = get_the_title($prod_obj);
                        } elseif (is_object($prod_obj) && isset($prod_obj->post_title)) {
                            $prod_name = $prod_obj->post_title;
                        }

                        $status_label = $is_paid ? __('PAID', 'ial-royalties') : __('UNPAID', 'ial-royalties');
                        $status_class = $is_paid ? 'status-paid' : 'status-unpaid';

                        // Use number_format to ensure decimals appear correctly in Excel
                        $total_formatted = number_format((float) $total_raw, 2, ',', '');

                        echo "<tr>";
                        echo "<td>" . esc_html($order_display) . "</td>";
                        echo "<td class='date-col'>" . esc_html($formatted_date) . "</td>";
                        echo "<td>" . esc_html($collab_name) . "</td>";
                        echo "<td>" . esc_html($prod_name) . "</td>";
                        echo "<td>" . esc_html($units) . "</td>";
                        echo "<td class='num'>" . esc_html($total_formatted) . "</td>";
                        echo "<td class='" . esc_attr($status_class) . "'>" . esc_html($status_label) . "</td>";
                        echo "<td>" . nl2br(esc_html($notes)) . "</td>";
                        echo "</tr>";
                    }
                } else {
                    echo '<tr><td colspan="8">' . esc_html__('No records found.', 'ial-royalties') . '</td></tr>';
                }
                ?>
            </tbody>
        </table>
    </body>

    </html>
    <?php
    exit;
}
add_action('admin_init', 'ial_royalties_handle_export');
