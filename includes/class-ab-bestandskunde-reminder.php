<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sendet eine einmalige Erinnerungs-E-Mail an Bestandskunden,
 * die seit X Tagen im Status "Bestandskunde Vertrag" sind
 * und den Wizard noch nicht abgeschlossen haben.
 *
 * Läuft als täglicher WP-Cron.
 */
class AB_Bestandskunde_Reminder {

    const CRON_HOOK = 'ab_bestandskunde_reminder_check';

    public static function init() {
        // Action Hook registrieren (wird von WP-Cron ODER Action Scheduler aufgerufen)
        add_action(self::CRON_HOOK, [__CLASS__, 'check_and_send_reminders']);

        // Action Scheduler Setup erst ausführen wenn AS bereit ist
        add_action('action_scheduler_init', [__CLASS__, 'schedule_with_action_scheduler']);

        // Fallback: WP-Cron falls Action Scheduler nicht verfügbar
        add_action('init', [__CLASS__, 'maybe_schedule_wp_cron']);

        // Admin: Tools-Seite + Resend-Action
        add_action('admin_menu', [__CLASS__, 'add_admin_page'], 20);
        add_action('admin_post_ab_resend_bestandskunde_reminders', [__CLASS__, 'handle_resend_action']);
    }

    /**
     * Admin-Tools-Seite zum Zurücksetzen und Neu-Senden der Reminder.
     */
    public static function add_admin_page() {
        add_submenu_page(
            'parkourone',
            'Bestandskunden-Reminder',
            'Bestandskunden-Reminder',
            'manage_woocommerce',
            'ab-bestandskunde-reminder',
            [__CLASS__, 'render_admin_page']
        );
    }

    public static function render_admin_page() {
        // Statistik: wie viele Orders sind betroffen?
        $orders = wc_get_orders([
            'status' => ['bkdvertrag'],
            'limit'  => -1,
            'meta_query' => [
                [
                    'key'     => '_ab_bestandskunde_reminder_sent',
                    'value'   => 'yes',
                    'compare' => '=',
                ],
            ],
        ]);

        $affected_count = count($orders);
        $notice = '';
        if (isset($_GET['done'])) {
            $sent = intval($_GET['sent']);
            $reset = intval($_GET['reset']);
            $notice = '<div class="notice notice-success"><p><strong>Fertig:</strong> ' . $reset . ' Reminder-Flags zurückgesetzt, ' . $sent . ' Mails neu versendet.</p></div>';
        }

        ?>
        <div class="wrap">
            <h1>Bestandskunden-Reminder neu senden</h1>
            <?php echo $notice; ?>
            <p>Diese Aktion setzt das "Reminder gesendet"-Flag bei allen Orders im Status <strong>"Bestandskunde Vertrag"</strong> zurück und sendet die 1. Reminder-Mail nochmal sofort.</p>
            <p>Sinnvoll wenn die zuvor gesendeten Mails einen defekten Button hatten und die Kunden den Vertrag noch nicht abgeschlossen haben.</p>

            <h2>Aktuelle Situation</h2>
            <p><strong><?php echo $affected_count; ?></strong> offene Verträge mit bereits gesendetem 1. Reminder.</p>

            <?php if ($affected_count > 0): ?>
            <h3>Betroffene Bestellungen</h3>
            <ul style="max-height:300px;overflow-y:auto;background:#fff;padding:1em;border:1px solid #ccd0d4;">
                <?php foreach ($orders as $o): ?>
                <li>
                    Order #<?php echo $o->get_order_number(); ?> —
                    <?php echo esc_html($o->get_billing_first_name() . ' ' . $o->get_billing_last_name()); ?>
                    (<?php echo esc_html($o->get_billing_email()); ?>)
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:24px;">
                <input type="hidden" name="action" value="ab_resend_bestandskunde_reminders" />
                <?php wp_nonce_field('ab_resend_reminders_nonce'); ?>
                <button type="submit"
                        class="button button-primary"
                        <?php disabled($affected_count, 0); ?>
                        onclick="return confirm('Wirklich <?php echo $affected_count; ?> Reminder-Mails neu senden?');">
                    Reminder neu senden (<?php echo $affected_count; ?>)
                </button>
            </form>
        </div>
        <?php
    }

    /**
     * Handler für den Resend-Button.
     */
    public static function handle_resend_action() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Keine Berechtigung');
        }
        check_admin_referer('ab_resend_reminders_nonce');

        $orders = wc_get_orders([
            'status' => ['bkdvertrag'],
            'limit'  => -1,
            'meta_query' => [
                [
                    'key'     => '_ab_bestandskunde_reminder_sent',
                    'value'   => 'yes',
                    'compare' => '=',
                ],
            ],
        ]);

        $reset = 0;
        $sent = 0;

        foreach ($orders as $order) {
            $order_id = $order->get_id();
            // Flag zurücksetzen
            delete_post_meta($order_id, '_ab_bestandskunde_reminder_sent');
            delete_post_meta($order_id, '_ab_bestandskunde_reminder_date');
            $reset++;

            // Mail sofort neu senden
            $success = self::send_reminder_email($order, 1);
            if ($success) {
                update_post_meta($order_id, '_ab_bestandskunde_reminder_sent', 'yes');
                update_post_meta($order_id, '_ab_bestandskunde_reminder_date', current_time('mysql'));
                $sent++;
                error_log('[AB Reminder] Manuell neu gesendet für Order #' . $order_id);
            }
        }

        wp_safe_redirect(add_query_arg([
            'page'  => 'ab-bestandskunde-reminder',
            'done'  => 1,
            'sent'  => $sent,
            'reset' => $reset,
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * Action Scheduler nutzen (wird aufgerufen wenn AS initialisiert ist)
     */
    public static function schedule_with_action_scheduler() {
        // Alten WP-Cron entfernen falls noch vorhanden
        $wp_cron_ts = wp_next_scheduled(self::CRON_HOOK);
        if ($wp_cron_ts) {
            wp_unschedule_event($wp_cron_ts, self::CRON_HOOK);
        }
        // Täglich um 08:00 Uhr
        if (as_next_scheduled_action(self::CRON_HOOK) === false) {
            $timezone = new DateTimeZone(wp_timezone_string());
            $tomorrow_8am = new DateTime('tomorrow 08:00', $timezone);
            as_schedule_recurring_action(
                $tomorrow_8am->getTimestamp(),
                DAY_IN_SECONDS,
                self::CRON_HOOK,
                [],
                'ab-bestandskunde-reminder'
            );
        }
    }

    /**
     * Fallback: WP-Cron nur wenn Action Scheduler nicht verfügbar
     */
    public static function maybe_schedule_wp_cron() {
        if (function_exists('as_next_scheduled_action')) {
            return; // Action Scheduler übernimmt
        }
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), 'daily', self::CRON_HOOK);
        }
    }

    /**
     * Prüft alle Bestellungen im Status bkdvertrag
     * und sendet Erinnerung wenn X Tage vergangen
     */
    public static function check_and_send_reminders() {
        $options = get_option('ab_email_settings', []);
        $days = isset($options['bestandskunde_reminder_days']) ? intval($options['bestandskunde_reminder_days']) : 0;

        if ($days <= 0) {
            return; // Deaktiviert
        }

        $is_enabled = !empty($options['send_email_bestandskunde_reminder']);
        if (!$is_enabled) {
            return;
        }

        error_log('[AB Reminder] Starte Bestandskunde-Vertrag Reminder Check (Tage: ' . $days . ')');

        // Alle Bestellungen im Status bkdvertrag holen
        $orders = wc_get_orders([
            'status' => 'bkdvertrag',
            'limit'  => -1,
        ]);

        if (empty($orders)) {
            error_log('[AB Reminder] Keine Bestellungen im Status bkdvertrag gefunden.');
            return;
        }

        $sent = 0;
        $skipped = 0;
        $threshold_date = strtotime("-{$days} days");

        foreach ($orders as $order) {
            $order_id = $order->get_id();

            // Wann wurde der Status auf bkdvertrag gesetzt?
            $status_date = self::get_status_change_date($order, 'bkdvertrag');
            if (!$status_date) {
                // Fallback: Bestelldatum
                $status_date = $order->get_date_modified() ?: $order->get_date_created();
            }

            if (!$status_date) {
                $skipped++;
                continue;
            }

            $status_timestamp = $status_date->getTimestamp();

            // 1. Erinnerung
            if (get_post_meta($order_id, '_ab_bestandskunde_reminder_sent', true) !== 'yes') {
                if ($status_timestamp <= $threshold_date) {
                    $success = self::send_reminder_email($order, 1);
                    if ($success) {
                        update_post_meta($order_id, '_ab_bestandskunde_reminder_sent', 'yes');
                        update_post_meta($order_id, '_ab_bestandskunde_reminder_date', current_time('mysql'));
                        $sent++;
                        error_log('[AB Reminder] 1. Erinnerung gesendet für Order #' . $order_id);
                    }
                }
                continue;
            }

            // 2. Erinnerung
            $days_2 = isset($options['bestandskunde_reminder_2_days']) ? intval($options['bestandskunde_reminder_2_days']) : 0;
            $is_enabled_2 = !empty($options['send_email_bestandskunde_reminder_2']);

            if ($days_2 > 0 && $is_enabled_2 && get_post_meta($order_id, '_ab_bestandskunde_reminder_2_sent', true) !== 'yes') {
                $threshold_date_2 = strtotime("-{$days_2} days");
                if ($status_timestamp <= $threshold_date_2) {
                    $success = self::send_reminder_email($order, 2);
                    if ($success) {
                        update_post_meta($order_id, '_ab_bestandskunde_reminder_2_sent', 'yes');
                        update_post_meta($order_id, '_ab_bestandskunde_reminder_2_date', current_time('mysql'));
                        $sent++;
                        error_log('[AB Reminder] 2. Erinnerung gesendet für Order #' . $order_id);
                    }
                }
            } else {
                $skipped++;
            }
        }

        error_log("[AB Reminder] Fertig: $sent gesendet, $skipped übersprungen.");
    }

    /**
     * Datum des letzten Status-Wechsels zu einem bestimmten Status aus den Order Notes holen
     */
    private static function get_status_change_date($order, $target_status) {
        $notes = wc_get_order_notes([
            'order_id' => $order->get_id(),
            'type'     => 'internal',
        ]);

        foreach ($notes as $note) {
            if (stripos($note->content, $target_status) !== false || stripos($note->content, 'Bestandskunde Vertrag') !== false) {
                return new WC_DateTime($note->date_created->date('Y-m-d H:i:s'));
            }
        }

        return null;
    }

    /**
     * Erinnerungs-E-Mail senden (nutzt die gleiche Infrastruktur wie alle anderen Mails)
     * Public damit der E-Mail-Tester sie aufrufen kann.
     */
    public static function send_reminder_email($order, $reminder_number = 1) {
        $options = get_option('ab_email_settings', []);

        $to = $order->get_billing_email();
        if (empty($to)) {
            return false;
        }

        // E-Mail-Inhalte je nach Erinnerungsnummer
        if ($reminder_number === 2) {
            $subject = $options['subject_bestandskunde_reminder_2'] ?? 'Letzte Erinnerung: Vertrag noch offen';
            $header_text = $options['header_bestandskunde_reminder_2'] ?? 'Vertrag noch immer offen';
            $content = $options['content_bestandskunde_reminder_2'] ?? '';
        } else {
            $subject = $options['subject_bestandskunde_reminder'] ?? 'Erinnerung: Bitte Vertrag abschließen';
            $header_text = $options['header_bestandskunde_reminder'] ?? 'Vertrag noch offen';
            $content = $options['content_bestandskunde_reminder'] ?? '';
        }

        // Fallback-Template nur für 1. Erinnerung — 2. Erinnerung MUSS konfiguriert sein
        if (empty($content) && $reminder_number === 1) {
            $content = '<!-- Logo -->
<div style="font-family: Arial, sans-serif; color: #333;">
<div style="text-align: center; margin-bottom: 20px;">
&nbsp;
<img style="max-width: 300px; height: auto;" src="' . esc_url($options['logo_url'] ?? '') . '" alt="ParkourONE Logo" />
&nbsp;
</div>
<div style="font-family: Arial, sans-serif; color: #333; padding: 20px; max-width: 600px; margin: 0 auto;">
<p style="text-align: left;">Hallo {first_name},</p>
<p style="text-align: left;">vor einigen Tagen haben wir dir einen neuen Vertrag zugeschickt, den du noch nicht abgeschlossen hast.</p>
<p style="text-align: left;">Damit alles seine Ordnung hat und du weiterhin am Training teilnehmen kannst, bitten wir dich den Vertrag zeitnah abzuschliessen:</p>
<div style="text-align: center; margin: 20px 0;">
<a style="display: inline-block; background-color: #0066cc; color: #fff; padding: 12px 24px; text-decoration: none; border-radius: 5px; font-size: 16px;" href="{contract_link}">Vertrag jetzt abschliessen</a>
</div>
<p style="text-align: left;">Falls du Fragen hast oder Hilfe benötigst, melde dich gerne jederzeit bei uns.</p>
<p style="margin-top: 20px; text-align: left;">ONE for All &amp; All for ONE<br>Viele Grüsse,</p>
<strong>Dein Team von ParkourONE</strong>
</div>
<div style="border-top: 1px solid #ddd; margin: 20px 0;"></div>
</div>';
        } elseif (empty($content) && $reminder_number === 2) {
            // 2. Erinnerung ohne konfigurierten Inhalt → NICHT senden, Admin benachrichtigen
            error_log('[AB Reminder] FEHLER: 2. Erinnerung für Order #' . $order->get_id() . ' hat keinen E-Mail-Inhalt! Bitte unter Einstellungen > E-Mails den Inhalt der 2. Erinnerung konfigurieren.');
            $order->add_order_note('⚠️ 2. Erinnerungsmail konnte NICHT gesendet werden — E-Mail-Inhalt ist nicht konfiguriert. Bitte unter Einstellungen > E-Mails den Text für die 2. Bestandskunde-Erinnerung eintragen.');
            self::notify_admin_missing_template($order);
            return false;
        }

        // Platzhalter ersetzen
        $first_name = $order->get_billing_first_name();
        $last_name = $order->get_billing_last_name();
        $order_number = $order->get_order_number();

        $replacements = [
            '{first_name}'   => $first_name,
            '{last_name}'    => $last_name,
            '{order_number}' => $order_number,
            '{status}'       => 'Bestandskunde Vertrag',
        ];

        $subject = str_replace(array_keys($replacements), array_values($replacements), $subject);
        $content = str_replace(array_keys($replacements), array_values($replacements), $content);

        // Globale Order-Variable setzen damit alle Shortcodes
        // ([contract_link], [first_participant_first_name], etc.) funktionieren
        global $ab_current_order;
        $previous_order = $ab_current_order;
        $ab_current_order = $order;

        // Legacy-Platzhalter {contract_link} weiter unterstützen
        $content = do_shortcode(str_replace('{contract_link}', '[contract_link order_id="' . $order->get_id() . '"]', $content));

        // Globale Variable wiederherstellen
        $ab_current_order = $previous_order;

        // Absender
        $sender_email = $options['sender_email'] ?? get_option('admin_email');
        $sender_name = $options['sender_name'] ?? get_option('blogname');

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $sender_name . ' <' . $sender_email . '>',
        ];

        // E-Mail-Template laden (gleiche Struktur wie andere Status-Mails)
        $email_body = self::build_email_html($header_text, $content, $options);

        $success = wp_mail($to, $subject, $email_body, $headers);

        if ($success) {
            $label = $reminder_number === 2 ? '2. Erinnerungs-E-Mail' : '1. Erinnerungs-E-Mail';
            $order->add_order_note(
                $label . ' gesendet (Bestandskunde Vertrag noch offen).'
            );
        }

        return $success;
    }

    /**
     * Einfaches HTML-E-Mail-Template
     */
    private static function build_email_html($header, $content, $options) {
        $logo_url = $options['logo_url'] ?? '';

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0; padding:0; background:#f5f5f5;">';
        $html .= '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5; padding:20px;">';
        $html .= '<tr><td align="center">';
        $html .= '<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff; border-radius:8px; overflow:hidden;">';

        // Logo
        if ($logo_url) {
            $html .= '<tr><td style="padding:20px; text-align:center; background:#1e3d59;">';
            $html .= '<img src="' . esc_url($logo_url) . '" alt="Logo" style="max-width:200px; height:auto;">';
            $html .= '</td></tr>';
        }

        // Header
        $html .= '<tr><td style="padding:30px 30px 10px; font-family:Arial,sans-serif;">';
        $html .= '<h1 style="margin:0; font-size:24px; color:#1e3d59;">' . esc_html($header) . '</h1>';
        $html .= '</td></tr>';

        // Content
        $html .= '<tr><td style="padding:10px 30px 30px; font-family:Arial,sans-serif; font-size:15px; line-height:1.6; color:#333;">';
        $html .= wp_kses_post($content);
        $html .= '</td></tr>';

        $html .= '</table></td></tr></table></body></html>';

        return $html;
    }

    /**
     * Admin per E-Mail benachrichtigen wenn 2. Erinnerungstemplate fehlt
     */
    private static function notify_admin_missing_template($order) {
        $admin_email = get_option('admin_email');
        $subject = '[ParkourONE] 2. Erinnerungsmail konnte nicht gesendet werden';
        $body = sprintf(
            "Die 2. Erinnerungsmail für Bestellung #%s (%s %s) konnte nicht gesendet werden, weil der E-Mail-Inhalt nicht konfiguriert ist.\n\n" .
            "Bitte gehe zu Einstellungen > E-Mails > Bestandskunde Vertrag — 2. Erinnerung und trage den E-Mail-Text ein.\n\n" .
            "Solange der Inhalt fehlt, wird die 2. Erinnerung für KEINE Bestellung verschickt.",
            $order->get_order_number(),
            $order->get_billing_first_name(),
            $order->get_billing_last_name()
        );
        wp_mail($admin_email, $subject, $body);
    }

    /**
     * Cron deaktivieren (bei Plugin-Deaktivierung)
     */
    public static function deactivate() {
        // WP-Cron aufräumen
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
        // Action Scheduler aufräumen
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::CRON_HOOK);
        }
    }
}
