<?php

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_enqueue_scripts', 'ial_royalties_load_chart_assets');
function ial_royalties_load_chart_assets($hook)
{
    $screen = get_current_screen();
    if (!$screen || 'edit.php' !== $hook || 'ial_user_prod_assoc' !== $screen->post_type) {
        return;
    }

    wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', array(), '4.4.2', true);
    wp_add_inline_style('common', ial_royalties_get_dashboard_css());
}

function ial_royalties_get_global_stats()
{
    global $wpdb;

    $sql = "SELECT
        SUM(CASE WHEN paid_meta.meta_value = '1' THEN CAST(total_meta.meta_value AS DECIMAL(20,4)) ELSE 0 END) AS paid_total,
        SUM(CASE WHEN paid_meta.meta_value = '1' THEN 0 ELSE CAST(total_meta.meta_value AS DECIMAL(20,4)) END) AS owed_total,
        SUM(CAST(total_meta.meta_value AS DECIMAL(20,4))) AS generated_total,
        COUNT(*) AS generated_count,
        SUM(CASE WHEN paid_meta.meta_value = '1' THEN 1 ELSE 0 END) AS paid_count,
        SUM(CASE WHEN paid_meta.meta_value = '1' THEN 0 ELSE 1 END) AS owed_count
    FROM {$wpdb->posts} p
    INNER JOIN {$wpdb->postmeta} total_meta ON (p.ID = total_meta.post_id AND total_meta.meta_key = 'royalty_total')
    LEFT JOIN {$wpdb->postmeta} paid_meta ON (p.ID = paid_meta.post_id AND paid_meta.meta_key = 'paid')
    WHERE p.post_type = 'ial_royalty_record' AND p.post_status = 'publish'";

    $row = $wpdb->get_row($sql, ARRAY_A);

    return array(
        'paid_total' => isset($row['paid_total']) ? (float) $row['paid_total'] : 0.0,
        'owed_total' => isset($row['owed_total']) ? (float) $row['owed_total'] : 0.0,
        'generated_total' => isset($row['generated_total']) ? (float) $row['generated_total'] : 0.0,
        'generated_count' => isset($row['generated_count']) ? (int) $row['generated_count'] : 0,
        'paid_count' => isset($row['paid_count']) ? (int) $row['paid_count'] : 0,
        'owed_count' => isset($row['owed_count']) ? (int) $row['owed_count'] : 0,
    );
}

function ial_royalties_get_chart_palette()
{
    return array(
        '#4F9AD6', '#F29D49', '#EC6684', '#F2C14E', '#6BBF59', '#8B6FD8',
        '#38B2AC', '#ED8936', '#718096', '#E53E3E', '#319795', '#805AD5'
    );
}

function ial_royalties_get_product_breakdown()
{
    global $wpdb;

    $sql = "SELECT
        product_meta.meta_value AS product_id,
        SUM(CAST(total_meta.meta_value AS DECIMAL(20,4))) AS generated_total,
        SUM(CASE WHEN paid_meta.meta_value = '1' THEN CAST(total_meta.meta_value AS DECIMAL(20,4)) ELSE 0 END) AS paid_total,
        SUM(CASE WHEN paid_meta.meta_value = '1' THEN 0 ELSE CAST(total_meta.meta_value AS DECIMAL(20,4)) END) AS owed_total
    FROM {$wpdb->posts} p
    INNER JOIN {$wpdb->postmeta} product_meta ON (p.ID = product_meta.post_id AND product_meta.meta_key = 'product')
    INNER JOIN {$wpdb->postmeta} total_meta ON (p.ID = total_meta.post_id AND total_meta.meta_key = 'royalty_total')
    LEFT JOIN {$wpdb->postmeta} paid_meta ON (p.ID = paid_meta.post_id AND paid_meta.meta_key = 'paid')
    WHERE p.post_type = 'ial_royalty_record' AND p.post_status = 'publish'
    GROUP BY product_meta.meta_value
    HAVING generated_total > 0
    ORDER BY generated_total DESC";

    $rows = $wpdb->get_results($sql, ARRAY_A);
    $grouped = array();

    foreach ($rows as $row) {
        $product_id = (int) $row['product_id'];
        if (!$product_id) {
            continue;
        }

        if (get_post_type($product_id) === 'product_variation') {
            $parent_id = wp_get_post_parent_id($product_id);
            if ($parent_id > 0) {
                $product_id = $parent_id;
            }
        }

        if (!isset($grouped[$product_id])) {
            $grouped[$product_id] = array(
                'generated' => 0.0,
                'paid' => 0.0,
                'owed' => 0.0,
            );
        }

        $grouped[$product_id]['generated'] += (float) $row['generated_total'];
        $grouped[$product_id]['paid'] += (float) $row['paid_total'];
        $grouped[$product_id]['owed'] += (float) $row['owed_total'];
    }

    uasort($grouped, function ($a, $b) {
        return $b['generated'] <=> $a['generated'];
    });

    $palette = ial_royalties_get_chart_palette();
    $data = array();
    $index = 0;

    foreach ($grouped as $product_id => $totals) {
        $title = get_the_title($product_id);
        if (!$title) {
            $title = sprintf(__('Product #%d', 'ial-royalties'), $product_id);
        }

        $data[] = array(
            'product_id' => $product_id,
            'label' => $title,
            'generated' => round((float) $totals['generated'], 2),
            'paid' => round((float) $totals['paid'], 2),
            'owed' => round((float) $totals['owed'], 2),
            'color' => $palette[$index % count($palette)],
        );
        $index++;
    }

    return $data;
}

add_action('all_admin_notices', 'ial_royalties_render_dashboard_widget');
function ial_royalties_render_dashboard_widget()
{
    $screen = get_current_screen();
    if (!$screen || 'ial_user_prod_assoc' !== $screen->post_type || 'edit' !== $screen->base) {
        return;
    }

    $stats = ial_royalties_get_global_stats();
    $breakdown = ial_royalties_get_product_breakdown();
    $chart_id = 'ialRoyaltiesChart';
    ?>
    <div class="ial-royalties-dashboard-wrapper">
        <div class="ial-royalties-card ial-royalties-kpis-card">
            <div class="ial-royalties-kpi-stack">
                <div class="ial-royalties-kpi-item is-blue">
                    <span class="ial-royalties-label"><?php echo esc_html(sprintf(__('Total royalties generated (%d)', 'ial-royalties'), (int) $stats['generated_count'])); ?></span>
                    <strong class="ial-royalties-value"><?php echo esc_html(ial_royalties_format_eur_amount($stats['generated_total'])); ?></strong>
                </div>
                <div class="ial-royalties-kpi-item is-green">
                    <span class="ial-royalties-label"><?php echo esc_html(sprintf(__('Total royalties paid (%d)', 'ial-royalties'), (int) $stats['paid_count'])); ?></span>
                    <strong class="ial-royalties-value"><?php echo esc_html(ial_royalties_format_eur_amount($stats['paid_total'])); ?></strong>
                </div>
                <div class="ial-royalties-kpi-item is-red">
                    <span class="ial-royalties-label"><?php echo esc_html(sprintf(__('Total royalties owed (%d)', 'ial-royalties'), (int) $stats['owed_count'])); ?></span>
                    <strong class="ial-royalties-value"><?php echo esc_html(ial_royalties_format_eur_amount($stats['owed_total'])); ?></strong>
                </div>
            </div>
        </div>

        <div class="ial-royalties-card ial-royalties-chart-card">
            <div class="ial-royalties-chart-header">
                <h3><?php esc_html_e('Royalties generated by product', 'ial-royalties'); ?></h3>
            </div>
            <?php if (!empty($breakdown)) : ?>
                <div class="ial-royalties-chart-body">
                    <div class="ial-royalties-chart-wrap">
                        <canvas id="<?php echo esc_attr($chart_id); ?>"></canvas>
                    </div>
                    <div class="ial-royalties-legend">
                        <?php foreach ($breakdown as $item) : ?>
                            <span class="ial-royalties-legend-item">
                                <span class="ial-royalties-legend-swatch" style="background: <?php echo esc_attr($item['color']); ?>;"></span>
                                <span class="ial-royalties-legend-name"><?php echo esc_html($item['label']); ?></span>
                                <strong><?php echo esc_html(ial_royalties_format_eur_amount($item['generated'])); ?></strong>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php else : ?>
                <div class="ial-royalties-empty-chart"><?php esc_html_e('No royalty data yet.', 'ial-royalties'); ?></div>
            <?php endif; ?>
        </div>
    </div>
    <?php

    if (!empty($breakdown)) {
        $chart_data = array(
            'labels' => wp_list_pluck($breakdown, 'label'),
            'generated' => wp_list_pluck($breakdown, 'generated'),
            'paid' => wp_list_pluck($breakdown, 'paid'),
            'owed' => wp_list_pluck($breakdown, 'owed'),
            'colors' => wp_list_pluck($breakdown, 'color'),
            'textGenerated' => __('Generated', 'ial-royalties'),
            'textPaid' => __('Paid', 'ial-royalties'),
            'textOwed' => __('Owed', 'ial-royalties'),
            'currencySuffix' => ' €',
        );
        ?>
        <script>
            window.ialRoyaltiesChartData = <?php echo wp_json_encode($chart_data); ?>;
            document.addEventListener('DOMContentLoaded', function () {
                var canvas = document.getElementById('<?php echo esc_js($chart_id); ?>');
                if (!canvas || typeof Chart === 'undefined' || !window.ialRoyaltiesChartData) {
                    return;
                }

                var formatCurrency = function (value) {
                    return Number(value || 0).toLocaleString('es-ES', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    }) + window.ialRoyaltiesChartData.currencySuffix;
                };

                new Chart(canvas, {
                    type: 'doughnut',
                    data: {
                        labels: window.ialRoyaltiesChartData.labels,
                        datasets: [{
                            data: window.ialRoyaltiesChartData.generated,
                            backgroundColor: window.ialRoyaltiesChartData.colors,
                            borderColor: '#ffffff',
                            borderWidth: 1,
                            hoverOffset: 4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '58%',
                        animation: false,
                        layout: {
                            padding: 0
                        },
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                callbacks: {
                                    title: function (context) {
                                        return context[0] ? context[0].label : '';
                                    },
                                    label: function (context) {
                                        var idx = context.dataIndex;
                                        if (idx === undefined) {
                                            return '';
                                        }
                                        return [
                                            window.ialRoyaltiesChartData.textGenerated + ': ' + formatCurrency(window.ialRoyaltiesChartData.generated[idx]),
                                            window.ialRoyaltiesChartData.textPaid + ': ' + formatCurrency(window.ialRoyaltiesChartData.paid[idx]),
                                            window.ialRoyaltiesChartData.textOwed + ': ' + formatCurrency(window.ialRoyaltiesChartData.owed[idx])
                                        ];
                                    }
                                }
                            }
                        }
                    }
                });
            });
        </script>
        <?php
    }
}

function ial_royalties_get_dashboard_css()
{
    return "
        .ial-royalties-dashboard-wrapper { display:grid; grid-template-columns: 340px 1fr; gap:20px; margin:40px 20px 20px 0; width:auto; box-sizing:border-box; align-items:start; }
        .ial-royalties-card { background:#fff; border:1px solid #c3c4c7; border-radius:4px; box-shadow:0 1px 1px rgba(0,0,0,.04); display:flex; flex-direction:column; }
        .ial-royalties-kpis-card { padding:15px; box-sizing:border-box; min-height:310px; }
        .ial-royalties-kpi-stack { display:grid; grid-template-columns:1fr; gap:10px; flex:1; }
        .ial-royalties-kpi-item { border:1px solid #edf0f2; border-radius:4px; padding:12px 14px; background:#fbfbfc; min-height:74px; display:flex; flex-direction:column; justify-content:center; }
        .ial-royalties-label { font-size:11px; text-transform:uppercase; letter-spacing:.4px; color:#646970; font-weight:600; margin-bottom:6px; }
        .ial-royalties-value { font-size:19px; line-height:1.2; color:#1d2327; font-weight:700; }
        .ial-royalties-kpi-item.is-green .ial-royalties-value { color:#2e7d32; }
        .ial-royalties-kpi-item.is-red .ial-royalties-value { color:#c62828; }
        .ial-royalties-kpi-item.is-blue .ial-royalties-value { color:#2271b1; }
        .ial-royalties-chart-card { min-width:0; }
        .ial-royalties-chart-header { padding:12px 15px; border-bottom:1px solid #eee; background:#f8f9fa; }
        .ial-royalties-chart-header h3 { margin:0; font-size:14px; text-transform:uppercase; color:#50575e; font-weight:600; }
        .ial-royalties-chart-body { padding:12px 15px 14px; }
        .ial-royalties-chart-wrap { position:relative; height:220px; max-height:220px; display:flex; justify-content:center; align-items:center; }
        #ialRoyaltiesChart { max-height:200px !important; width:100% !important; height:100% !important; }
        .ial-royalties-legend { display:flex; flex-wrap:wrap; gap:8px 14px; margin-top:10px; }
        .ial-royalties-legend-item { display:inline-flex; align-items:center; gap:7px; font-size:12px; color:#50575e; }
        .ial-royalties-legend-swatch { width:10px; height:10px; border-radius:2px; display:inline-block; flex:0 0 10px; }
        .ial-royalties-legend-name { color:#1d2327; }
        .ial-royalties-empty-chart { padding:24px 16px; color:#646970; }
        @media (max-width: 960px) { .ial-royalties-dashboard-wrapper { display:flex; flex-direction:column; width:auto; margin-right:0; } .ial-royalties-kpis-card { min-height:auto; } .ial-royalties-chart-wrap { height:200px; max-height:200px; } #ialRoyaltiesChart { max-height:180px !important; } }
    ";
}
