<?php

if (!defined('ABSPATH')) {
    exit;
}

// Handles hook registration and email sending.
class IdeaaLab_Royalty_Emails
{

    private static $instance = null;

    private $payment_notification_pending = false;

    // Singleton accessor. Use this instead of `new` so that the hook
    // listeners are only registered once per request.
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        // Automatic trigger on royalty creation.
        add_action('ial_royalty_record_created', array($this, 'send_creation_notifications'), 10, 1);

        // Automatic trigger when a royalty is marked as paid via meta box
        add_action('ial_royalty_paid_status_changed', array($this, 'send_paid_notifications'), 10, 1);

        // AJAX Handler for manual triggers from admin area.
        add_action('wp_ajax_ial_send_notification', array($this, 'ajax_handle_manual_send'));
        add_action('wp_ajax_ial_send_test_email', array($this, 'ajax_handle_send_test'));
    }

    // Logging utility for email event debugging.
    private function log($message)
    {
        if (function_exists('ial_royalties_log')) {
            ial_royalties_log('[Emails] ' . $message);
        }
    }

    // Send notifications when a new royalty record is created.
    public function send_creation_notifications($record_id)
    {
        $this->log("Triggering 'Created' notifications for Record #{$record_id}.");
        $this->send_email_by_type($record_id, 'created', 'collaborator');
        $this->send_email_by_type($record_id, 'created', 'admin');
    }

    // Send notifications when a royalty record is marked as paid.
    public function send_paid_notifications($record_id)
    {
        $this->log("Triggering 'Paid' notifications for Record #{$record_id}.");
        $this->send_email_by_type($record_id, 'paid', 'collaborator');
        $this->send_email_by_type($record_id, 'paid', 'admin');
    }

    // Gets an email template from the database, with default fallback.
    private function get_email_template($option_key, $default)
    {
        $option = get_option($option_key);
        return !empty($option) ? $option : $default;
    }

    // Core email sending function.
    private function send_email_by_type($record_id, $email_type, $recipient_type)
    {
        // 1. Data Collection
        $collaborator_id = get_post_meta($record_id, 'collaborator_user', true);
        if (!$collaborator_id) {
            $this->log("Error: Could not find valid collaborator ID for Record #{$record_id}.");
            return false;
        }

        $collaborator_user = get_userdata($collaborator_id);
        if (!$collaborator_user) {
            $this->log("Error: Collaborator user object not found for ID {$collaborator_id}.");
            return false;
        }

        $recipient_email = ('collaborator' === $recipient_type) ? $collaborator_user->user_email : get_option('admin_email');

        if (!is_email($recipient_email)) {
            $this->log("Error: Invalid recipient email '{$recipient_email}' for {$recipient_type}.");
            return false;
        }

        $product_id = get_post_meta($record_id, 'product', true);
        $product_title = $product_id ? get_the_title($product_id) : __('Deleted Product', 'ial-royalties');

        $my_account_url = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url();

        // 2. Placeholder Preparation
        $placeholders = array(
            '{site_name}' => get_bloginfo('name'),
            '{product_name}' => $product_title,
            '{collaborator_name}' => $collaborator_user->display_name,
            '{amount}' => wc_price((float) get_post_meta($record_id, 'royalty_total', true)),
            '{units}' => (int) get_post_meta($record_id, 'units', true),
            '{my_account_url}' => $my_account_url,
        );

        // 3. Get Template
        $subject_template = '';
        $body_template = '';

        if ('created' === $email_type) {
            if ('collaborator' === $recipient_type) {
                $subject_template = $this->get_email_template('ial_email_collab_created_subj', '[{site_name}] New Royalty Recorded');
                $body_template = $this->get_email_template('ial_email_collab_created_body', 'Hello {collaborator_name},<br><br>A new royalty of {amount} has been recorded for the sale of {units} unit(s) of <strong>{product_name}</strong>.');
            } else { // admin
                $subject_template = $this->get_email_template('ial_email_admin_created_subj', '[{site_name}] New Royalty Created for {collaborator_name}');
                $body_template = $this->get_email_template('ial_email_admin_created_body', 'A new royalty of {amount} has been created for {collaborator_name} for the product {product_name}.');
            }
        } elseif ('paid' === $email_type) {
            if ('collaborator' === $recipient_type) {
                $subject_template = $this->get_email_template('ial_email_collab_paid_subj', '[{site_name}] Payment Sent for Royalty');
                $body_template = $this->get_email_template('ial_email_collab_paid_body', 'Hello {collaborator_name},<br><br>A payment of {amount} has been processed for the royalty of {product_name}.');
            } else { // admin
                $subject_template = $this->get_email_template('ial_email_admin_paid_subj', '[{site_name}] Royalty Marked as Paid');
                $body_template = $this->get_email_template('ial_email_admin_paid_body', 'The royalty of {amount} for {collaborator_name} ({product_name}) has been marked as paid.');
            }
        }

        // 4. Population and Sanitization
        $subject = str_replace(array_keys($placeholders), array_values($placeholders), $subject_template);
        $body = str_replace(array_keys($placeholders), array_values($placeholders), $body_template);

        $body = str_replace(array('%7Bmy_account_url%7D', '%7bmy_account_url%7d'), $my_account_url, $body);
        $body = ial_sanitize_url($body);
        $body = wpautop($body);

        // 5. Send Email
        $headers = array('Content-Type: text/html; charset=UTF-8');
        $sent = wp_mail($recipient_email, $subject, $body, $headers);

        if ($sent) {
            $this->log("Success: '{$email_type}' email sent to {$recipient_type} ({$recipient_email}).");
        } else {
            $this->log("Error: wp_mail() failed for '{$email_type}' to {$recipient_type} ({$recipient_email}).");
        }

        return $sent;
    }

    // AJAX Handler: Manually send a notification from admin area.
    public function ajax_handle_manual_send()
    {
        check_ajax_referer('ial_notification_nonce', 'nonce');
        if (!current_user_can('edit_others_posts')) {
            wp_send_json_error('Unauthorized');
        }

        $record_id = isset($_POST['record_id']) ? absint($_POST['record_id']) : 0;
        $email_type = isset($_POST['notification_type']) ? sanitize_key($_POST['notification_type']) : '';

        if (!$record_id || !in_array($email_type, array('created', 'paid'))) {
            wp_send_json_error('Invalid request parameters.');
        }

        if ('created' === $email_type) {
            $this->send_creation_notifications($record_id);
        } elseif ('paid' === $email_type) {
            $this->send_paid_notifications($record_id);
        }

        wp_send_json_success(__('Notifications sent successfully.', 'ial-royalties'));
    }

    // AJAX Handler: Send a test email from settings page.
    public function ajax_handle_send_test()
    {
        check_ajax_referer('ial_test_email_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $current_user = wp_get_current_user();
        $to_email = $current_user->user_email;

        $subject = isset($_POST['subject']) ? sanitize_text_field(wp_unslash($_POST['subject'])) : 'Test Subject';
        $body = isset($_POST['body']) ? wp_kses_post(wp_unslash($_POST['body'])) : 'Test body content.';

        $my_account_url = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url();

        // Use dummy data for placeholders.
        $dummy_placeholders = array(
            '{site_name}' => get_bloginfo('name'),
            '{product_name}' => 'Sample Product',
            '{collaborator_name}' => $current_user->display_name,
            '{amount}' => wc_price(123.45),
            '{units}' => 10,
            '{my_account_url}' => $my_account_url,
        );

        $final_subject = str_replace(array_keys($dummy_placeholders), array_values($dummy_placeholders), $subject);
        $final_body = str_replace(array_keys($dummy_placeholders), array_values($dummy_placeholders), $body);

        $final_body = str_replace(array('%7Bmy_account_url%7D', '%7bmy_account_url%7d'), $my_account_url, $final_body);
        $final_body = ial_sanitize_url($final_body);

        $final_body = wpautop($final_body);
        $headers = array('Content-Type: text/html; charset=UTF-8');

        if (wp_mail($to_email, '[TEST] ' . $final_subject, $final_body, $headers)) {
            wp_send_json_success(sprintf(__('Test email sent successfully to %s', 'ial-royalties'), $to_email));
        } else {
            wp_send_json_error(__('Failed to send the test email. Please check your WordPress email configuration.', 'ial-royalties'));
        }
    }
}

IdeaaLab_Royalty_Emails::get_instance();
