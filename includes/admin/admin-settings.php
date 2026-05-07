<?php

if (!defined('ABSPATH')) {
    exit;
}

function ial_royalties_add_settings_page()
{
    add_submenu_page(
        'edit.php?post_type=ial_user_prod_assoc',
        __('Royalties Settings', 'ial-royalties'),
        __('Settings', 'ial-royalties'),
        'manage_options',
        'ial-royalties-settings',
        'ial_royalties_render_settings_page'
    );
}
add_action('admin_menu', 'ial_royalties_add_settings_page');

function ial_royalties_register_settings()
{

    register_setting('ial_royalties_settings_group', 'ial_royalties_trigger_status', array('sanitize_callback' => 'sanitize_text_field'));

    add_settings_section('ial_royalties_general_settings', __('Automation Settings', 'ial-royalties'), null, 'ial-royalties-settings');
    add_settings_field('ial_royalties_trigger_status', __('Order Status for Activation', 'ial-royalties'), 'ial_royalties_render_status_dropdown', 'ial-royalties-settings', 'ial_royalties_general_settings');

    $defaults = array(
        'collab_created_body' => "Hello {collaborator_name},<br><br>A new royalty of {amount} has been registered for the sale of {units} units of <strong>{product_name}</strong>.",
        'admin_created_body' => "A new royalty of {amount} has been created for {collaborator_name} for the product {product_name}.",
        'collab_paid_body' => "Hello {collaborator_name},<br><br>A royalty payment of {amount} has been processed for <strong>{product_name}</strong>.",
        'admin_paid_body' => "The royalty of {amount} for {collaborator_name} ({product_name}) has been marked as paid."
    );

    $email_fields = array(
        'ial_email_collab_created_subj',
        'ial_email_collab_created_body',
        'ial_email_admin_created_subj',
        'ial_email_admin_created_body',
        'ial_email_collab_paid_subj',
        'ial_email_collab_paid_body',
        'ial_email_admin_paid_subj',
        'ial_email_admin_paid_body'
    );

    foreach ($email_fields as $field) {
        register_setting('ial_royalties_settings_group', $field, array('sanitize_callback' => 'wp_kses_post'));
    }

    add_settings_section('ial_royalties_email_settings', __('Email Notifications Templates', 'ial-royalties'), 'ial_royalties_email_section_desc', 'ial-royalties-settings');

    add_settings_field('ial_email_collab_created_subj', 'Collaborator: New Record (Subject)', 'ial_render_text_input', 'ial-royalties-settings', 'ial_royalties_email_settings', array('id' => 'ial_email_collab_created_subj'));
    add_settings_field('ial_email_collab_created_body', 'Collaborator: New Record (Body)', 'ial_render_visual_editor', 'ial-royalties-settings', 'ial_royalties_email_settings', array('id' => 'ial_email_collab_created_body', 'default_text' => $defaults['collab_created_body']));

    add_settings_field('ial_email_admin_created_subj', 'Admin: New Record (Subject)', 'ial_render_text_input', 'ial-royalties-settings', 'ial_royalties_email_settings', array('id' => 'ial_email_admin_created_subj'));
    add_settings_field('ial_email_admin_created_body', 'Admin: New Record (Body)', 'ial_render_visual_editor', 'ial-royalties-settings', 'ial_royalties_email_settings', array('id' => 'ial_email_admin_created_body', 'default_text' => $defaults['admin_created_body']));

    add_settings_field('ial_email_collab_paid_subj', 'Collaborator: Payment Sent (Subject)', 'ial_render_text_input', 'ial-royalties-settings', 'ial_royalties_email_settings', array('id' => 'ial_email_collab_paid_subj'));
    add_settings_field('ial_email_collab_paid_body', 'Collaborator: Payment Sent (Body)', 'ial_render_visual_editor', 'ial-royalties-settings', 'ial_royalties_email_settings', array('id' => 'ial_email_collab_paid_body', 'default_text' => $defaults['collab_paid_body']));

    add_settings_field('ial_email_admin_paid_subj', 'Admin: Payment Sent (Subject)', 'ial_render_text_input', 'ial-royalties-settings', 'ial_royalties_email_settings', array('id' => 'ial_email_admin_paid_subj'));
    add_settings_field('ial_email_admin_paid_body', 'Admin: Payment Sent (Body)', 'ial_render_visual_editor', 'ial-royalties-settings', 'ial_royalties_email_settings', array('id' => 'ial_email_admin_paid_body', 'default_text' => $defaults['admin_paid_body']));
}
add_action('admin_init', 'ial_royalties_register_settings');

function ial_royalties_render_status_dropdown()
{
    $saved_status = get_option('ial_royalties_trigger_status', 'wc-completed');
    $all_statuses = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : array();
    echo '<select name="ial_royalties_trigger_status">';
    foreach ($all_statuses as $status_key => $status_label) {
        printf('<option value="%s" %s>%s</option>', esc_attr($status_key), selected($saved_status, $status_key, false), esc_html($status_label));
    }
    echo '</select>';
}

function ial_royalties_email_section_desc()
{
    ?>
    <div class="card" style="padding: 15px; max-width: 100%;">
        <h2 class="title" style="margin-top:0;"><?php esc_html_e('Available Placeholders', 'ial-royalties'); ?>
        </h2>
        <p><?php esc_html_e('You can use these placeholders in the Subject and Body fields:', 'ial-royalties'); ?></p>
        <ul
            style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 10px; margin-top: 10px; margin-bottom: 20px;">
            <li><code>{site_name}</code> : Site Name</li>
            <li><code>{product_name}</code> : Product Title</li>
            <li><code>{collaborator_name}</code> : Collaborator Name</li>
            <li><code>{amount}</code> : Royalty Amount</li>
            <li><code>{units}</code> : Units Sold</li>
            <li><code>{my_account_url}</code> : My Account Link</li>
        </ul>

        <div style="background: #fff; border-left: 4px solid #72aee6; padding: 10px; font-size: 13px;">
            <strong><?php esc_html_e('Note about Links:', 'ial-royalties'); ?></strong><br>
            <?php esc_html_e('To create a clickable link to the panel, use the Insert Link button in the editor or copy this HTML code:', 'ial-royalties'); ?>
            <br><code
                style="display:inline-block; margin-top:5px; user-select: all;">&lt;a href="{my_account_url}"&gt;Go to Panel&lt;/a&gt;</code>
        </div>
    </div>
    <?php
}

function ial_render_text_input($args)
{
    $option = get_option($args['id']);
    echo '<input type="text" name="' . esc_attr($args['id']) . '" value="' . esc_attr($option) . '" class="regular-text" style="width: 100%; max-width: 600px;" />';
}

function ial_render_visual_editor($args)
{
    $option = get_option($args['id']);
    $default = isset($args['default_text']) ? $args['default_text'] : '';

    $settings = array(
        'textarea_name' => $args['id'],
        'textarea_rows' => 8,
        'media_buttons' => false,
        'teeny' => false,
    );

    wp_editor($option, $args['id'], $settings);

    ?>
    <div class="ial-editor-actions">
        <details class="ial-preview-details">
            <summary><?php esc_html_e('View Default Template', 'ial-royalties'); ?></summary>
            <div class="ial-preview-box">
                <?php echo wp_kses_post(nl2br($default)); ?>
            </div>
        </details>

        <button type="button" class="button ial-test-email-btn" data-editor-id="<?php echo esc_attr($args['id']); ?>"
            data-subject-id="<?php echo esc_attr(str_replace('_body', '_subj', $args['id'])); ?>">
            <span class="dashicons dashicons-email-alt"></span>
            <?php esc_html_e('Send Test Email', 'ial-royalties'); ?>
        </button>
    </div>
    <?php
}

function ial_royalties_render_settings_page()
{
    if (!current_user_can('manage_options'))
        return;
    ?>
    <div class="wrap">
        <style>
            .ial-editor-actions {
                margin-top: 10px;
                max-width: 800px;
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                gap: 20px;
            }

            .ial-preview-details {
                flex-grow: 1;
            }

            .ial-preview-details summary {
                cursor: pointer;
                color: #2271b1;
                font-size: 12px;
            }

            .ial-preview-box {
                margin-top: 5px;
                padding: 10px;
                background: #f0f0f1;
                border: 1px solid #ccc;
                font-size: 12px;
                color: #50575e;
            }

            .ial-preview-box a {
                pointer-events: none;
                cursor: default;
                color: #50575e;
                text-decoration: underline;
            }

            .ial-test-email-btn {
                display: inline-flex !important;
                align-items: center;
                gap: 6px;
            }

            .ial-test-email-btn .dashicons {
                font-size: 18px;
                width: 18px;
                height: 18px;
            }
        </style>

        <h1><?php echo esc_html__('Royalties Settings', 'ial-royalties'); ?></h1>
        <form action="options.php" method="POST">
            <?php
            settings_fields('ial_royalties_settings_group');
            do_settings_sections('ial-royalties-settings');
            submit_button();
            ?>
        </form>
    </div>

    <script type="text/javascript">
        jQuery(document).ready(function ($) {
            $('.ial-test-email-btn').on('click', function (e) {
                e.preventDefault();
                var btn = $(this);
                var editorId = btn.data('editor-id');
                var subjId = btn.data('subject-id');
                var subject = $('input[name="' + subjId + '"]').val();
                var content = '';

                if (typeof tinymce !== 'undefined' && tinymce.get(editorId) && !tinymce.get(editorId).isHidden()) {
                    content = tinymce.get(editorId).getContent();
                } else {
                    content = $('#' + editorId).val();
                }

                if (btn.hasClass('disabled')) return;
                btn.addClass('disabled').text('<?php esc_html_e('Sending...', 'ial-royalties'); ?>');

                $.post(ajaxurl, {
                    action: 'ial_send_test_email',
                    nonce: '<?php echo wp_create_nonce("ial_test_email_nonce"); ?>',
                    subject: subject,
                    body: content,
                    key: editorId
                }, function (response) {
                    if (response.success) {
                        alert(response.data);
                    } else {
                        alert('Error: ' + (response.data || 'Unknown error'));
                    }
                    btn.removeClass('disabled').html('<span class="dashicons dashicons-email-alt"></span> <?php esc_html_e('Send Test Email', 'ial-royalties'); ?>');
                });
            });
        });
    </script>
    <?php
}
?>