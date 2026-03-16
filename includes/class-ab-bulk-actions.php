<?php
if (!defined('ABSPATH')) {
    exit;
}

class AB_Bulk_Actions {

    public static function init() {
        add_filter('bulk_actions-edit-shop_order', [__CLASS__, 'add_bulk_actions']);
        add_action('handle_bulk_actions-edit-shop_order', [__CLASS__, 'handle_bulk_actions'], 10, 3);
        add_action('admin_notices', [__CLASS__, 'bulk_action_success_notice']);

        // Row-Action "E-Mail erneut senden" in der Bestellungsliste
        add_filter('woocommerce_admin_order_actions', [__CLASS__, 'add_resend_email_action'], 10, 2);
        add_action('admin_init', [__CLASS__, 'handle_resend_email_action']);
        add_action('admin_notices', [__CLASS__, 'resend_email_notice']);
        add_action('admin_head', [__CLASS__, 'add_resend_email_styles']);
    }

    public static function add_bulk_actions($bulk_actions) {
        // Für jeden Custom-Status eine neue "mark_xyz"-Aktion
        $statuses = AB_Custom_Statuses::get_custom_statuses();
        foreach ($statuses as $slug => $label) {
            $bulk_actions['mark_' . substr($slug, 3)] = sprintf(__('Mark as %s', 'woocommerce'), $label);
        }
        return $bulk_actions;
    }

    public static function handle_bulk_actions($redirect_to, $action, $post_ids) {
        if (strpos($action, 'mark_') !== 0) {
            return $redirect_to;
        }

        $new_status = 'wc-' . substr($action, 5);

        foreach ($post_ids as $post_id) {
            $order = wc_get_order($post_id);
            if ($order) {
                $order->update_status($new_status, 'Status via Bulk-Aktion aktualisiert.');
                // E-Mail
                AB_Email_Sender::send_status_email($post_id, $new_status);
            }
        }

        $redirect_to = add_query_arg('bulk_action_success', count($post_ids), $redirect_to);
        return $redirect_to;
    }

    public static function bulk_action_success_notice() {
        if (empty($_REQUEST['bulk_action_success'])) {
            return;
        }
        printf(
            '<div id="message" class="updated fade"><p>' .
                _n('Ein Auftrag wurde aktualisiert.', '%s Aufträge wurden aktualisiert.', intval($_REQUEST['bulk_action_success']), 'woocommerce') .
                '</p></div>',
            intval($_REQUEST['bulk_action_success'])
        );
    }

    /**
     * Fügt "E-Mail erneut senden" Button zur Order-Zeile hinzu
     */
    public static function add_resend_email_action($actions, $order) {
        // Nur für unsere Custom-Status anzeigen
        $custom_statuses = AB_Custom_Statuses::get_custom_statuses();
        $current_status = 'wc-' . $order->get_status();

        if (isset($custom_statuses[$current_status])) {
            $resend_url = wp_nonce_url(
                admin_url('admin.php?action=ab_resend_status_email&order_id=' . $order->get_id()),
                'ab_resend_email_' . $order->get_id()
            );

            $actions['resend_email'] = [
                'url'    => $resend_url,
                'name'   => 'E-Mail senden',
                'action' => 'resend_email',
            ];
        }

        return $actions;
    }

    /**
     * Verarbeitet den "E-Mail erneut senden" Klick
     */
    public static function handle_resend_email_action() {
        if (!isset($_GET['action']) || $_GET['action'] !== 'ab_resend_status_email') {
            return;
        }

        if (!isset($_GET['order_id'])) {
            return;
        }

        $order_id = absint($_GET['order_id']);

        // Nonce prüfen
        if (!wp_verify_nonce($_GET['_wpnonce'], 'ab_resend_email_' . $order_id)) {
            wp_die('Sicherheitsprüfung fehlgeschlagen');
        }

        // Berechtigung prüfen
        if (!current_user_can('edit_shop_orders')) {
            wp_die('Keine Berechtigung');
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_redirect(admin_url('edit.php?post_type=shop_order&ab_resend_error=not_found'));
            exit;
        }

        $current_status = 'wc-' . $order->get_status();
        $status_key = str_replace('wc-', '', $current_status);

        // E-Mail-Marker für diesen Status löschen (ermöglicht erneutes Senden)
        delete_post_meta($order_id, '_ab_email_sent_' . $status_key);

        // E-Mail senden
        $sent = AB_Email_Sender::send_status_email($order_id, $current_status);

        // Logging
        error_log('[AB Status Plugin] Admin hat E-Mail erneut gesendet für Order #' . $order_id . ' mit Status ' . $current_status . ' - Ergebnis: ' . ($sent ? 'erfolgreich' : 'fehlgeschlagen'));

        // Order-Notiz hinzufügen
        $order->add_order_note(
            sprintf('E-Mail für Status "%s" wurde manuell erneut gesendet von %s.',
                $status_key,
                wp_get_current_user()->display_name
            )
        );

        // Redirect zurück zur Bestellungsliste
        $redirect_url = add_query_arg([
            'post_type' => 'shop_order',
            'ab_resend_result' => $sent ? 'success' : 'failed',
            'ab_resend_order' => $order_id
        ], admin_url('edit.php'));

        wp_redirect($redirect_url);
        exit;
    }

    /**
     * Zeigt Erfolg/Fehler-Meldung nach E-Mail-Versand
     */
    public static function resend_email_notice() {
        if (!isset($_GET['ab_resend_result'])) {
            return;
        }

        $result = sanitize_text_field($_GET['ab_resend_result']);
        $order_id = isset($_GET['ab_resend_order']) ? absint($_GET['ab_resend_order']) : 0;

        if ($result === 'success') {
            printf(
                '<div class="notice notice-success is-dismissible"><p>E-Mail für Bestellung #%d wurde erfolgreich erneut gesendet.</p></div>',
                $order_id
            );
        } elseif ($result === 'failed') {
            printf(
                '<div class="notice notice-error is-dismissible"><p>E-Mail für Bestellung #%d konnte nicht gesendet werden. Bitte Logs prüfen.</p></div>',
                $order_id
            );
        }
    }

    /**
     * CSS für den E-Mail-Button in der Bestellungsliste
     */
    public static function add_resend_email_styles() {
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'edit-shop_order') {
            return;
        }
        ?>
        <style>
            .wc-action-button-resend_email::after {
                font-family: 'Dashicons' !important;
                content: '\f466' !important; /* dashicons-email-alt */
                color: #fff !important;
            }
            .wc-action-button-resend_email {
                background: #2271b1 !important;
            }
            .wc-action-button-resend_email:hover {
                background: #135e96 !important;
            }
        </style>
        <?php
    }
}
