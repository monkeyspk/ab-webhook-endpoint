<?php
if (!defined('ABSPATH')) {
    exit;
}

class AB_Gutschein_Email {

    /**
     * Default-Content fuer Gutschein-Mail an Empfaenger.
     * Wird im Customizer als Vorbelegung angezeigt und als Fallback genutzt.
     */
    public static function get_default_content_gutschein() {
        return '<p style="text-align: left;">{gutschein_nachricht_block}</p>

<!-- Gutschein-Karte -->
<div style="background-color: #1e3d59; border-radius: 12px; padding: 30px; text-align: center; margin: 20px 0;">
    <p style="color: rgba(255,255,255,0.7); font-size: 14px; margin: 0 0 5px; text-transform: uppercase; letter-spacing: 2px;">Gutschein</p>
    <p style="color: #ffffff; font-size: 48px; font-weight: 700; margin: 0 0 15px;">[ab_gutschein_wert]</p>
    <div style="background: rgba(255,255,255,0.15); display: inline-block; padding: 10px 20px; border-radius: 6px;">
        <span style="color: #ffffff; font-size: 24px; font-weight: 600; letter-spacing: 3px; font-family: \'Courier New\', Courier, monospace;">[ab_gutschein_code]</span>
    </div>
    <p style="color: rgba(255,255,255,0.7); font-size: 13px; margin: 15px 0 0;">G&uuml;ltig bis: [ab_gutschein_ablauf]</p>
</div>

<p style="text-align: left;">Gib den Code einfach bei deiner n&auml;chsten Buchung auf <a href="https://berlin.parkourone.com" style="color: #0066cc; text-decoration: none;">berlin.parkourone.com</a> im Warenkorb ein. Der Gutschein ist teilweise einl&ouml;sbar &ndash; du kannst ihn auch auf mehrere Buchungen aufteilen.</p>

<p style="margin-top: 20px; text-align: left;">ONE for All &amp; All for ONE<br>Viele Gr&uuml;&szlig;e,</p>
<p><strong>Dein Team von <a href="https://berlin.parkourone.com" style="color: #333; text-decoration: none;">ParkourONE Berlin</a></strong></p>';
    }

    /**
     * Default-Content fuer Kaeufer-Bestaetigung.
     */
    public static function get_default_content_gutschein_buyer() {
        return '<p style="text-align: left;">Hallo {first_name},</p>

<p style="text-align: left;">dein Gutschein &uuml;ber <strong>[ab_gutschein_wert]</strong> wurde erfolgreich erstellt.</p>

<p style="text-align: left;">Der Gutschein-Code <strong style="font-family: \'Courier New\', Courier, monospace; letter-spacing: 1px;">[ab_gutschein_code]</strong> wurde an <strong>[ab_gutschein_empfaenger]</strong> gesendet.</p>

<p style="text-align: left;">Der Gutschein ist g&uuml;ltig bis: <strong>[ab_gutschein_ablauf]</strong></p>

<p style="margin-top: 20px; text-align: left;">ONE for All &amp; All for ONE<br>Viele Gr&uuml;&szlig;e,</p>
<p><strong>Dein Team von <a href="https://berlin.parkourone.com" style="color: #333; text-decoration: none;">ParkourONE Berlin</a></strong></p>';
    }

    /**
     * Sendet die Gutschein-E-Mail nach Coupon-Generierung.
     * Alle E-Mail-Settings kommen aus dem AB Email Customizer (ab_email_settings).
     */
    public static function send_gutschein_email($order, $coupon_code, $amount, $expiry_date, $recipient_email, $message, $sender_name) {
        $order_id = $order->get_id();

        // Duplikat-Schutz
        $email_sent = $order->get_meta('_ab_gutschein_email_sent');
        if ($email_sent === 'yes') {
            error_log('[AB Gutschein] E-Mail bereits gesendet fuer Order #' . $order_id);
            return false;
        }

        // E-Mail-Settings aus dem AB Email Customizer
        $email_settings = get_option('ab_email_settings', []);

        // Pruefen ob E-Mail aktiviert ist
        if (empty($email_settings['send_email_gutschein'])) {
            error_log('[AB Gutschein] Gutschein-Mail ist deaktiviert im Email Customizer.');
            return false;
        }

        // Absender (globale Einstellungen aus Email Customizer)
        $sender_email_addr = !empty($email_settings['sender_email']) ? $email_settings['sender_email'] : get_option('admin_email');
        $sender_name_from = !empty($email_settings['sender_name']) ? $email_settings['sender_name'] : get_bloginfo('name');

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $sender_name_from . ' <' . $sender_email_addr . '>',
            'Reply-To: ' . $sender_name_from . ' <' . $sender_email_addr . '>',
        ];

        // Betreff aus Email Customizer (mit Fallback)
        $subject = !empty($email_settings['subject_gutschein']) ? $email_settings['subject_gutschein'] : 'Dein Parkour ONE Gutschein';

        // Content aus Email Customizer (mit Default-Fallback)
        $email_content = !empty($email_settings['content_gutschein']) ? $email_settings['content_gutschein'] : self::get_default_content_gutschein();

        // Nachricht-Block aufbauen (nur wenn Nachricht vorhanden)
        $nachricht_block = '';
        if (!empty($message)) {
            $nachricht_block = '<p style="text-align: left;">Nachricht von ' . esc_html($sender_name) . ':</p>'
                . '<div style="background: #f8f9fa; border-left: 4px solid #1e3d59; padding: 15px 20px; margin: 0 0 20px; border-radius: 0 4px 4px 0;">'
                . '<p style="margin: 0; color: #333; font-size: 16px; font-style: italic;">'
                . '&bdquo;' . nl2br(esc_html($message)) . '&ldquo;</p></div>';
        }
        $email_content = str_replace('{gutschein_nachricht_block}', $nachricht_block, $email_content);

        // Shortcodes + Platzhalter aufloesen (gleicher Flow wie AB_Email_Sender)
        global $ab_current_order;
        $ab_current_order = $order;

        $subject = AB_Email_Customizer::replace_variables($subject, $order, 'Gutschein');
        $subject = do_shortcode($subject);

        $email_content = AB_Email_Customizer::replace_variables($email_content, $order, 'Gutschein');
        $email_content = do_shortcode($email_content);
        $email_content = apply_filters('ab_process_email_content', $email_content, $order);

        $ab_current_order = null;

        // Empfaenger bestimmen
        $to = !empty($recipient_email) ? $recipient_email : $order->get_billing_email();

        // Template rendern (Logo + Content + Footer)
        $email_body = $email_content;
        ob_start();
        $template_path = plugin_dir_path(__FILE__) . '../templates/emails/gutschein-email.php';
        if (!file_exists($template_path)) {
            error_log('[AB Gutschein] E-Mail-Template nicht gefunden: ' . $template_path);
            return false;
        }
        include $template_path;
        $email_html = ob_get_clean();

        // PDF generieren und als Anhang vorbereiten (nur wenn in Settings aktiviert)
        $attachments = [];
        $pdf_enabled = AB_Gutschein_Settings::get_setting('pdf_enabled', '');
        if ($pdf_enabled) {
            $pdf_html = AB_Gutschein_PDF::build_html($coupon_code, $amount, $expiry_date, $message, $sender_name);
            $pdf_path = AB_Gutschein_PDF::generate($pdf_html, $order_id);
            if ($pdf_path && file_exists($pdf_path)) {
                $attachments[] = $pdf_path;
                $order->update_meta_data('_ab_gutschein_pdf', $pdf_path);
                $order->save();
                error_log('[AB Gutschein] PDF generiert: ' . $pdf_path);
            } else {
                error_log('[AB Gutschein] PDF-Generierung fehlgeschlagen fuer Order #' . $order_id);
            }
        }

        // Senden (mit PDF-Anhang als 5. Parameter)
        $sent = wp_mail($to, $subject, $email_html, $headers, $attachments);
        error_log('[AB Gutschein] Gutschein-Mail an ' . $to . ' ' . ($sent ? 'erfolgreich' : 'fehlgeschlagen'));

        // Order Note schreiben
        if ($sent) {
            $order->add_order_note(sprintf(
                'Gutschein-Mail gesendet an %s (Code: %s, Wert: %s EUR)',
                $to,
                $coupon_code,
                number_format($amount, 2, ',', '.')
            ));
        } else {
            $order->add_order_note(sprintf(
                'FEHLER: Gutschein-Mail an %s konnte nicht gesendet werden.',
                $to
            ));
        }

        // Wenn Empfaenger != Kaeufer: Bestaetigungs-Mail an Kaeufer
        if (!empty($recipient_email) && $recipient_email !== $order->get_billing_email()) {
            self::send_buyer_confirmation($order, $coupon_code, $amount, $expiry_date, $recipient_email, $headers);
        }

        // Admin-Benachrichtigung senden
        if ($sent) {
            self::send_admin_notification($order, $coupon_code, $amount, $expiry_date, $recipient_email, $sender_name);
        }

        // Marker setzen
        if ($sent) {
            $order->update_meta_data('_ab_gutschein_email_sent', 'yes');
            $order->save();
        }

        return $sent;
    }

    /**
     * Sendet eine Admin-Benachrichtigung bei neuer Gutschein-Buchung.
     */
    private static function send_admin_notification($order, $coupon_code, $amount, $expiry_date, $recipient_email, $sender_name) {
        $email_settings = get_option('ab_email_settings', []);
        $notification_email = isset($email_settings['admin_notification_gutschein']) ? $email_settings['admin_notification_gutschein'] : '';

        if (empty($notification_email)) {
            return;
        }

        $sender_email_addr = !empty($email_settings['sender_email']) ? $email_settings['sender_email'] : get_option('admin_email');
        $sender_name_from = !empty($email_settings['sender_name']) ? $email_settings['sender_name'] : get_bloginfo('name');

        $order_id = $order->get_id();
        $buyer_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
        $buyer_email = $order->get_billing_email();
        $formatted_amount = number_format(floatval($amount), 2, ',', '.') . ' EUR';

        $admin_subject = 'Neuer Gutschein — Bestellung #' . $order->get_order_number() . ' (' . $formatted_amount . ')';

        $admin_message = '<div style="font-family:Arial,sans-serif;color:#333;max-width:600px;">'
            . '<h2 style="color:#1e3d59;margin-bottom:5px;">Neuer Gutschein bestellt</h2>'
            . '<p style="color:#666;margin-top:0;">Bestellung #' . $order->get_order_number() . '</p>'
            . '<table style="width:100%;border-collapse:collapse;margin:15px 0;">'
            . '<tr><td colspan="2" style="padding:8px;background:#1e3d59;color:#fff;font-weight:bold;">Gutschein-Details</td></tr>'
            . '<tr><td style="padding:4px 8px;color:#666;">Betrag:</td><td style="padding:4px 8px;font-weight:bold;">' . esc_html($formatted_amount) . '</td></tr>'
            . '<tr><td style="padding:4px 8px;color:#666;">Code:</td><td style="padding:4px 8px;font-family:Courier New,monospace;">' . esc_html($coupon_code) . '</td></tr>'
            . '<tr><td style="padding:4px 8px;color:#666;">Gültig bis:</td><td style="padding:4px 8px;">' . esc_html($expiry_date) . '</td></tr>'
            . '</table>'
            . '<table style="width:100%;border-collapse:collapse;margin:15px 0;">'
            . '<tr><td colspan="2" style="padding:8px;background:#1e3d59;color:#fff;font-weight:bold;">Käufer</td></tr>'
            . '<tr><td style="padding:4px 8px;color:#666;">Name:</td><td style="padding:4px 8px;">' . esc_html($buyer_name) . '</td></tr>'
            . '<tr><td style="padding:4px 8px;color:#666;">E-Mail:</td><td style="padding:4px 8px;">' . esc_html($buyer_email) . '</td></tr>';

        if (!empty($recipient_email) && $recipient_email !== $buyer_email) {
            $admin_message .= '<tr><td style="padding:4px 8px;color:#666;">Empfänger:</td><td style="padding:4px 8px;">' . esc_html($recipient_email) . '</td></tr>';
        }
        if (!empty($sender_name)) {
            $admin_message .= '<tr><td style="padding:4px 8px;color:#666;">Absender-Name:</td><td style="padding:4px 8px;">' . esc_html($sender_name) . '</td></tr>';
        }

        $admin_message .= '</table>'
            . '<p style="margin-top:15px;"><a href="' . admin_url('post.php?post=' . $order_id . '&action=edit') . '" style="display:inline-block;background:#0066cc;color:#fff;padding:8px 16px;text-decoration:none;border-radius:4px;">Bestellung ansehen</a></p>'
            . '</div>';

        $admin_headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $sender_name_from . ' <' . $sender_email_addr . '>',
            'Reply-To: ' . $sender_name_from . ' <' . $sender_email_addr . '>',
        ];

        wp_mail($notification_email, $admin_subject, $admin_message, $admin_headers);
        error_log('[AB Gutschein] Admin-Benachrichtigung gesendet an ' . $notification_email . ' für Order #' . $order_id);
    }

    /**
     * Sendet eine Bestaetigungs-Mail an den Kaeufer.
     * Settings kommen aus dem AB Email Customizer (ab_email_settings).
     */
    public static function send_buyer_confirmation($order, $coupon_code, $amount, $expiry_date, $recipient_email, $headers) {
        $email_settings = get_option('ab_email_settings', []);

        // Pruefen ob Kaeufer-Bestaetigung aktiviert ist
        if (empty($email_settings['send_email_gutschein_buyer'])) {
            error_log('[AB Gutschein] Kaeufer-Bestaetigung ist deaktiviert im Email Customizer.');
            return false;
        }

        $subject = !empty($email_settings['subject_gutschein_buyer']) ? $email_settings['subject_gutschein_buyer'] : 'Deine Gutschein-Bestellung';

        // Content aus Email Customizer (mit Default-Fallback)
        $email_content = !empty($email_settings['content_gutschein_buyer']) ? $email_settings['content_gutschein_buyer'] : self::get_default_content_gutschein_buyer();

        // Shortcodes + Platzhalter aufloesen
        global $ab_current_order;
        $ab_current_order = $order;

        $subject = AB_Email_Customizer::replace_variables($subject, $order, 'Gutschein');
        $subject = do_shortcode($subject);

        $email_content = AB_Email_Customizer::replace_variables($email_content, $order, 'Gutschein');
        $email_content = do_shortcode($email_content);
        $email_content = apply_filters('ab_process_email_content', $email_content, $order);

        $ab_current_order = null;

        // Template rendern (Logo + Content + Footer)
        $buyer_email = $order->get_billing_email();
        $email_body = $email_content;
        ob_start();
        $template_path = plugin_dir_path(__FILE__) . '../templates/emails/gutschein-buyer-email.php';
        if (!file_exists($template_path)) {
            error_log('[AB Gutschein] Kaeufer-Template nicht gefunden: ' . $template_path);
            return false;
        }
        include $template_path;
        $message_html = ob_get_clean();

        // PDF aus Order-Meta lesen und als Anhang anhaengen
        $attachments = [];
        $pdf_path = $order->get_meta('_ab_gutschein_pdf');
        if ($pdf_path && file_exists($pdf_path)) {
            $attachments[] = $pdf_path;
        }

        $sent = wp_mail($buyer_email, $subject, $message_html, $headers, $attachments);
        error_log('[AB Gutschein] Kaeufer-Bestaetigung an ' . $buyer_email . ' ' . ($sent ? 'erfolgreich' : 'fehlgeschlagen'));

        // Order Note schreiben
        if ($sent) {
            $order->add_order_note(sprintf(
                'Kaeufer-Bestaetigung gesendet an %s (Gutschein wurde an %s verschickt)',
                $buyer_email,
                $recipient_email
            ));
        } else {
            $order->add_order_note(sprintf(
                'FEHLER: Kaeufer-Bestaetigung an %s konnte nicht gesendet werden.',
                $buyer_email
            ));
        }

        return $sent;
    }
}
