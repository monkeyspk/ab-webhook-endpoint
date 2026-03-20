<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Workshop, Kurs & Experience Scheduler - Nutzt WooCommerce Action Scheduler für geplante E-Mails.
 */
class AB_Workshop_Scheduler {

    /**
     * Registriert Action Scheduler Callbacks.
     */
    public static function init() {
        add_action('ab_workshop_send_reminder', [__CLASS__, 'send_reminder_email']);
        add_action('ab_kurs_send_reminder', [__CLASS__, 'send_kurs_reminder_email']);
        add_action('ab_workshop_send_coach_reminder', [__CLASS__, 'send_workshop_coach_reminder']);
        add_action('ab_kurs_send_coach_reminder', [__CLASS__, 'send_kurs_coach_reminder']);
        add_action('ab_experience_send_welcome', [__CLASS__, 'send_experience_welcome']);
        add_action('ab_experience_send_one_month', [__CLASS__, 'send_experience_one_month']);
        add_action('ab_experience_send_two_months', [__CLASS__, 'send_experience_two_months']);
    }

    /**
     * Plant Reminder E-Mails für eine Workshop-Bestellung.
     */
    public static function schedule_workshop_emails($order_id) {
        // Alte geplante E-Mails für diese Order canceln
        self::unschedule_workshop_emails($order_id);

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $email_settings = get_option('ab_email_settings', []);
        $timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('Europe/Zurich');
        $now = new DateTime('now', $timezone);

        // Reminder: X Tage vor erstem Event-Datum
        $first_date_str = ab_get_workshop_first_date($order);
        if ($first_date_str && !empty($email_settings['send_email_workshop_reminder'])) {
            $reminder_days = isset($email_settings['workshop_reminder_days']) ? intval($email_settings['workshop_reminder_days']) : 3;
            if ($reminder_days < 1) $reminder_days = 3;

            $first_date = DateTime::createFromFormat('d-m-Y', $first_date_str, $timezone);
            if ($first_date) {
                $first_date->setTime(10, 0, 0); // 10:00 Uhr
                $reminder_date = clone $first_date;
                $reminder_date->modify("-{$reminder_days} days");

                if ($reminder_date > $now) {
                    as_schedule_single_action(
                        $reminder_date->getTimestamp(),
                        'ab_workshop_send_reminder',
                        array('order_id' => $order_id),
                        'ab-workshop'
                    );
                    error_log("[AB Workshop] Reminder geplant für Order #{$order_id} am " . $reminder_date->format('Y-m-d H:i:s'));
                } else {
                    error_log("[AB Workshop] Reminder für Order #{$order_id} liegt in der Vergangenheit - nicht geplant");
                }
            }
        }

        // Coach Reminder: X Tage vor erstem Event-Datum
        if ($first_date_str && !empty($email_settings['send_email_workshop_coach_reminder'])) {
            $coach_days = isset($email_settings['workshop_coach_reminder_days']) ? intval($email_settings['workshop_coach_reminder_days']) : 3;
            if ($coach_days < 1) $coach_days = 3;

            $first_date = DateTime::createFromFormat('d-m-Y', $first_date_str, $timezone);
            if ($first_date) {
                $first_date->setTime(10, 0, 0);
                $coach_date = clone $first_date;
                $coach_date->modify("-{$coach_days} days");

                if ($coach_date > $now) {
                    as_schedule_single_action(
                        $coach_date->getTimestamp(),
                        'ab_workshop_send_coach_reminder',
                        array('order_id' => $order_id),
                        'ab-workshop'
                    );
                    error_log("[AB Workshop] Coach-Reminder geplant für Order #{$order_id} am " . $coach_date->format('Y-m-d H:i:s'));
                }
            }
        }
    }

    /**
     * Plant Reminder E-Mails für eine Kurs-Bestellung (gleiche Logik wie Workshop).
     */
    public static function schedule_kurs_emails($order_id) {
        // Alte geplante E-Mails für diese Order canceln
        self::unschedule_kurs_emails($order_id);

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $email_settings = get_option('ab_email_settings', []);
        $timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('Europe/Zurich');
        $now = new DateTime('now', $timezone);

        // Reminder: X Tage vor erstem Event-Datum
        $first_date_str = ab_get_workshop_first_date($order);
        if ($first_date_str && !empty($email_settings['send_email_kurs_reminder'])) {
            $reminder_days = isset($email_settings['kurs_reminder_days']) ? intval($email_settings['kurs_reminder_days']) : 3;
            if ($reminder_days < 1) $reminder_days = 3;

            $first_date = DateTime::createFromFormat('d-m-Y', $first_date_str, $timezone);
            if ($first_date) {
                $first_date->setTime(10, 0, 0); // 10:00 Uhr
                $reminder_date = clone $first_date;
                $reminder_date->modify("-{$reminder_days} days");

                if ($reminder_date > $now) {
                    as_schedule_single_action(
                        $reminder_date->getTimestamp(),
                        'ab_kurs_send_reminder',
                        array('order_id' => $order_id),
                        'ab-kurs'
                    );
                    error_log("[AB Kurs] Reminder geplant für Order #{$order_id} am " . $reminder_date->format('Y-m-d H:i:s'));
                } else {
                    error_log("[AB Kurs] Reminder für Order #{$order_id} liegt in der Vergangenheit - nicht geplant");
                }
            }
        }

        // Coach Reminder: X Tage vor erstem Event-Datum
        if ($first_date_str && !empty($email_settings['send_email_kurs_coach_reminder'])) {
            $coach_days = isset($email_settings['kurs_coach_reminder_days']) ? intval($email_settings['kurs_coach_reminder_days']) : 3;
            if ($coach_days < 1) $coach_days = 3;

            $first_date = DateTime::createFromFormat('d-m-Y', $first_date_str, $timezone);
            if ($first_date) {
                $first_date->setTime(10, 0, 0);
                $coach_date = clone $first_date;
                $coach_date->modify("-{$coach_days} days");

                if ($coach_date > $now) {
                    as_schedule_single_action(
                        $coach_date->getTimestamp(),
                        'ab_kurs_send_coach_reminder',
                        array('order_id' => $order_id),
                        'ab-kurs'
                    );
                    error_log("[AB Kurs] Coach-Reminder geplant für Order #{$order_id} am " . $coach_date->format('Y-m-d H:i:s'));
                }
            }
        }
    }

    /**
     * Sendet die Workshop Reminder E-Mail (Action Scheduler Callback).
     */
    public static function send_reminder_email($order_id) {
        $order = wc_get_order($order_id);
        if (!$order || !$order->has_status('workshop')) {
            error_log("[AB Workshop] Reminder für Order #{$order_id} übersprungen - Status ist nicht 'workshop'");
            return;
        }

        self::send_scheduled_email($order_id, 'workshop_reminder', 'Workshop Erinnerung');
    }

    /**
     * Sendet die Kurs Reminder E-Mail (Action Scheduler Callback).
     */
    public static function send_kurs_reminder_email($order_id) {
        $order = wc_get_order($order_id);
        if (!$order || !$order->has_status('kurs')) {
            error_log("[AB Kurs] Reminder für Order #{$order_id} übersprungen - Status ist nicht 'kurs'");
            return;
        }

        self::send_scheduled_email($order_id, 'kurs_reminder', 'Kurs Erinnerung');
    }

    /**
     * Sendet die Workshop Coach Reminder E-Mail (Action Scheduler Callback).
     */
    public static function send_workshop_coach_reminder($order_id) {
        $order = wc_get_order($order_id);
        if (!$order || !$order->has_status('workshop')) {
            error_log("[AB Workshop] Coach-Reminder für Order #{$order_id} übersprungen - Status ist nicht 'workshop'");
            return;
        }
        self::send_coach_reminder_email($order_id, 'workshop_coach_reminder', 'Workshop Coach Erinnerung');
    }

    /**
     * Sendet die Kurs Coach Reminder E-Mail (Action Scheduler Callback).
     */
    public static function send_kurs_coach_reminder($order_id) {
        $order = wc_get_order($order_id);
        if (!$order || !$order->has_status('kurs')) {
            error_log("[AB Kurs] Coach-Reminder für Order #{$order_id} übersprungen - Status ist nicht 'kurs'");
            return;
        }
        self::send_coach_reminder_email($order_id, 'kurs_coach_reminder', 'Kurs Coach Erinnerung');
    }

    /**
     * Sendet eine Coach-Reminder E-Mail.
     * Recipient: Coach email aus Order Item Meta '_event_coach_email'.
     */
    private static function send_coach_reminder_email($order_id, $email_key, $status_label) {
        $sent_marker = '_ab_email_sent_' . $email_key;
        if (get_post_meta($order_id, $sent_marker, true) === 'yes') {
            error_log("[AB Scheduler] Coach E-Mail '{$email_key}' bereits gesendet für Order #{$order_id}");
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) return;

        // Coach-Email aus Order Item holen
        $coach_email = '';
        foreach ($order->get_items() as $item) {
            $coach_email = $item->get_meta('_event_coach_email');
            if (!empty($coach_email)) break;
        }

        // Fallback: headcoach email vom Event Post
        if (empty($coach_email)) {
            foreach ($order->get_items() as $item) {
                $product_id = $item->get_meta('_event_product_id') ?: $item->get_product_id();
                if ($product_id) {
                    $event_id = get_post_meta($product_id, '_event_id', true);
                    if ($event_id) {
                        $coach_email = get_post_meta($event_id, '_event_headcoach_email', true);
                        if (!empty($coach_email)) break;
                    }
                }
            }
        }

        if (empty($coach_email)) {
            error_log("[AB Scheduler] Keine Coach-Email gefunden für Order #{$order_id}");
            return;
        }

        $email_settings = get_option('ab_email_settings', []);

        if (empty($email_settings['send_email_' . $email_key])) {
            error_log("[AB Scheduler] Coach E-Mail '{$email_key}' ist deaktiviert");
            return;
        }

        $subject = !empty($email_settings['subject_' . $email_key])
            ? $email_settings['subject_' . $email_key]
            : 'Erinnerung: Bevorstehender ' . (strpos($email_key, 'workshop') !== false ? 'Workshop' : 'Kurs');

        $header_text = !empty($email_settings['header_' . $email_key])
            ? $email_settings['header_' . $email_key]
            : $status_label;

        $email_body = !empty($email_settings['content_' . $email_key])
            ? $email_settings['content_' . $email_key]
            : '';

        if (empty($email_body)) {
            error_log("[AB Scheduler] Kein E-Mail-Inhalt für Coach '{$email_key}' konfiguriert");
            return;
        }

        // Platzhalter ersetzen
        $subject = AB_Email_Customizer::replace_variables($subject, $order, $status_label);
        $header_text = AB_Email_Customizer::replace_variables($header_text, $order, $status_label);
        $email_body = AB_Email_Customizer::replace_variables($email_body, $order, $status_label);

        // Shortcodes auflösen
        global $ab_current_order;
        $ab_current_order = $order;

        $subject = do_shortcode($subject);
        $header_text = do_shortcode($header_text);
        $email_body = do_shortcode($email_body);
        $email_body = apply_filters('ab_process_email_content', $email_body, $order);

        $ab_current_order = null;

        // HTML-Template
        ob_start();
        $template_path = plugin_dir_path(__FILE__) . '../templates/emails/custom-status-email.php';
        if (!file_exists($template_path)) {
            error_log("[AB Scheduler] E-Mail-Template nicht gefunden: " . $template_path);
            return;
        }
        include $template_path;
        $message = ob_get_clean();
        if (empty($message)) return;

        // Header
        $sender_email = !empty($email_settings['sender_email']) ? $email_settings['sender_email'] : get_option('admin_email');
        $sender_name = !empty($email_settings['sender_name']) ? $email_settings['sender_name'] : get_bloginfo('name');

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $sender_name . ' <' . $sender_email . '>',
            'Reply-To: ' . $sender_name . ' <' . $sender_email . '>',
        ];

        $sent = wp_mail($coach_email, $subject, $message, $headers);

        if ($sent) {
            update_post_meta($order_id, $sent_marker, 'yes');
            $order->add_order_note('Coach-Erinnerung gesendet an ' . $coach_email);
            error_log("[AB Scheduler] Coach E-Mail '{$email_key}' gesendet an {$coach_email} für Order #{$order_id}");
        } else {
            error_log("[AB Scheduler] Coach E-Mail '{$email_key}' Versand fehlgeschlagen für Order #{$order_id}");
        }
    }

    /**
     * Sendet eine geplante E-Mail (Reminder).
     * Folgt dem gleichen Pattern wie AB_Email_Sender::send_status_email().
     */
    private static function send_scheduled_email($order_id, $email_key, $status_label) {
        // Duplikat-Schutz
        $sent_marker = '_ab_email_sent_' . $email_key;
        if (get_post_meta($order_id, $sent_marker, true) === 'yes') {
            error_log("[AB Scheduler] E-Mail '{$email_key}' bereits gesendet für Order #{$order_id}");
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $to = $order->get_billing_email();
        if (!$to) {
            return;
        }

        $email_settings = get_option('ab_email_settings', []);

        // Prüfen ob E-Mail für diesen Key aktiviert ist
        if (empty($email_settings['send_email_' . $email_key])) {
            error_log("[AB Scheduler] E-Mail '{$email_key}' ist deaktiviert");
            return;
        }

        $default_subjects = [
            'workshop_reminder'      => 'Erinnerung an deinen Workshop',
            'kurs_reminder'          => 'Erinnerung an deinen Kurs',
            'experience_welcome'     => 'Willkommen bei Parkour ONE',
            'experience_one_month'   => 'Ein Monat bei ONE',
            'experience_two_months'  => '2 Monate Parkour liegen hinter dir',
        ];

        $default_headers = [
            'workshop_reminder'      => 'Workshop Erinnerung',
            'kurs_reminder'          => 'Kurs Erinnerung',
            'experience_welcome'     => 'Willkommen',
            'experience_one_month'   => 'Ein Monat bei ONE',
            'experience_two_months'  => '2 Monate Parkour',
        ];

        $subject = !empty($email_settings['subject_' . $email_key])
            ? $email_settings['subject_' . $email_key]
            : ($default_subjects[$email_key] ?? 'Erinnerung');

        $header_text = !empty($email_settings['header_' . $email_key])
            ? $email_settings['header_' . $email_key]
            : ($default_headers[$email_key] ?? $status_label);

        $email_body = !empty($email_settings['content_' . $email_key])
            ? $email_settings['content_' . $email_key]
            : '';

        if (empty($email_body)) {
            error_log("[AB Scheduler] Kein E-Mail-Inhalt für '{$email_key}' konfiguriert");
            return;
        }

        // Platzhalter ersetzen
        $subject = AB_Email_Customizer::replace_variables($subject, $order, $status_label);
        $header_text = AB_Email_Customizer::replace_variables($header_text, $order, $status_label);
        $email_body = AB_Email_Customizer::replace_variables($email_body, $order, $status_label);

        // Shortcodes auflösen
        global $ab_current_order;
        $ab_current_order = $order;

        $subject = do_shortcode($subject);
        $header_text = do_shortcode($header_text);
        $email_body = do_shortcode($email_body);
        $email_body = apply_filters('ab_process_email_content', $email_body, $order);

        $ab_current_order = null;

        // HTML-Template einbinden
        ob_start();
        $template_path = plugin_dir_path(__FILE__) . '../templates/emails/custom-status-email.php';
        if (!file_exists($template_path)) {
            error_log("[AB Scheduler] E-Mail-Template nicht gefunden: " . $template_path);
            return;
        }
        include $template_path;
        $message = ob_get_clean();

        if (empty($message)) {
            return;
        }

        // Header
        $sender_email = !empty($email_settings['sender_email']) ? $email_settings['sender_email'] : get_option('admin_email');
        $sender_name = !empty($email_settings['sender_name']) ? $email_settings['sender_name'] : get_bloginfo('name');

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $sender_name . ' <' . $sender_email . '>',
            'Reply-To: ' . $sender_name . ' <' . $sender_email . '>',
        ];

        $sent = wp_mail($to, $subject, $message, $headers);

        if ($sent) {
            update_post_meta($order_id, $sent_marker, 'yes');
            error_log("[AB Scheduler] E-Mail '{$email_key}' erfolgreich gesendet für Order #{$order_id}");
        } else {
            error_log("[AB Scheduler] E-Mail '{$email_key}' Versand fehlgeschlagen für Order #{$order_id}");
        }
    }

    /**
     * Cancelt alle geplanten Workshop-E-Mails für eine Order.
     */
    public static function unschedule_workshop_emails($order_id) {
        if (!function_exists('as_unschedule_all_actions')) {
            return;
        }

        as_unschedule_all_actions('ab_workshop_send_reminder', array('order_id' => $order_id), 'ab-workshop');
        as_unschedule_all_actions('ab_workshop_send_coach_reminder', array('order_id' => $order_id), 'ab-workshop');

        error_log("[AB Workshop] Alle geplanten E-Mails gecancelt für Order #{$order_id}");
    }

    /**
     * Cancelt alle geplanten Kurs-E-Mails für eine Order.
     */
    public static function unschedule_kurs_emails($order_id) {
        if (!function_exists('as_unschedule_all_actions')) {
            return;
        }

        as_unschedule_all_actions('ab_kurs_send_reminder', array('order_id' => $order_id), 'ab-kurs');
        as_unschedule_all_actions('ab_kurs_send_coach_reminder', array('order_id' => $order_id), 'ab-kurs');

        error_log("[AB Kurs] Alle geplanten E-Mails gecancelt für Order #{$order_id}");
    }

    // =============================================
    // Experience E-Mails (Onboarding-Sequenz)
    // =============================================

    /**
     * Plant die 3 Experience-E-Mails ab dem Zeitpunkt des Schüler_in-Status.
     * - 1 Woche nach Einstieg: Willkommen
     * - 1 Monat nach Einstieg: Ein Monat bei ONE
     * - 2 Monate nach Einstieg: 2 Monate Parkour
     */
    public static function schedule_experience_emails($order_id) {
        // Alte geplante Experience-E-Mails canceln
        self::unschedule_experience_emails($order_id);

        $timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('Europe/Zurich');
        $now = new DateTime('now', $timezone);

        $email_settings = get_option('ab_email_settings', []);

        // E-Mail 1: Willkommen — 1 Woche nach jetzt
        if (!empty($email_settings['send_email_experience_welcome'])) {
            $send_date = clone $now;
            $send_date->modify('+1 week');
            $send_date->setTime(10, 0, 0);

            as_schedule_single_action(
                $send_date->getTimestamp(),
                'ab_experience_send_welcome',
                array('order_id' => $order_id),
                'ab-experience'
            );
            error_log("[AB Experience] Willkommen geplant für Order #{$order_id} am " . $send_date->format('Y-m-d H:i:s'));
        }

        // E-Mail 2: Ein Monat bei ONE — 1 Monat nach jetzt
        if (!empty($email_settings['send_email_experience_one_month'])) {
            $send_date = clone $now;
            $send_date->modify('+1 month');
            $send_date->setTime(10, 0, 0);

            as_schedule_single_action(
                $send_date->getTimestamp(),
                'ab_experience_send_one_month',
                array('order_id' => $order_id),
                'ab-experience'
            );
            error_log("[AB Experience] Ein Monat geplant für Order #{$order_id} am " . $send_date->format('Y-m-d H:i:s'));
        }

        // E-Mail 3: 2 Monate Parkour — 2 Monate nach jetzt
        if (!empty($email_settings['send_email_experience_two_months'])) {
            $send_date = clone $now;
            $send_date->modify('+2 months');
            $send_date->setTime(10, 0, 0);

            as_schedule_single_action(
                $send_date->getTimestamp(),
                'ab_experience_send_two_months',
                array('order_id' => $order_id),
                'ab-experience'
            );
            error_log("[AB Experience] Zwei Monate geplant für Order #{$order_id} am " . $send_date->format('Y-m-d H:i:s'));
        }
    }

    /**
     * Callback: Willkommen E-Mail (1 Woche nach Schüler_in)
     */
    public static function send_experience_welcome($order_id) {
        $order = wc_get_order($order_id);
        if (!$order || !$order->has_status('schuelerin')) {
            error_log("[AB Experience] Willkommen für Order #{$order_id} übersprungen - Status ist nicht 'schuelerin'");
            return;
        }
        self::send_scheduled_email($order_id, 'experience_welcome', 'Willkommen');
    }

    /**
     * Callback: Ein Monat bei ONE E-Mail (1 Monat nach Schüler_in)
     */
    public static function send_experience_one_month($order_id) {
        $order = wc_get_order($order_id);
        if (!$order || !$order->has_status('schuelerin')) {
            error_log("[AB Experience] Ein Monat für Order #{$order_id} übersprungen - Status ist nicht 'schuelerin'");
            return;
        }
        self::send_scheduled_email($order_id, 'experience_one_month', 'Ein Monat bei ONE');
    }

    /**
     * Callback: 2 Monate Parkour E-Mail (2 Monate nach Schüler_in)
     */
    public static function send_experience_two_months($order_id) {
        $order = wc_get_order($order_id);
        if (!$order || !$order->has_status('schuelerin')) {
            error_log("[AB Experience] Zwei Monate für Order #{$order_id} übersprungen - Status ist nicht 'schuelerin'");
            return;
        }
        self::send_scheduled_email($order_id, 'experience_two_months', '2 Monate Parkour');
    }

    /**
     * Cancelt alle geplanten Experience-E-Mails für eine Order.
     */
    public static function unschedule_experience_emails($order_id) {
        if (!function_exists('as_unschedule_all_actions')) {
            return;
        }

        as_unschedule_all_actions('ab_experience_send_welcome', array('order_id' => $order_id), 'ab-experience');
        as_unschedule_all_actions('ab_experience_send_one_month', array('order_id' => $order_id), 'ab-experience');
        as_unschedule_all_actions('ab_experience_send_two_months', array('order_id' => $order_id), 'ab-experience');

        error_log("[AB Experience] Alle geplanten E-Mails gecancelt für Order #{$order_id}");
    }
}
