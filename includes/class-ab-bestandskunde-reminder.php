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
        // Cron-Hook registrieren
        add_action(self::CRON_HOOK, [__CLASS__, 'check_and_send_reminders']);

        // Täglichen Cron einplanen falls noch nicht vorhanden
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

            // Bereits Erinnerung gesendet?
            if (get_post_meta($order_id, '_ab_bestandskunde_reminder_sent', true) === 'yes') {
                $skipped++;
                continue;
            }

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

            // Ist die Wartezeit abgelaufen?
            if ($status_timestamp > $threshold_date) {
                continue; // Noch nicht lange genug
            }

            // Erinnerung senden
            $success = self::send_reminder_email($order);
            if ($success) {
                update_post_meta($order_id, '_ab_bestandskunde_reminder_sent', 'yes');
                update_post_meta($order_id, '_ab_bestandskunde_reminder_date', current_time('mysql'));
                $sent++;
                error_log('[AB Reminder] Erinnerung gesendet für Order #' . $order_id);
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
     */
    private static function send_reminder_email($order) {
        $options = get_option('ab_email_settings', []);

        $to = $order->get_billing_email();
        if (empty($to)) {
            return false;
        }

        // E-Mail-Inhalte aus den Einstellungen
        $subject = $options['subject_bestandskunde_reminder'] ?? 'Erinnerung: Bitte Vertrag abschließen';
        $header_text = $options['header_bestandskunde_reminder'] ?? 'Vertrag noch offen';
        $content = $options['content_bestandskunde_reminder'] ?? '';

        if (empty($content)) {
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

        // Shortcodes im Content verarbeiten (für [contract_link] etc.)
        $content = do_shortcode(str_replace('{contract_link}', '[contract_link order_id="' . $order->get_id() . '"]', $content));

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
            $order->add_order_note(sprintf(
                'Erinnerungs-E-Mail gesendet (Bestandskunde Vertrag noch offen).'
            ));
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
     * Cron deaktivieren (bei Plugin-Deaktivierung)
     */
    public static function deactivate() {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }
}
