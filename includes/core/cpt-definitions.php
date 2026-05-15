<?php

if (!defined('ABSPATH')) {
    exit;
}

function ial_register_royalty_cpts()
{

    // 1. CPT: User-Product Associations
    $assoc_labels = array(
        'name' => _x('User-Product Associations', 'post type general name', 'ial-royalties'),
        'singular_name' => _x('User-Product Association', 'post type singular name', 'ial-royalties'),
        'menu_name' => _x('Royalties', 'admin menu', 'ial-royalties'),
        'all_items' => __('Associations', 'ial-royalties'),
        'add_new' => __('Add New Association', 'ial-royalties'),
        'add_new_item' => __('Add New Association', 'ial-royalties'),
        'edit_item' => __('Edit Association', 'ial-royalties'),
        'view_item' => __('View Association', 'ial-royalties'),
    );

    $assoc_args = array(
        'labels' => $assoc_labels,
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => true,
        'menu_position' => 26,
        'menu_icon' => 'dashicons-money-alt',
        'supports' => array('title', 'custom-fields'),
        'capability_type' => 'post',
        'map_meta_cap' => true,
        'description' => __('Defines the rules for calculating royalties for collaborators.', 'ial-royalties'),
    );

    register_post_type('ial_user_prod_assoc', $assoc_args);

    // 2. CPT: Recorded Royalty
    $record_labels = array(
        'name' => _x('Recorded Royalties', 'post type general name', 'ial-royalties'),
        'singular_name' => _x('Recorded Royalty', 'post type singular name', 'ial-royalties'),
        'menu_name' => _x('Royalty Records', 'admin menu', 'ial-royalties'),
        'all_items' => __('Royalty Records', 'ial-royalties'),
        'add_new_item' => __('Add New Royalty Record Manually', 'ial-royalties'),
        'edit_item' => __('Edit Royalty Record', 'ial-royalties'),
        'view_item' => __('View Royalty Record', 'ial-royalties'),
    );

    $record_args = array(
        'labels' => $record_labels,
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => 'edit.php?post_type=ial_user_prod_assoc',
        'supports' => array('custom-fields'),
        'capability_type' => 'post',
        'map_meta_cap' => true,
        'description' => __('Logs each royalty transaction generated from a WooCommerce order.', 'ial-royalties'),
    );

    register_post_type('ial_royalty_record', $record_args);
}
add_action('init', 'ial_register_royalty_cpts');