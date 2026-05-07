<?php

if (!defined('ABSPATH')) {
    exit;
}

function ial_royalties_enqueue_frontend()
{
    global $post;
    $should_load = false;

    if (function_exists('is_account_page') && is_account_page()) {
        $should_load = true;
    }
    if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'ial_collaborator_dashboard')) {
        $should_load = true;
    }

    if ($should_load) {
        wp_enqueue_style('dashicons');

        $css = "
            .ial-dashboard-wrapper { 
                margin-top: 20px; 
                width: 100% !important; 
                max-width: 100% !important; 
                box-sizing: border-box;
                display: block;
                margin-left: 0 !important;
                margin-right: 0 !important;
                padding: 0 !important;
            }

            .ial-table-responsive { 
                width: 100% !important;
                overflow-x: auto; 
                margin-bottom: 30px; 
                border: 1px solid #e5e5e5; 
                border-radius: 4px; 
                background: #fff;
            }
            
            table.ial-dashboard-table { 
                width: 100% !important; 
                max-width: 100% !important;
                border-collapse: collapse; 
                font-size: 14px; 
                margin: 0 !important;
                table-layout: auto;
            }
            
            table.ial-dashboard-table th, table.ial-dashboard-table td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #eee; }
            table.ial-dashboard-table th { background: #f9f9f9; font-weight: 600; color: #333; }
            table.ial-dashboard-table tr:last-child td { border-bottom: none; }
            
            .ial-text-right { text-align: right !important; }
            .ial-text-center { text-align: center !important; }
            .ial-muted { color: #999; font-style: italic; }
            
            .ial-badge { display: inline-block; padding: 3px 8px; border-radius: 12px; font-size: 11px; font-weight: bold; text-transform: uppercase; line-height: 1; }
            .ial-badge-success { background: #d4edda; color: #155724; }
            .ial-badge-warning { background: #fff3cd; color: #856404; }

            .ial-summary-box { display: flex; gap: 20px; margin-top: 40px; background: #fcfcfc; border: 1px solid #ddd; padding: 20px; border-radius: 4px; width: 100% !important; box-sizing: border-box; }
            .ial-summary-item { flex: 1; text-align: center; padding: 10px; border-right: 1px solid #eee; }
            .ial-summary-item:last-child { border-right: none; }
            .ial-summary-item h4 { margin: 0 0 10px; font-size: 14px; color: #555; text-transform: uppercase; letter-spacing: 1px; }
            .ial-summary-item .ial-amount { font-size: 28px; font-weight: bold; color: #333; margin-bottom: 5px; }
            .ial-summary-item.ial-pending .ial-amount { color: #d63638; }

            @media (max-width: 768px) {
                .ial-summary-box { flex-direction: column; }
                .ial-summary-item { border-right: none; border-bottom: 1px solid #eee; }
                .ial-summary-item:last-child { border-bottom: none; }
                
                table.ial-dashboard-table { min-width: 600px; } 
            }

            .woocommerce-MyAccount-navigation-link--royalties a {
                position: relative !important;
                display: flex !important;
                align-items: center !important;
                padding-left: 26px; 
            }
            .woocommerce-MyAccount-navigation-link--royalties a::before {
                content: \"\\f184\"; 
                font-family: \"dashicons\" !important; 
                position: absolute !important;
                left: 0 !important; 
                top: 50% !important; 
                transform: translateY(-50%) !important;
                display: inline-flex !important; 
                align-items: center;
                justify-content: center;
                width: 20px !important;
                height: 20px !important;
                font-size: 20px !important;
                font-weight: 400;
                line-height: 1 !important;
                text-decoration: none;
                -webkit-font-smoothing: antialiased;
                color: inherit;
            }
        ";
        wp_register_style('ial-royalties-css', false);
        wp_enqueue_style('ial-royalties-css');
        wp_add_inline_style('ial-royalties-css', $css);
    }
}
add_action('wp_enqueue_scripts', 'ial_royalties_enqueue_frontend');