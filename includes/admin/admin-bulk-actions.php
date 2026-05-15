<?php

if (!defined('ABSPATH')) {
    exit;
}

// =====================================================================
//  SHARED HELPERS
// =====================================================================

/**
 * Process a wallet payout for one collaborator's royalty records.
 *
 * Credits the wallet once with the total amount, then marks every
 * record as paid and stores the WSW transaction ID for traceability.
 *
 * @param int   $user_id    Collaborator user ID.
 * @param int[] $record_ids Royalty record post IDs (must all be unpaid).
 * @return array|WP_Error   { tx_id, total, count } on success.
 */
function ial_royalties_process_wallet_payout($user_id, $record_ids)
{
    if (!function_exists('wsw_credit') || empty($record_ids)) {
        return new WP_Error('wsw_unavailable', __('WP Simple Wallet is not available.', 'ial-royalties'));
    }

    // Auto-activate wallet if the collaborator doesn't have one yet.
    // The admin explicitly chose to pay to wallet, so creating it is
    // a necessary prerequisite — not a side-effect.
    $wallet_created = false;
    if (function_exists('wsw_is_active') && !wsw_is_active($user_id)) {
        if (function_exists('wsw_set_active')) {
            wsw_set_active($user_id, true);
            $wallet_created = true;
        }
    }

    // --- Build detailed note and compute total ---
    $total_amount = 0;
    $note_lines = array();

    foreach ($record_ids as $rid) {
        $amount   = (float) get_post_meta($rid, 'royalty_total', true);
        $total_amount += $amount;

        $prod_id  = get_post_meta($rid, 'product', true);
        $prod_name = $prod_id ? get_the_title($prod_id) : '—';
        $order_id = get_post_meta($rid, 'order', true);
        $source   = get_post_meta($rid, 'record_source', true);
        $units    = (int) get_post_meta($rid, 'units', true);

        $order_ref = ('manual' === $source)
            ? __('Manual', 'ial-royalties')
            : (($order_id) ? '#' . $order_id : '—');

        $note_lines[] = sprintf(
            '| RR#%d • %s %s • %s • %d %s • %s',
            $rid,
            __('Order', 'ial-royalties'),
            $order_ref,
            $prod_name,
            $units,
            _n('unit', 'units', $units, 'ial-royalties'),
            strip_tags(wc_price($amount))
        );
    }

    $wsw_note = sprintf(
        __('Royalty payout (%d records):', 'ial-royalties'),
        count($record_ids)
    ) . "\n" . implode("\n", $note_lines) . "\n" . sprintf(
        __('Total: %s', 'ial-royalties'),
        strip_tags(wc_price($total_amount))
    );

    // --- Credit wallet ---
    $wsw_args = array(
        'type'       => 'royalty_payout',
        'source'     => 'wp-royalties',
        'created_by' => get_current_user_id(),
    );

    $result = wsw_credit($user_id, $total_amount, $wsw_note, $wsw_args);

    if (is_wp_error($result)) {
        return $result;
    }

    $wsw_tx_id = (int) $result;
    $today = date('Y-m-d');

    // --- Mark records as paid ---
    foreach ($record_ids as $post_id) {
        update_post_meta($post_id, 'paid', 1);
        update_post_meta($post_id, 'payment_method', 'wallet');
        update_post_meta($post_id, 'paid_date', $today);
        update_post_meta($post_id, 'wsw_tx_id', $wsw_tx_id);
    }

    return array(
        'tx_id'          => $wsw_tx_id,
        'total'          => $total_amount,
        'count'          => count($record_ids),
        'wallet_created' => $wallet_created,
    );
}

// Helper: append a note to a record's existing notes.
function ial_royalties_append_note($post_id, $note)
{
    $existing = get_post_meta($post_id, 'notes', true);
    $updated = $existing ? $existing . "\n" . $note : $note;
    update_post_meta($post_id, 'notes', $updated);
}

/**
 * Filter a list of record IDs, keeping only unpaid ones without
 * an existing WSW transaction (idempotency guard for wallet payouts).
 */
function ial_royalties_filter_unpaid($record_ids, $wallet = false)
{
    return array_values(array_filter($record_ids, function ($rid) use ($wallet) {
        if (1 === (int) get_post_meta($rid, 'paid', true)) {
            return false;
        }
        if ($wallet && get_post_meta($rid, 'wsw_tx_id', true)) {
            return false;
        }
        return true;
    }));
}

// =====================================================================
//  ROYALTY RECORDS — Bulk Actions
// =====================================================================

function ial_royalties_register_bulk_actions($bulk_actions)
{
    $bulk_actions['ial_mark_paid'] = __('Marcar como Pagado', 'ial-royalties');
    $bulk_actions['ial_mark_unpaid'] = __('Marcar como No Pagado', 'ial-royalties');
    $bulk_actions['ial_bulk_note'] = __('Añadir solo Nota', 'ial-royalties');
    $bulk_actions['ial_pay_and_note'] = __('Marcar como Pagado & Añadir Nota', 'ial-royalties');

    if (function_exists('wsw_credit')) {
        $bulk_actions['ial_pay_wallet'] = __('Pagar a Wallet', 'ial-royalties');
        $bulk_actions['ial_pay_wallet_and_note'] = __('Pagar a Wallet & Añadir Nota', 'ial-royalties');
    }

    return $bulk_actions;
}
add_filter('bulk_actions-edit-ial_royalty_record', 'ial_royalties_register_bulk_actions');

/**
 * Handle bulk actions on Royalty Records.
 *
 * Payment actions are grouped by collaborator so that wallet credits
 * are issued once per collaborator and emails are consolidated.
 */
function ial_royalties_handle_bulk_actions($redirect_to, $action, $post_ids)
{
    $wallet_actions = array('ial_pay_wallet', 'ial_pay_wallet_and_note');
    $manual_pay_actions = array('ial_mark_paid', 'ial_pay_and_note');
    $all_pay_actions = array_merge($manual_pay_actions, $wallet_actions);
    $allowed_actions = array_merge($all_pay_actions, array('ial_mark_unpaid', 'ial_bulk_note'));

    if (!in_array($action, $allowed_actions)) {
        return $redirect_to;
    }

    $redirect_to = remove_query_arg(
        array('ial_bulk_processed', 'ial_bulk_skipped', 'ial_bulk_errors', 'ial_wallets_created'),
        $redirect_to
    );

    if (!current_user_can('edit_others_posts')) {
        wp_die(__('Acción no autorizada.', 'ial-royalties'));
    }

    $incoming_note = '';
    if (isset($_REQUEST['ial_bulk_note_content'])) {
        $incoming_note = sanitize_textarea_field(wp_unslash($_REQUEST['ial_bulk_note_content']));
    }

    $is_wallet = in_array($action, $wallet_actions);
    $has_note = in_array($action, array('ial_bulk_note', 'ial_pay_and_note', 'ial_pay_wallet_and_note'));

    $processed = 0;
    $skipped = 0;
    $errors = 0;
    $wallets_created = 0;
    $status_changed = false;

    // --- Non-payment actions ---

    if ('ial_mark_unpaid' === $action) {
        foreach ($post_ids as $post_id) {
            update_post_meta($post_id, 'paid', 0);
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

        $grouped = array();

        foreach ($post_ids as $post_id) {
            if (1 === (int) get_post_meta($post_id, 'paid', true)) {
                $skipped++;
                continue;
            }
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

        foreach ($grouped as $user_id => $record_ids) {

            if ($is_wallet) {
                $result = ial_royalties_process_wallet_payout($user_id, $record_ids);

                if (is_wp_error($result)) {
                    $errors += count($record_ids);
                    continue;
                }

                if (!empty($result['wallet_created'])) {
                    $wallets_created++;
                }

            } else {
                // --- Manual: mark paid without wallet ---
                $today = date('Y-m-d');
                foreach ($record_ids as $post_id) {
                    update_post_meta($post_id, 'paid', 1);
                    update_post_meta($post_id, 'payment_method', 'manual');
                    update_post_meta($post_id, 'paid_date', $today);
                }
            }

            // Add notes if applicable.
            if ($has_note && !empty($incoming_note)) {
                foreach ($record_ids as $post_id) {
                    ial_royalties_append_note($post_id, $incoming_note);
                }
            }

            $processed += count($record_ids);
            $status_changed = true;

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
    if ($wallets_created > 0) {
        $args['ial_wallets_created'] = $wallets_created;
    }

    return add_query_arg($args, $redirect_to);
}
add_filter('handle_bulk_actions-edit-ial_royalty_record', 'ial_royalties_handle_bulk_actions', 10, 3);

// =====================================================================
//  ASSOCIATIONS — Bulk Action: Pay pending royalties to Wallet
// =====================================================================

function ial_royalties_register_assoc_bulk_actions($bulk_actions)
{
    if (function_exists('wsw_credit')) {
        $bulk_actions['ial_assoc_pay_wallet'] = __('Pagar Pendientes a Wallet', 'ial-royalties');
    }
    return $bulk_actions;
}
add_filter('bulk_actions-edit-ial_user_prod_assoc', 'ial_royalties_register_assoc_bulk_actions');

/**
 * Handle "Pay Pending to Wallet" from the Associations list.
 *
 * For each selected association, finds all unpaid royalty records
 * matching that collaborator + product, then processes them through
 * the shared wallet payout helper.
 */
function ial_royalties_handle_assoc_bulk_actions($redirect_to, $action, $post_ids)
{
    if ('ial_assoc_pay_wallet' !== $action) {
        return $redirect_to;
    }

    $redirect_to = remove_query_arg(
        array('ial_bulk_processed', 'ial_bulk_skipped', 'ial_bulk_errors', 'ial_wallets_created'),
        $redirect_to
    );

    if (!current_user_can('edit_others_posts')) {
        wp_die(__('Acción no autorizada.', 'ial-royalties'));
    }

    $processed = 0;
    $no_pending = 0;
    $errors = 0;
    $wallets_created = 0;

    // 1. Collect all unpaid records from the selected associations,
    //    grouped by collaborator.
    $grouped = array(); // user_id => [ record_id, ... ]

    foreach ($post_ids as $assoc_id) {
        $collab_id = (int) get_post_meta($assoc_id, 'collaborator_user', true);
        if (!$collab_id) {
            continue;
        }

        // Get product IDs including variations.
        $product_ids = function_exists('ial_royalties_get_assoc_product_ids')
            ? ial_royalties_get_assoc_product_ids($assoc_id)
            : array((int) get_post_meta($assoc_id, 'product', true));

        if (empty($product_ids)) {
            continue;
        }

        // Query unpaid records for this collaborator + product.
        $unpaid = get_posts(array(
            'post_type'      => 'ial_royalty_record',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'meta_query'     => array(
                array('key' => 'collaborator_user', 'value' => $collab_id, 'compare' => '='),
                array('key' => 'product', 'value' => $product_ids, 'compare' => 'IN'),
                array(
                    'relation' => 'OR',
                    array('key' => 'paid', 'compare' => 'NOT EXISTS'),
                    array('key' => 'paid', 'value' => 1, 'compare' => '!='),
                ),
            ),
        ));

        if (empty($unpaid)) {
            $no_pending++;
            continue;
        }

        if (!isset($grouped[$collab_id])) {
            $grouped[$collab_id] = array();
        }
        $grouped[$collab_id] = array_merge($grouped[$collab_id], $unpaid);
    }

    // Deduplicate (multiple associations for the same collaborator).
    foreach ($grouped as $uid => $rids) {
        $grouped[$uid] = array_unique($rids);
    }

    // 2. Process each collaborator batch.
    foreach ($grouped as $user_id => $record_ids) {

        // Final idempotency filter.
        $record_ids = ial_royalties_filter_unpaid($record_ids, true);

        if (empty($record_ids)) {
            $no_pending++;
            continue;
        }

        $result = ial_royalties_process_wallet_payout($user_id, $record_ids);

        if (is_wp_error($result)) {
            $errors += count($record_ids);
            continue;
        }

        if (!empty($result['wallet_created'])) {
            $wallets_created++;
        }

        $processed += $result['count'];
        do_action('ial_royalty_bulk_paid', $user_id, $record_ids, 'wallet');
    }

    if ($processed > 0 && class_exists('IdeaaLab_Royalties')) {
        IdeaaLab_Royalties::clear_badge_cache();
    }

    $args = array();
    if ($processed > 0) {
        $args['ial_bulk_processed'] = $processed;
    }
    if ($no_pending > 0) {
        $args['ial_bulk_skipped'] = $no_pending;
    }
    if ($errors > 0) {
        $args['ial_bulk_errors'] = $errors;
    }
    if ($wallets_created > 0) {
        $args['ial_wallets_created'] = $wallets_created;
    }

    return add_query_arg($args, $redirect_to);
}
add_filter('handle_bulk_actions-edit-ial_user_prod_assoc', 'ial_royalties_handle_assoc_bulk_actions', 10, 3);

// =====================================================================
//  ADMIN NOTICES (shared by both CPTs)
// =====================================================================

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
            sprintf(esc_html__('%d registros omitidos (ya pagados o sin pendientes).', 'ial-royalties'), $count)
        );
    }
    if (!empty($_REQUEST['ial_bulk_errors'])) {
        $count = intval($_REQUEST['ial_bulk_errors']);
        printf(
            '<div id="message" class="notice notice-error is-dismissible"><p>%s</p></div>',
            sprintf(esc_html__('%d registros no pudieron procesarse (error de wallet).', 'ial-royalties'), $count)
        );
    }
    if (!empty($_REQUEST['ial_wallets_created'])) {
        $count = intval($_REQUEST['ial_wallets_created']);
        printf(
            '<div id="message" class="notice notice-info is-dismissible"><p>%s</p></div>',
            sprintf(esc_html__('Se crearon wallets para %d colaboradores que no tenían.', 'ial-royalties'), $count)
        );
    }
}
add_action('admin_notices', 'ial_royalties_bulk_admin_notice');
