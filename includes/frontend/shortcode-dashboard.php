<?php
if (!defined('ABSPATH')) {
    exit;
}

function ial_royalties_dashboard_shortcode()
{
    if (!is_user_logged_in()) {
        return sprintf(
            '<p class="ial-alert ial-alert-warning">%s</p>',
            esc_html__('Debes iniciar sesión para ver tus royalties.', 'ial-royalties')
        );
    }

    $current_user_id = get_current_user_id();
    global $wpdb;

    $sql_total = $wpdb->prepare("
        SELECT SUM(pm.meta_value)
        FROM {$wpdb->postmeta} pm
        INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
        INNER JOIN {$wpdb->postmeta} pm_user ON pm_user.post_id = p.ID
        WHERE p.post_type = 'ial_royalty_record'
        AND p.post_status = 'publish'
        AND pm.meta_key = 'royalty_total'
        AND pm_user.meta_key = 'collaborator_user'
        AND pm_user.meta_value = %d
    ", $current_user_id);
    $total_earned = (float) $wpdb->get_var($sql_total);

    $sql_pending = $wpdb->prepare("
        SELECT SUM(pm.meta_value)
        FROM {$wpdb->postmeta} pm
        INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
        INNER JOIN {$wpdb->postmeta} pm_user ON pm_user.post_id = p.ID
        LEFT JOIN {$wpdb->postmeta} pm_paid ON (pm_paid.post_id = p.ID AND pm_paid.meta_key = 'paid')
        WHERE p.post_type = 'ial_royalty_record'
        AND p.post_status = 'publish'
        AND pm.meta_key = 'royalty_total'
        AND pm_user.meta_key = 'collaborator_user'
        AND pm_user.meta_value = %d
        AND (pm_paid.meta_value IS NULL OR pm_paid.meta_value != '1')
    ", $current_user_id);
    $total_pending = (float) $wpdb->get_var($sql_pending);

    $sql_stats = $wpdb->prepare("
        SELECT
            pm_prod.meta_value as product_id,
            SUM(pm_units.meta_value) as total_units,
            SUM(pm_total.meta_value) as total_amount
        FROM {$wpdb->posts} p
        JOIN {$wpdb->postmeta} pm_user ON (p.ID = pm_user.post_id AND pm_user.meta_key = 'collaborator_user')
        JOIN {$wpdb->postmeta} pm_prod ON (p.ID = pm_prod.post_id AND pm_prod.meta_key = 'product')
        JOIN {$wpdb->postmeta} pm_units ON (p.ID = pm_units.post_id AND pm_units.meta_key = 'units')
        JOIN {$wpdb->postmeta} pm_total ON (p.ID = pm_total.post_id AND pm_total.meta_key = 'royalty_total')
        WHERE p.post_type = 'ial_royalty_record'
        AND p.post_status = 'publish'
        AND pm_user.meta_value = %d
        GROUP BY pm_prod.meta_value
    ", $current_user_id);
    $product_stats_raw = $wpdb->get_results($sql_stats, ARRAY_A);

    $product_stats = array();
    foreach ($product_stats_raw as $row) {
        $product_stats[(int) $row['product_id']] = array(
            'units' => (int) $row['total_units'],
            'amount' => (float) $row['total_amount'],
        );
    }

    // 2. WP_Query for lists
    $rules_query = new WP_Query(array(
        'post_type' => 'ial_user_prod_assoc',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'meta_query' => array(
            array(
                'key' => 'collaborator_user',
                'value' => $current_user_id,
            ),
        ),
    ));

    $paged = max(1, get_query_var('paged'), get_query_var('royalties'));
    if (isset($_GET['pagenum'])) {
        $paged = absint($_GET['pagenum']);
    }

    $records_query = new WP_Query(array(
        'post_type' => 'ial_royalty_record',
        'post_status' => 'publish',
        'posts_per_page' => 20,
        'paged' => $paged,
        'meta_query' => array(
            array(
                'key' => 'collaborator_user',
                'value' => $current_user_id,
            ),
        ),
        'orderby' => array('sale_date' => 'DESC', 'date' => 'DESC'),
    ));

    ob_start();
    ?>
    <div class="ial-dashboard-wrapper">
        <h3><?php esc_html_e('Productos Asociados', 'ial-royalties'); ?></h3>
        <?php if ($rules_query->have_posts()): ?>
            <div class="ial-table-responsive">
                <table class="ial-dashboard-table">
                    <thead>
                        <tr>
                            <th style="width: 40%;"><?php esc_html_e('Producto', 'ial-royalties'); ?></th>
                            <th style="width: 30%;"><?php esc_html_e('Notas del Acuerdo', 'ial-royalties'); ?></th>
                            <th class="ial-text-right"><?php esc_html_e('Total Vendido', 'ial-royalties'); ?></th>
                            <th class="ial-text-right"><?php esc_html_e('Total Acumulado', 'ial-royalties'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($rules_query->have_posts()):
                            $rules_query->the_post();
                            $rule_id = get_the_ID();
                            $p_field = get_post_meta($rule_id, 'product', true);
                            $p_id = is_object($p_field) ? $p_field->ID : (int) $p_field;
                            $p_notes = get_post_meta($rule_id, 'notes', true);

                            $p_units = isset($product_stats[$p_id]) ? $product_stats[$p_id]['units'] : 0;
                            $p_accrued = isset($product_stats[$p_id]) ? $product_stats[$p_id]['amount'] : 0.0;
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html(get_the_title($p_id)); ?></strong></td>
                                <td><?php echo $p_notes ? esc_html($p_notes) : '<span class="ial-muted">—</span>'; ?></td>
                                <td class="ial-text-right"><?php echo esc_html($p_units); ?></td>
                                <td class="ial-text-right"><?php echo wc_price($p_accrued); ?></td>
                            </tr>
                        <?php endwhile;
                        wp_reset_postdata(); ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p><?php esc_html_e('No se encontraron productos asociados.', 'ial-royalties'); ?></p>
        <?php endif; ?>

        <h3 style="margin-top: 40px;"><?php esc_html_e('Historial de Royalties', 'ial-royalties'); ?></h3>
        <?php if ($records_query->have_posts()): ?>
            <div class="ial-table-responsive">
                <table class="ial-dashboard-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Fecha', 'ial-royalties'); ?></th>
                            <th><?php esc_html_e('Producto', 'ial-royalties'); ?></th>
                            <th class="ial-text-center"><?php esc_html_e('Unidades', 'ial-royalties'); ?></th>
                            <th class="ial-text-right"><?php esc_html_e('Importe', 'ial-royalties'); ?></th>
                            <th class="ial-text-center"><?php esc_html_e('Estado', 'ial-royalties'); ?></th>
                            <th><?php esc_html_e('Notas', 'ial-royalties'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($records_query->have_posts()):
                            $records_query->the_post();
                            $rid = get_the_ID();
                            $p_field = get_post_meta($rid, 'product', true);
                            $p_title = __('Producto Desconocido', 'ial-royalties');

                            if (is_object($p_field)) {
                                $p_title = $p_field->post_title;
                            } elseif (is_numeric($p_field) && $p_field > 0) {
                                $p_title = get_the_title($p_field);
                            }

                            $date_raw = get_post_meta($rid, 'sale_date', true);
                            $date_fmt = $date_raw ? date_i18n(get_option('date_format'), strtotime($date_raw)) : '—';
                            $is_paid = get_post_meta($rid, 'paid', true);
                            $r_notes = get_post_meta($rid, 'notes', true);
                            ?>
                            <tr>
                                <td><?php echo esc_html($date_fmt); ?></td>
                                <td><?php echo esc_html($p_title); ?></td>
                                <td class="ial-text-center"><?php echo esc_html(get_post_meta($rid, 'units', true)); ?></td>
                                <td class="ial-text-right"><?php echo wc_price(get_post_meta($rid, 'royalty_total', true)); ?>
                                </td>
                                <td class="ial-text-center">
                                    <?php if ($is_paid): ?>
                                        <span class="ial-badge ial-badge-success"><?php esc_html_e('Pagado', 'ial-royalties'); ?></span>
                                    <?php else: ?>
                                        <span
                                            class="ial-badge ial-badge-warning"><?php esc_html_e('No Pagado', 'ial-royalties'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $r_notes ? esc_html($r_notes) : '<span class="ial-muted">—</span>'; ?></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

            <div class="ial-pagination">
                <?php
                echo paginate_links(array(
                    'base' => str_replace(999999999, '%#%', esc_url(get_pagenum_link(999999999))),
                    'format' => '?paged=%#%',
                    'current' => max(1, $paged),
                    'total' => $records_query->max_num_pages
                ));
                ?>
            </div>
            <?php wp_reset_postdata(); ?>

        <?php else: ?>
            <p><?php esc_html_e('Todavía no hay royalties registradas.', 'ial-royalties'); ?></p>
        <?php endif; ?>

        <div class="ial-summary-box">
            <div class="ial-summary-item">
                <h4><?php esc_html_e('Total Generado', 'ial-royalties'); ?></h4>
                <div class="ial-amount"><?php echo wc_price($total_earned); ?></div>
                <span class="ial-muted"><?php esc_html_e('(Pagado + No Pagado)', 'ial-royalties'); ?></span>
            </div>
            <div class="ial-summary-item ial-pending">
                <h4><?php esc_html_e('Pago Pendiente', 'ial-royalties'); ?></h4>
                <div class="ial-amount"><?php echo wc_price($total_pending); ?></div>
                <span class="ial-muted"><?php esc_html_e('Importe a recibir', 'ial-royalties'); ?></span>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('ial_collaborator_dashboard', 'ial_royalties_dashboard_shortcode');
