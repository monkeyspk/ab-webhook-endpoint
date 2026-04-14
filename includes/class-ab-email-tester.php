<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin-Tool zum Testen aller Status-Mails ohne tatsächlichen Statuswechsel.
 * Sendet eine ausgewählte Status-Mail mit den Daten einer Beispiel-Order
 * an eine frei wählbare Empfänger-Adresse.
 */
class AB_Email_Tester {

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_admin_page'], 25);
        add_action('admin_post_ab_send_test_email', [__CLASS__, 'handle_test_send']);
        add_action('admin_post_ab_send_all_test_emails', [__CLASS__, 'handle_test_send_all']);
    }

    public static function add_admin_page() {
        add_submenu_page(
            'parkourone',
            'E-Mail Tester',
            'E-Mail Tester',
            'manage_woocommerce',
            'ab-email-tester',
            [__CLASS__, 'render_admin_page']
        );
    }

    public static function render_admin_page() {
        $statuses = AB_Custom_Statuses::get_custom_statuses();
        $email_settings = get_option('ab_email_settings', []);
        $current_user = wp_get_current_user();
        $default_email = $current_user->user_email;

        // Vorgewählte Werte aus URL
        $selected_email = isset($_GET['recipient']) ? sanitize_email($_GET['recipient']) : $default_email;
        $selected_order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : self::find_sample_order_id();

        // Notice nach Send
        $notice = '';
        if (isset($_GET['result'])) {
            if ($_GET['result'] === 'success') {
                $count = isset($_GET['count']) ? intval($_GET['count']) : 1;
                $notice = '<div class="notice notice-success"><p><strong>' . $count . ' Test-Mail(s) erfolgreich versendet</strong> an <code>' . esc_html($_GET['to'] ?? '') . '</code>.</p></div>';
            } elseif ($_GET['result'] === 'error') {
                $notice = '<div class="notice notice-error"><p><strong>Fehler:</strong> ' . esc_html($_GET['msg'] ?? 'Unbekannter Fehler') . '</p></div>';
            }
        }
        ?>
        <div class="wrap">
            <h1>Status-E-Mails testen</h1>
            <?php echo $notice; ?>

            <p>Sendet eine Status-E-Mail mit den Daten einer realen Beispiel-Order an eine beliebige Empfängeradresse — ohne den Order-Status tatsächlich zu ändern.</p>

            <h2>Konfiguration</h2>
            <table class="form-table">
                <tr>
                    <th><label for="ab-test-recipient">Empfänger E-Mail</label></th>
                    <td>
                        <input type="email" id="ab-test-recipient" value="<?php echo esc_attr($selected_email); ?>" class="regular-text" />
                        <p class="description">An diese Adresse werden alle Test-Mails verschickt.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="ab-test-order">Beispiel-Order ID</label></th>
                    <td>
                        <input type="number" id="ab-test-order" value="<?php echo esc_attr($selected_order_id); ?>" class="small-text" />
                        <button type="button" class="button" id="ab-test-update">Aktualisieren</button>
                        <p class="description">Aus dieser Order werden Teilnehmerdaten, Event-Infos etc. für die Mail-Vorschau verwendet.<br>
                        <?php if ($selected_order_id):
                            $sample_order = wc_get_order($selected_order_id);
                            if ($sample_order):
                        ?>
                            Aktuelle Auswahl: <strong>Order #<?php echo $sample_order->get_order_number(); ?></strong> —
                            <?php echo esc_html($sample_order->get_billing_first_name() . ' ' . $sample_order->get_billing_last_name()); ?>
                            (<?php echo esc_html($sample_order->get_billing_email()); ?>)
                        <?php else: ?>
                            <span style="color:#d63638;">Order #<?php echo $selected_order_id; ?> nicht gefunden!</span>
                        <?php endif; endif; ?>
                        </p>
                    </td>
                </tr>
            </table>

            <script>
            document.getElementById('ab-test-update').addEventListener('click', function() {
                var url = new URL(window.location.href);
                url.searchParams.set('recipient', document.getElementById('ab-test-recipient').value);
                url.searchParams.set('order_id', document.getElementById('ab-test-order').value);
                url.searchParams.delete('result');
                url.searchParams.delete('msg');
                url.searchParams.delete('to');
                url.searchParams.delete('count');
                window.location.href = url.toString();
            });
            </script>

            <h2>Status-E-Mails</h2>
            <p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                    <input type="hidden" name="action" value="ab_send_all_test_emails" />
                    <input type="hidden" name="recipient" value="<?php echo esc_attr($selected_email); ?>" />
                    <input type="hidden" name="order_id" value="<?php echo esc_attr($selected_order_id); ?>" />
                    <?php wp_nonce_field('ab_test_email_nonce'); ?>
                    <button type="submit" class="button button-primary"
                            onclick="return confirm('Wirklich ALLE konfigurierten Status-Mails senden?');">
                        Alle aktivierten Mails auf einmal senden
                    </button>
                </form>
            </p>

            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th>Status</th>
                        <th>Status-Schlüssel</th>
                        <th>Aktiviert</th>
                        <th>Betreff</th>
                        <th>Aktion</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($statuses as $wc_status => $label):
                    $status_key = str_replace('wc-', '', $wc_status);
                    $is_enabled = !empty($email_settings['send_email_' . $status_key]);
                    $subject = $email_settings['subject_' . $status_key] ?? '<em style="color:#999;">(kein Betreff konfiguriert)</em>';
                ?>
                    <tr>
                        <td><strong><?php echo esc_html($label); ?></strong></td>
                        <td><code><?php echo esc_html($status_key); ?></code></td>
                        <td>
                            <?php if ($is_enabled): ?>
                                <span style="color:#00a32a;">✓ Ja</span>
                            <?php else: ?>
                                <span style="color:#d63638;">✗ Nein</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo wp_kses_post($subject); ?></td>
                        <td>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                <input type="hidden" name="action" value="ab_send_test_email" />
                                <input type="hidden" name="status" value="<?php echo esc_attr($wc_status); ?>" />
                                <input type="hidden" name="recipient" value="<?php echo esc_attr($selected_email); ?>" />
                                <input type="hidden" name="order_id" value="<?php echo esc_attr($selected_order_id); ?>" />
                                <?php wp_nonce_field('ab_test_email_nonce'); ?>
                                <button type="submit" class="button"
                                    <?php if (!$is_enabled || !$selected_order_id) echo 'disabled'; ?>>
                                    Test senden
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h2 style="margin-top:32px;">Hinweise</h2>
            <ul style="list-style:disc;margin-left:24px;">
                <li>Test-Mails werden an die oben angegebene Empfänger-Adresse versendet, NICHT an die Billing-E-Mail der Order.</li>
                <li>Der Order-Status wird NICHT geändert.</li>
                <li>Alle Shortcodes ([first_participant_first_name], [contract_link], etc.) werden mit den Daten der Beispiel-Order aufgelöst.</li>
                <li>PDF-Anhänge (bei "Schüler_in" / "Bestandskunde akzeptiert") werden mitgesendet wenn vorhanden.</li>
                <li>Status-Mails die im Customizer deaktiviert sind, können hier nicht getestet werden.</li>
            </ul>
        </div>
        <?php
    }

    /**
     * Findet eine sinnvolle Beispiel-Order (neueste Order mit Teilnehmerdaten).
     */
    private static function find_sample_order_id() {
        $orders = wc_get_orders([
            'limit'   => 1,
            'orderby' => 'date',
            'order'   => 'DESC',
            'status'  => ['schuelerin', 'probetraining', 'bestandkundeakz', 'completed', 'processing'],
        ]);
        return !empty($orders) ? $orders[0]->get_id() : 0;
    }

    /**
     * Handler: Eine einzelne Test-Mail senden.
     */
    public static function handle_test_send() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Keine Berechtigung');
        }
        check_admin_referer('ab_test_email_nonce');

        $status = sanitize_text_field($_POST['status'] ?? '');
        $recipient = sanitize_email($_POST['recipient'] ?? '');
        $order_id = intval($_POST['order_id'] ?? 0);

        $result = self::send_test_email($status, $order_id, $recipient);

        $args = [
            'page'      => 'ab-email-tester',
            'recipient' => $recipient,
            'order_id'  => $order_id,
        ];
        if ($result === true) {
            $args['result'] = 'success';
            $args['count']  = 1;
            $args['to']     = $recipient;
        } else {
            $args['result'] = 'error';
            $args['msg']    = $result;
        }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    /**
     * Handler: Alle aktivierten Status-Mails als Test senden.
     */
    public static function handle_test_send_all() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Keine Berechtigung');
        }
        check_admin_referer('ab_test_email_nonce');

        $recipient = sanitize_email($_POST['recipient'] ?? '');
        $order_id = intval($_POST['order_id'] ?? 0);

        $statuses = AB_Custom_Statuses::get_custom_statuses();
        $email_settings = get_option('ab_email_settings', []);
        $sent = 0;

        foreach ($statuses as $wc_status => $label) {
            $status_key = str_replace('wc-', '', $wc_status);
            if (empty($email_settings['send_email_' . $status_key])) {
                continue;
            }
            $result = self::send_test_email($wc_status, $order_id, $recipient);
            if ($result === true) {
                $sent++;
            }
        }

        wp_safe_redirect(add_query_arg([
            'page'      => 'ab-email-tester',
            'recipient' => $recipient,
            'order_id'  => $order_id,
            'result'    => 'success',
            'count'     => $sent,
            'to'        => $recipient,
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * Sendet eine Test-Mail. Umgeht die Skip-Marker und Duplikat-Checks
     * von send_status_email(), damit die Mail beliebig oft gesendet werden kann.
     * Nutzt die identische Render-Logik wie produktiver Versand.
     *
     * @return true|string True bei Erfolg, Fehlermeldung bei Fehler.
     */
    public static function send_test_email($wc_status, $order_id, $recipient) {
        if (empty($recipient) || !is_email($recipient)) {
            return 'Ungültige Empfänger-Adresse';
        }
        if (empty($order_id)) {
            return 'Keine Order-ID angegeben';
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return 'Order #' . $order_id . ' nicht gefunden';
        }

        // Skip- und Sent-Marker temporär setzen damit send_status_email durchläuft
        // und keine bleibenden Spuren hinterlässt.
        $status_key = str_replace('wc-', '', $wc_status);
        $email_sent_key = '_ab_email_sent_' . $status_key;
        $previous_sent = get_post_meta($order_id, $email_sent_key, true);

        // Duplikat-Check temporär aushebeln
        delete_post_meta($order_id, $email_sent_key);
        // Skip-Marker entfernen falls gesetzt
        $had_skip = get_post_meta($order_id, '_ab_skip_probetraining_email', true) === 'yes';
        delete_post_meta($order_id, '_ab_skip_probetraining_email');

        // Empfänger-Override via Filter: wir überschreiben die billing_email
        // temporär, damit die Mail an die Test-Adresse geht statt an den Kunden.
        $override_filter = function($args) use ($recipient) {
            $args['to'] = $recipient;
            // Subject mit [TEST] markieren
            $args['subject'] = '[TEST] ' . $args['subject'];
            return $args;
        };
        add_filter('wp_mail', $override_filter, 999);

        $success = false;
        try {
            // Spezialfall Schüler_in / Bestandkunde akzeptiert: contract_status muss 'completed' sein
            $had_contract_status = get_post_meta($order_id, '_contract_status', true);
            if (($wc_status === 'wc-schuelerin' || $wc_status === 'wc-bestandkundeakz') && $had_contract_status !== 'completed') {
                update_post_meta($order_id, '_contract_status', 'completed');
            }

            $success = AB_Email_Sender::send_status_email($order_id, $wc_status);

            // contract_status wiederherstellen falls wir es geändert haben
            if (($wc_status === 'wc-schuelerin' || $wc_status === 'wc-bestandkundeakz') && $had_contract_status !== 'completed') {
                if ($had_contract_status === '') {
                    delete_post_meta($order_id, '_contract_status');
                } else {
                    update_post_meta($order_id, '_contract_status', $had_contract_status);
                }
            }
        } finally {
            remove_filter('wp_mail', $override_filter, 999);

            // Duplikat-Marker wiederherstellen damit produktiver Versand nicht erneut feuert
            if ($previous_sent === 'yes') {
                update_post_meta($order_id, $email_sent_key, 'yes');
            } else {
                // Auch das Marker das durch den Test gesetzt wurde entfernen
                delete_post_meta($order_id, $email_sent_key);
            }
            // Skip-Marker wiederherstellen
            if ($had_skip) {
                update_post_meta($order_id, '_ab_skip_probetraining_email', 'yes');
            }
        }

        return $success ? true : 'Versand fehlgeschlagen — siehe error_log für Details';
    }
}

AB_Email_Tester::init();
