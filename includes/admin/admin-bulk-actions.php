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

    // Wallet actions — only when WP Simple Wallet is available.
    if (function_exists('wsw_credit')) {
        $bulk_actions['ial_pay_wallet'] = __('Pagar a Wallet', 'ial-royalties');
        $bulk_actions['ial_pay_wallet_and_note'] = __('Pagar a Wallet & Añadir Nota', 'ial-royalties');
    }

    return $bulk_actions;
}
add_filter('bulk_actions-edit-ial_royalty_record', 'ial_royalties_register_bulk_actions');

// Handle Bulk Actions logic.
// Payment actions are grouped by collaborator so that:
//   1. Wallet credits are issued once per collaborator (not per record).
//   2. Email notifications are consolidated: one email per collaborator.
function ial_royalties_handle_bulk_actions($redirect_to, $action, $post_ids)
{
    $wallet_actions = array('ial_pay_wallet', 'ial_pay_wallet_and_note');
    $manual_pay_actions = array('ial_mark_paid', 'ial_pay_and_note');
    $all_pay_actions = array_merge($manual_pay_actions, $wallet_actions);
    $allowed_actions = array_merge($all_pay_actions, array('ial_mark_unpaid', 'ial_bulk_note'));

    if (!in_array($action, $allowed_actions)) {
        return $redirect_to;
    }

    if (!current_user_can('edit_others_posts')) {
        wp_die(__('Acción no autorizada.', 'ial-royalties'));
    }

    $incoming_note = '';
    if (isset($_REQUEST['ial_bulk_note_content'])) {
        $incoming_note = sanitize_textarea_field(wp_unslash($_REQUEST['ial_bulk_note_content']));
    }

    $is_wallet = in_array($action, $wallet_actions);
    $is_pay = in_array($action, $all_pay_actions);
    $has_note = in_array($action, array('ial_bulk_note', 'ial_pay_and_note', 'ial_pay_wallet_and_note'));

    $processed = 0;
    $skipped = 0;
    $errors = 0;
    $status_changed = false;

    // --- Non-payment actions: simple per-record loop ---

    if ('ial_mark_unpaid' === $action) {
        foreach ($post_ids as $post_id) {
            update_post_meta($post_id, 'paid', 0);
            // Keep payment_method & wsw_tx_id as audit trail.
            $processed++;
        }
        $status_changed = true;

    } elseif ('ial_bulk_note' === $action) {
        if (!empty($incoming_note)) {
            foreach ($post_ids as $post_id) {
                ial_royalties_append_note($post_id, $incoming_note);
                $processed++;
            }
        }

    } else {
        // --- Payment actions: group by collaborator ---

        $payment_method = $is_wallet ? 'wallet' : 'manual';

        // 1. Group eligible records by collaborator.
        $grouped = array(); // user_id => [ post_id, ... ]

        foreach ($post_ids as $post_id) {
            // Already paid? Skip.
            if (1 === (int) get_post_meta($post_id, 'paid', true)) {
                $skipped++;
                continue;
            }

            // Already has a wallet transaction? Idempotency guard.
            if ($is_wallet && get_post_meta($post_id, 'wsw_tx_id', true)) {
                $skipped++;
                continue;
            }

            $collab_id = (int) get_post_meta($post_id, 'collaborator_user', true);
            if (!$collab_id) {
                $skipped++;
                continue;
            }

            $grouped[$collab_id][] = $post_id;
        }

        // 2. Process each collaborator batch.
        foreach ($grouped as $user_id => $record_ids) {

            $wsw_tx_id = null;

            // --- Wallet credit ---
            if ($is_wallet) {
                if (!function_exists('wsw_is_active') || !wsw_is_active($user_id)) {
                    $errors += count($record_ids);
                    continue;
                }

                $total_amount = 0;
                foreach ($record_ids as $rid) {
                    $total_amount += (float) get_post_meta($rid, 'royalty_total', true);
                }

                // Build a human-readable note for the WSW transaction.
                $prod_names = array();
                foreach (array_slice($record_ids, 0, 5) as $rid) {
                    $pid = get_post_meta($rid, 'product', true);
                    $prod_names[] = $pid ? get_the_title($pid) : '#' . $rid;
                }
                $wsw_note = sprintf(
                    /* translators: 1: record count, 2: product names */
                    __('Royalty payout: %1$d records (%2$s)', 'ial-royalties'),
                    count($record_ids),
                    implode(', ', $prod_names) . (count($record_ids) > 5 ? ', ...' : '')
                );

                $wsw_args = array(
                    'type'       => 'royalty_payout',
                    'source'     => 'wp-royalties',
                    'created_by' => get_current_user_id(),
                );

                $result = wsw_credit($user_id, $total_amount, $wsw_note, $wsw_args);

                if (is_wp_error($result)) {
                    $errors += count($record_ids);
                    continue;
                }

                $wsw_tx_id = (int) $result;
            }

            // --- Mark records as paid ---
            $today = date('Y-m-d');

            foreach ($record_ids as $post_id) {
                update_post_meta($post_id, 'paid', 1);
                update_post_meta($post_id, 'payment_method', $payment_method);
                update_post_meta($post_id, 'paid_date', $today);

                if ($wsw_tx_id) {
                    update_post_meta($post_id, 'wsw_tx_id', $wsw_tx_id);
                }

                if ($has_note && !empty($incoming_note)) {
                    ial_royalties_append_note($post_id, $incoming_note);
                }

                $processed++;
            }

            $status_changed = true;

            // --- Consolidated notification: one per collaborator ---
            do_action('ial_royalty_bulk_paid', $user_id, $record_ids, $payment_method);
        }
    }

    if ($status_changed && class_exists('IdeaaLab_Royalties')) {
        IdeaaLab_Royalties::clear_badge_cache();
    }

    $args = array('ial_bulk_processed' => $processed);
    if ($skipped > 0) {
        $args['ial_bulk_skipped'] = $skipped;
    }
    if ($errors > 0) {
        $args['ial_bulk_errors'] = $errors;
    }

    return add_query_arg($args, $redirect_to);
}
add_filter('handle_bulk_actions-edit-ial_royalty_record', 'ial_royalties_handle_bulk_actions', 10, 3);

// Admin notices after bulk action.
function ial_royalties_bulk_admin_notice()
{
    if (!empty($_REQUEST['ial_bulk_processed'])) {
        $count = intval($_REQUEST['ial_bulk_processed']);
        printf(
            '<div id="message" class="updated notice is-dismissible"><p>%s</p></div>',
            sprintf(esc_html__('%d registros de royalty actualizados.', 'ial-royalties'), $count)
        );
    }
    if (!empty($_REQUEST['ial_bulk_skipped'])) {
        $count = intval($_REQUEST['ial_bulk_skipped']);
        printf(
            '<div id="message" class="notice notice-warning is-dismissible"><p>%s</p></div>',
            sprintf(esc_html__('%d registros omitidos (ya estaban pagados).', 'ial-royalties'), $count)
        );
    }
    if (!empty($_REQUEST['ial_bulk_errors'])) {
        $count = intval($_REQUEST['ial_bulk_errors']);
        printf(
            '<div id="message" class="notice notice-error is-dismissible"><p>%s</p></div>',
            sprintf(esc_html__('%d registros no pudieron procesarse (wallet inactiva o error).', 'ial-royalties'), $count)
        );
    }
}
add_action('admin_notices', 'ial_royalties_bulk_admin_notice');

// Helper: append a note to a record's existing notes.
function ial_royalties_append_note($post_id, $note)
{
    $existing = get_post_meta($post_id, 'notes', true);
    $updated = $existing ? $existing . "\n" . $note : $note;
    update_post_meta($post_id, 'notes', $updated);
}
