<?php

if (!defined('ABSPATH')) {
    exit;
}

function ial_royalties_admin_assets()
{
    global $pagenow;
    $screen = get_current_screen();

    if (!$screen)
        return;

    // 1. Global CSS for Royalty Screens
    if ($screen->post_type === 'ial_royalty_record') {
        $custom_css = "
            ul#adminmenu li a[href*='post_type=ial_royalty_record'] { white-space: nowrap !important; padding-right: 5px; }
            ul#adminmenu li a[href*='post_type=ial_royalty_record'] .update-plugins { display: inline-block; margin-left: 5px; vertical-align: baseline; }
            .ial-send-notification { display: flex !important; align-items: center; justify-content: center; gap: 6px; }
            .ial-send-notification .dashicons { margin-top: 1px; font-size: 18px; width: 18px; height: 18px; }
        ";
        wp_add_inline_style('admin-bar', $custom_css);
    }

    // 2. JS for Bulk Actions
    if ($screen->post_type === 'ial_royalty_record' && $pagenow === 'edit.php') {
        $script = "
        jQuery(document).ready(function($){
            $('form#posts-filter').submit(function(e){
                var actionTop = $('select[name=\"action\"]').val();
                var actionBottom = $('select[name=\"action2\"]').val();
                var action = ( actionTop !== '-1' ) ? actionTop : actionBottom;
                if ( action === 'ial_bulk_note' || action === 'ial_pay_and_note' ) {
                    var promptText = '" . esc_js(__('Enter the note to add:', 'ial-royalties')) . "';
                    if( action === 'ial_pay_and_note' ) promptText = '" . esc_js(__('Enter the note for the payment:', 'ial-royalties')) . "';
                    var note = prompt( promptText );
                    if ( note != null && note.trim() !== '' ) {
                        $('<input>').attr({ type: 'hidden', name: 'ial_bulk_note_content', value: note }).appendTo('form#posts-filter');
                    } else {
                        e.preventDefault();
                        alert('" . esc_js(__('Action cancelled: Note is required.', 'ial-royalties')) . "');
                    }
                }
            });
        });";
        wp_add_inline_script('jquery-core', $script);
    }

    // 3. JS for Edit Screen Buttons
    if ($screen->post_type === 'ial_royalty_record' && $pagenow === 'post.php') {
        wp_enqueue_script('ial-admin-validation', plugin_dir_url(__FILE__) . '../../assets/js/admin-validation.js', array('jquery'), '1.0', true);

        $ajax_nonce = wp_create_nonce('ial_notification_nonce');
        $edit_script = "
        jQuery(document).ready(function($){
            $('.ial-send-notification').on('click', function(e){
                e.preventDefault();
                var btn = $(this);
                var type = btn.data('type');
                var post_id = btn.data('id');
                
                if( btn.hasClass('disabled') ) return;
                if( !confirm('" . esc_js(__('Are you sure you want to send the email notifications?', 'ial-royalties')) . "') ) return;

                btn.addClass('disabled').text('" . esc_js(__('Sending...', 'ial-royalties')) . "');

                $.post( ajaxurl, {
                    action: 'ial_send_notification',
                    nonce: '" . $ajax_nonce . "',
                    record_id: post_id,
                    notification_type: type
                }, function( response ) {
                    if ( response.success ) {
                        alert( response.data );
                    } else {
                        alert( 'Error: ' + (response.data || 'Unknown error') );
                    }
                    if(type === 'created') btn.text('" . esc_js(__('Resend Created Notification', 'ial-royalties')) . "');
                    else btn.text('" . esc_js(__('Send Paid Notification', 'ial-royalties')) . "');
                    btn.removeClass('disabled');
                });
            });
        });
        ";
        wp_add_inline_script('jquery-core', $edit_script);
    }
}
add_action('admin_enqueue_scripts', 'ial_royalties_admin_assets');


// Menu Badge for Unpaid items.
function ial_royalties_add_menu_badge()
{
    global $menu, $submenu;
    if (!current_user_can('edit_posts'))
        return;

    // Attempt to get count from cache
    $count = get_transient('ial_royalties_unpaid_count');

    if (false === $count) {
        $args = array(
            'post_type' => 'ial_royalty_record',
            'post_status' => 'publish',
            'fields' => 'ids',
            'posts_per_page' => -1,
            'meta_query' => array(
                'relation' => 'OR',
                array('key' => 'paid', 'value' => '1', 'compare' => '!='),
                array('key' => 'paid', 'compare' => 'NOT EXISTS')
            )
        );
        $query = new WP_Query($args);
        $count = $query->found_posts;

        // Cache for 24 hours (will be cleared by hooks on update/create)
        set_transient('ial_royalties_unpaid_count', $count, 24 * HOUR_IN_SECONDS);
    }

    if ($count < 1)
        return;

    $badge_html = sprintf(' <span class="update-plugins count-%1$d"><span class="plugin-count">%1$d</span></span>', $count);
    $menu_slug = 'edit.php?post_type=ial_user_prod_assoc';

    if (isset($menu)) {
        foreach ($menu as $key => $value) {
            if ($value[2] === $menu_slug) {
                $menu[$key][0] .= $badge_html;
                break;
            }
        }
    }
    if (isset($submenu[$menu_slug])) {
        foreach ($submenu[$menu_slug] as $key => $value) {
            if (strpos($value[2], 'post_type=ial_royalty_record') !== false) {
                $submenu[$menu_slug][$key][0] .= $badge_html;
                break;
            }
        }
    }
}
add_action('admin_menu', 'ial_royalties_add_menu_badge', 99);

// Cleanup Submenu Items.
function ial_royalties_cleanup_menu()
{
    remove_submenu_page('edit.php?post_type=ial_user_prod_assoc', 'post-new.php?post_type=ial_user_prod_assoc');
}
add_action('admin_menu', 'ial_royalties_cleanup_menu', 999);

// Meta Box for Email Actions.
function ial_royalties_add_email_actions_meta_box()
{
    add_meta_box(
        'ial_royalties_actions_box',
        __('Email Actions', 'ial-royalties'),
        'ial_royalties_render_actions_box',
        'ial_royalty_record',
        'side',
        'high'
    );
}
add_action('add_meta_boxes', 'ial_royalties_add_email_actions_meta_box');

function ial_royalties_render_actions_box($post)
{
    ?>
    <div style="margin-bottom: 10px;">
        <p class="description"><?php esc_html_e('Manually trigger email notifications.', 'ial-royalties'); ?></p>
    </div>

    <button type="button" class="button ial-send-notification" data-type="created"
        data-id="<?php echo esc_attr($post->ID); ?>" style="width:100%; margin-bottom:5px;">
        <span class="dashicons dashicons-email-alt"></span>
        <?php esc_html_e('Resend Created Notification', 'ial-royalties'); ?>
    </button>

    <button type="button" class="button button-primary ial-send-notification" data-type="paid"
        data-id="<?php echo esc_attr($post->ID); ?>" style="width:100%;">
        <span class="dashicons dashicons-money-alt"></span>
        <?php esc_html_e('Send Paid Notification', 'ial-royalties'); ?>
    </button>
    <?php
}