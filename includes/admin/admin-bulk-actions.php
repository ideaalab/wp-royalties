<?php

if (!defined('ABSPATH')) {
    exit;
}

// Register Bulk Actions.
function ial_royalties_register_bulk_actions($bulk_actions)
{
    $bulk_actions['ial_mark_paid'] = __('Marcar como Pagado', 'ial-royalties');
    $bulk_actions['ial_mark_unpaid'] = __('Marcar como No Pagado', 'ial-royalties');
    $bulk_actions['ial_bulk_note'] = __('Añadir solo Nota', 'ial-royalties');
    $bulk_actions['ial_pay_and_note'] = __('Marcar como Pagado & Añadir Nota', 'ial-royalties');
    return $bulk_actions;
}
add_filter('bulk_actions-edit-ial_royalty_record', 'ial_royalties_register_bulk_actions');

// Handle Bulk Actions logic.
function ial_royalties_handle_bulk_actions($redirect_to, $action, $post_ids)
{

    $allowed_actions = array('ial_mark_paid', 'ial_mark_unpaid', 'ial_bulk_note', 'ial_pay_and_note');
    if (!in_array($action, $allowed_actions)) {
        return $redirect_to;
    }

    if (!current_user_can('edit_others_posts')) {
        wp_die(__('Acción no autorizada.', 'ial-royalties'));
    }

    $processed_count = 0;
    $status_changed = false;

    // Sanitize Input
    $incoming_note = '';
    if (isset($_REQUEST['ial_bulk_note_content'])) {
        $incoming_note = sanitize_textarea_field(wp_unslash($_REQUEST['ial_bulk_note_content']));
    }

    foreach ($post_ids as $post_id) {

        $should_update_note = false;
        $note_to_save = '';
        $paid_transition = false;

        switch ($action) {
            case 'ial_mark_paid':
                $old_paid = (int) get_post_meta($post_id, 'paid', true);
                update_post_meta($post_id, 'paid', 1);
                $paid_transition = (0 === $old_paid);
                $status_changed = true;
                break;

            case 'ial_mark_unpaid':
                update_post_meta($post_id, 'paid', 0);
                $status_changed = true;
                break;

            case 'ial_bulk_note':
                $should_update_note = true;
                $note_to_save = $incoming_note;
                break;

            case 'ial_pay_and_note':
                $old_paid = (int) get_post_meta($post_id, 'paid', true);
                update_post_meta($post_id, 'paid', 1);
                $paid_transition = (0 === $old_paid);
                $should_update_note = true;
                $note_to_save = $incoming_note;
                $status_changed = true;
                break;
        }

        // Add note if exists
        if ($should_update_note && !empty($note_to_save)) {
            $existing_note = get_post_meta($post_id, 'notes', true);
            $updated_note = $existing_note ? $existing_note . "\n" . $note_to_save : $note_to_save;
            update_post_meta($post_id, 'notes', $updated_note);
        }

        // Fire the same action the meta-box save fires, so any listener
        // (emails, integrations, etc.) reacts consistently to bulk vs.
        // per-record edits. Only on real 0→1 transitions to avoid spam.
        if ($paid_transition) {
            do_action('ial_royalty_paid_status_changed', $post_id);
        }

        $processed_count++;
    }

    // Clear badge cache if payment status changed
    if ($status_changed && class_exists('IdeaaLab_Royalties')) {
        IdeaaLab_Royalties::clear_badge_cache();
    }

    return add_query_arg('ial_bulk_processed', $processed_count, $redirect_to);
}
add_filter('handle_bulk_actions-edit-ial_royalty_record', 'ial_royalties_handle_bulk_actions', 10, 3);

function ial_royalties_bulk_admin_notice()
{
    if (!empty($_REQUEST['ial_bulk_processed'])) {
        $count = intval($_REQUEST['ial_bulk_processed']);
        printf(
            '<div id="message" class="updated notice is-dismissible"><p>%s</p></div>',
            sprintf(esc_html__('%d registros de royalty actualizados.', 'ial-royalties'), $count)
        );
    }
}
add_action('admin_notices', 'ial_royalties_bulk_admin_notice');
