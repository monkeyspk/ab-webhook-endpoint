<?php
if (!defined('ABSPATH')) {
    exit;
}

class AB_Email_Sender {
    public static function send_status_email($order_id, $new_status) {

      // Skip-Marker prüfen
          if (get_post_meta($order_id, '_ab_skip_probetraining_email', true) === 'yes') {
              delete_post_meta($order_id, '_ab_skip_probetraining_email');
              error_log('[AB Status Plugin] E-Mail-Versand übersprungen aufgrund des Skip-Markers');
              return false;
          }

          // Silent-Update Marker prüfen
          if (get_post_meta($order_id, '_ab_silent_update', true) === 'yes') {
              error_log('[AB Status Plugin] E-Mail-Versand übersprungen aufgrund des Silent-Flags');
              return false;
          }

          // Gutschein-Status hat eigenes E-Mail-System (AB_Gutschein_Email)
          if ($new_status === 'wc-gutschein') {
              error_log('[AB Status Plugin] Status gutschein hat eigenes E-Mail-System - generische Mail uebersprungen');
              return false;
          }

          // Fix: Duplikat-Schutz - Prüfen ob E-Mail für diesen Status bereits gesendet wurde
          // Ausnahme: bkdvertrag darf immer erneut gesendet werden (Vertragslink zum Testen)
          $email_sent_key = '_ab_email_sent_' . str_replace('wc-', '', $new_status);
          $email_already_sent = get_post_meta($order_id, $email_sent_key, true);
          if ($email_already_sent === 'yes' && $new_status !== 'wc-bkdvertrag') {
              error_log('[AB Status Plugin] E-Mail für Status ' . $new_status . ' wurde bereits gesendet für Order #' . $order_id);
              return false;
          }

          // KRITISCH: Status "schuelerin" und "bestandkundeakz" nur erlauben wenn Vertrag abgeschlossen!
          if ($new_status === 'wc-schuelerin' || $new_status === 'wc-bestandkundeakz') {
              $contract_status = get_post_meta($order_id, '_contract_status', true);
              if ($contract_status !== 'completed') {
                  error_log(sprintf(
                      '[AB Status Plugin] E-MAIL BLOCKIERT für Order #%d: Status "%s" aber kein abgeschlossener Vertrag (_contract_status = "%s")',
                      $order_id,
                      $new_status,
                      $contract_status ?: 'nicht gesetzt'
                  ));
                  return false;
              }
          }

        try {
            error_log('[AB Status Plugin] Sende E-Mail für Order #' . $order_id . ' mit Status ' . $new_status);
            error_log('====== START E-MAIL VERSAND ======');
            error_log('Order ID: ' . $order_id);
            error_log('Neuer Status: ' . $new_status);

            $order = wc_get_order($order_id);
            if (!$order) {
                throw new Exception('Bestellung nicht gefunden');
            }

            $to = $order->get_billing_email();
            if (!$to) {
                throw new Exception('Kein Empfänger gefunden');
            }

            // Status-Label über AB_Custom_Statuses holen
            $custom_statuses = AB_Custom_Statuses::get_custom_statuses();
            $status_label = isset($custom_statuses[$new_status]) ? $custom_statuses[$new_status] : $new_status;
            $status_key = str_replace('wc-', '', $new_status);

            // E-Mail-Einstellungen aus dem Customizer
            $email_settings = get_option('ab_email_settings', []);

            // Prüfen ob E-Mail für diesen Status aktiviert ist
            $send_email_key = 'send_email_' . $status_key;
            if (empty($email_settings[$send_email_key])) {
                error_log('[AB Status Plugin] E-Mail Versand für Status ' . $new_status . ' ist deaktiviert.');
                return false;
            }

            $subject_key = 'subject_' . $status_key;
            $header_key = 'header_' . $status_key;
            $content_key = 'content_' . $status_key;

            $default_subject = sprintf('Deine Bestellung ist jetzt im Status "%s"', $status_label);
            $default_content = sprintf(
                "Hallo %s,\n\nDeine Bestellung (#%s) ist jetzt im Status: %s.\n\nFalls du Fragen hast, melde dich gern!",
                $order->get_billing_first_name(),
                $order->get_order_number(),
                $status_label
            );

            $subject = !empty($email_settings[$subject_key]) ? $email_settings[$subject_key] : $default_subject;
            $header_text = !empty($email_settings[$header_key]) ? $email_settings[$header_key] : $status_label;
            
            // Prüfen ob Status "vertragverschickt" und vorheriger Status "warteliste" war
            $previous_status = get_post_meta($order_id, '_ab_previous_status', true);
            if ($status_key === 'vertragverschickt' && $previous_status === 'warteliste') {
                // Spezielle Inhalte für Warteliste -> Vertrag verschickt
                $warteliste_content_key = 'content_vertragverschickt_from_warteliste';
                $email_body = !empty($email_settings[$warteliste_content_key]) 
                    ? $email_settings[$warteliste_content_key] 
                    : '<div style="font-family: Arial, sans-serif; color: #333; padding: 20px; max-width: 600px; margin: 0 auto;">
<p style="text-align: left;"><strong>Hallo [first_participant_first_name],</strong></p>
<p style="text-align: left;">wir haben gute Neuigkeiten für dich! 🎉</p>
<p style="text-align: left;">In unserer <strong>[ab_event_title_clean]</strong> Klasse ist nun ein Platz für dich frei geworden. Vielen Dank für deine Geduld – wir freuen uns riesig, dass es nun geklappt hat!</p>
<p style="text-align: left;">Wenn du dabei sein möchtest und mit deinem wöchentlichen Parkour Training starten willst, kannst du hier den Vertrag abschliessen:</p>
<div style="text-align: center; margin: 20px 0;">
<a style="display: inline-block; background-color: #0066cc; color: #fff; padding: 12px 24px; text-decoration: none; border-radius: 5px; font-size: 16px;" href="[contract_link]">Jetzt Mitglied werden</a>
</div>
<p style="text-align: left;">Wir freuen uns sehr darauf, dich bald in unserer Klasse begrüssen zu dürfen!</p>
<p style="margin-top: 20px; text-align: left;">ONE for All &amp; All for ONE<br>
Viele Grüsse</p>
</div>';
                
                error_log('[AB Status Plugin] Verwende Warteliste-spezifischen E-Mail-Inhalt für Order #' . $order_id);
            } elseif ($status_key === 'bkdvertrag' && empty($email_settings[$content_key])) {
                // Default-Template: Bestandskunde Vertrag
                $subject = !empty($email_settings[$subject_key]) ? $email_settings[$subject_key] : 'Dein neuer Vertrag bei ParkourONE';
                $header_text = !empty($email_settings[$header_key]) ? $email_settings[$header_key] : 'Neuer Vertrag';
                $email_body = '<!-- Logo -->
<div style="font-family: Arial, sans-serif; color: #333;">
<div style="text-align: center; margin-bottom: 20px;">
&nbsp;
<img style="max-width: 300px; height: auto;" src="[ab_footer_logo_url]" alt="ParkourONE Logo" />
&nbsp;
</div>
<div style="font-family: Arial, sans-serif; color: #333; padding: 20px; max-width: 600px; margin: 0 auto;">
<p style="text-align: left;">Hallo [first_participant_first_name],</p>
<p style="text-align: left;">schön, dass du weiterhin bei ParkourONE dabei bist!</p>
<p style="text-align: left;">Aufgrund einer Vertragsanpassung haben wir einen neuen Vertrag für dich vorbereitet. Bitte schliesse diesen über den folgenden Link ab:</p>
<div style="text-align: center; margin: 20px 0;">
<a style="display: inline-block; background-color: #0066cc; color: #fff; padding: 12px 24px; text-decoration: none; border-radius: 5px; font-size: 16px;" href="[contract_link]">Vertrag abschliessen</a>
</div>
Dieser Link ist für 6 Tage aktiv.
<p style="text-align: left;">Falls du Fragen zum neuen Vertrag hast, melde dich gerne jederzeit bei uns.</p>
<p style="margin-top: 20px; text-align: left;">ONE for All &amp; All for ONE<br>Viele Grüsse,</p>
<strong>Dein Team von ParkourONE</strong>
</div>
<div style="border-top: 1px solid #ddd; margin: 20px 0;"></div>
<div style="font-family: Arial, sans-serif; color: #777; font-size: 12px; padding: 20px; max-width: 600px; margin: 0 auto;">
[ab_footer]
</div>
</div>';

            } elseif ($status_key === 'bestandkundeakz' && empty($email_settings[$content_key])) {
                // Default-Template: Bestandskunde akzeptiert (Vertrag abgeschlossen, PDF im Anhang)
                $subject = !empty($email_settings[$subject_key]) ? $email_settings[$subject_key] : 'Dein Vertrag bei ParkourONE — Bestätigung';
                $header_text = !empty($email_settings[$header_key]) ? $email_settings[$header_key] : 'Vertrag abgeschlossen';
                $email_body = '<!-- Logo -->
<div style="font-family: Arial, sans-serif; color: #333;">
<div style="text-align: center; margin-bottom: 20px;">
&nbsp;
<img style="max-width: 300px; height: auto;" src="[ab_footer_logo_url]" alt="ParkourONE Logo" />
&nbsp;
</div>
<div style="font-family: Arial, sans-serif; color: #333; padding: 20px; max-width: 600px; margin: 0 auto;">
<p style="text-align: left;">Hallo [first_participant_first_name],</p>
<p style="text-align: left;">vielen Dank! Dein Vertrag wurde erfolgreich abgeschlossen.</p>
<p style="text-align: left;">Im Anhang findest du deinen unterschriebenen Vertrag als PDF zur Bestätigung.</p>
<p style="text-align: left;">Dein wöchentliches Training in der <strong>[ab_event_title_clean]</strong> Klasse geht wie gewohnt weiter. Wir freuen uns auf viele weitere gemeinsame Trainings!</p>
<p style="text-align: left;"><strong>Tipp:</strong> Wenn dir unser Training &amp; Konzept gefallen, kannst du uns mit einer positiven Bewertung auf Google unterstützen:</p>
<div style="margin: 10px 0;">
<a style="color: #0066cc; text-decoration: none;" href="[ab_google_review_link]">Rezension hinterlassen</a>
</div>
<p style="margin-top: 20px; text-align: left;">ONE for All &amp; All for ONE<br>Viele Grüsse,</p>
<strong>Dein Team von ParkourONE</strong>
</div>
<div style="border-top: 1px solid #ddd; margin: 20px 0;"></div>
<div style="font-family: Arial, sans-serif; color: #777; font-size: 12px; padding: 20px; max-width: 600px; margin: 0 auto;">
[ab_footer]
</div>
</div>';

            } else {
                $email_body = !empty($email_settings[$content_key]) ? $email_settings[$content_key] : $default_content;
            }

            // Vertragslink für Status "vertragverschickt"
            if ($status_key === 'vertragverschickt') {
                // Der Link wird über den [contract_link] Shortcode im Template eingefügt
            }


            // 1) Platzhalter ersetzen über AB_Email_Customizer
            $subject = AB_Email_Customizer::replace_variables($subject, $order, $status_label);
            $header_text = AB_Email_Customizer::replace_variables($header_text, $order, $status_label);
            $email_body = AB_Email_Customizer::replace_variables($email_body, $order, $status_label);

            // 2) Shortcodes auflösen
            global $ab_current_order;
            $ab_current_order = $order;

            $subject = do_shortcode($subject);
            $header_text = do_shortcode($header_text);
            $email_body = do_shortcode($email_body);
            $email_body = apply_filters('ab_process_email_content', $email_body, $order);


            $ab_current_order = null;

            // 3) HTML-Template einbinden
            ob_start();
            $template_path = plugin_dir_path(__FILE__) . '../templates/emails/custom-status-email.php';

            if (!file_exists($template_path)) {
                throw new Exception('E-Mail-Template nicht gefunden: ' . $template_path);
            }

            include $template_path;
            $message = ob_get_clean();

            if (empty($message)) {
                throw new Exception('E-Mail-Template konnte nicht geladen werden');
            }

            // Header - Absender aus Plugin-Einstellungen oder Fallback auf WordPress-Defaults
            $sender_email = !empty($email_settings['sender_email']) ? $email_settings['sender_email'] : get_option('admin_email');
            $sender_name = !empty($email_settings['sender_name']) ? $email_settings['sender_name'] : get_bloginfo('name');
            
            $headers = [
                'Content-Type: text/html; charset=UTF-8',
                'From: ' . $sender_name . ' <' . $sender_email . '>',
                'Reply-To: ' . $sender_name . ' <' . $sender_email . '>',
            ];

            // PDF anhängen wenn Status "schuelerin" oder "bestandkundeakz"
            $attachments = [];
            if ($new_status === 'wc-schuelerin' || $new_status === 'wc-bestandkundeakz') {
                $pdf_path = get_post_meta($order_id, '_ab_contract_pdf', true);
                error_log('[AB Status Plugin] PDF-Pfad aus Meta: ' . ($pdf_path ?: 'LEER/NICHT GESETZT'));

                if ($pdf_path && file_exists($pdf_path)) {
                    $attachments[] = $pdf_path;
                    error_log('[AB Status Plugin] PDF wird angehängt: ' . $pdf_path);
                } else {
                    error_log('[AB Status Plugin] WARNUNG: PDF nicht gefunden!');
                    error_log('[AB Status Plugin] - PDF-Pfad: ' . ($pdf_path ?: 'NICHT GESETZT'));
                    error_log('[AB Status Plugin] - Datei existiert: ' . (file_exists($pdf_path) ? 'JA' : 'NEIN'));

                    // Fallback: Auch alten Key prüfen (für Kompatibilität)
                    $old_pdf_path = get_post_meta($order_id, '_contract_pdf_path', true);
                    if ($old_pdf_path && file_exists($old_pdf_path)) {
                        $attachments[] = $old_pdf_path;
                        error_log('[AB Status Plugin] PDF über alten Key gefunden: ' . $old_pdf_path);
                    }
                }
            }

            // Standard E-Mail an Schüler senden (für ALLE Status)
            $sent = wp_mail($to, $subject, $message, $headers, $attachments);
            error_log('[AB Status Plugin] E-Mail-Versand ' . ($sent ? 'erfolgreich' : 'fehlgeschlagen'));

            // Fix: Marker setzen, dass E-Mail für diesen Status gesendet wurde
            if ($sent) {
                update_post_meta($order_id, $email_sent_key, 'yes');
            }


            // ZUSÄTZLICHE Benachrichtigungs-E-Mail nur bei Status "schuelerin"
            if ($new_status === 'wc-schuelerin') {
$notification_email = isset($email_settings['admin_notification_schuelerin'])
? $email_settings['admin_notification_schuelerin']
: '';

if (!empty($notification_email)) {
          $admin_subject = 'Neuer Vertragsabschluss';

        $contract_id = AB_Contract_Wizard::determine_contract_type($order);
        $contract_details = AB_Contract_Overview::get_contract_details($contract_id);

        // Teilnehmerdaten holen
        $participant_info = '';
        foreach ($order->get_items() as $item) {
        $participants = $item->get_meta('_event_participant_data');
        if (!empty($participants) && is_array($participants)) {
        $first_participant = reset($participants);
        $participant_vorname = $first_participant['vorname'] ?? '';
        $participant_nachname = $first_participant['name'] ?? '';
        $participant_geburtsdatum = $first_participant['geburtsdatum'] ?? '';

        $participant_info = sprintf(
        'Teilnehmer: %s %s<br>' .
        'Geburtsdatum: %s<br>',
        $participant_vorname,
        $participant_nachname,
        $participant_geburtsdatum
        );
        break;
        }
        }

        // AHV-Nummer aus Vertragsdaten
        $ahv_info = '';
        $contract_data = get_post_meta($order_id, '_ab_contract_data', true);
        if (!empty($contract_data['ahv_nummer'])) {
            $ahv_info = 'AHV-Nummer: ' . $contract_data['ahv_nummer'] . '<br>';
        }

        $admin_message = sprintf(
        'Hallo,<br><br>' .
        'Ein neuer Vertrag wurde abgeschlossen:<br><br>' .
        'Vertragsunterzeichner: %s %s<br>' .
        '%s' . // Teilnehmer-Info
        '%s' . // AHV-Info
        'Vertrag: %s<br>' .
        'Monatsbeitrag: %s €<br><br>' .
        'Die Schüler_in wurde im Academyboard aufgenommen und wird nun unter Schülerin gelistet.',
        $order->get_billing_first_name(),
        $order->get_billing_last_name(),
        $participant_info,
        $ahv_info,
        $contract_details['trainingsumfang'],
        $contract_details['vertrag_preis']
        );

                                  $admin_headers = [
                                      'Content-Type: text/html; charset=UTF-8',
                                      'From: ' . $sender_name . ' <' . $sender_email . '>',
                                      'Reply-To: ' . $sender_name . ' <' . $sender_email . '>',
                                  ];

                                  $sent_to = [];
                                  
                                  // Separates Mail an Admin
                                  wp_mail($notification_email, $admin_subject, $admin_message, $admin_headers, $attachments);
                                  $sent_to[] = strtolower(trim($notification_email));

                                  // Zusätzlich: Finde Coach-Email und sende die gleiche Mail (nur wenn nicht bereits gesendet)
                                  foreach ($order->get_items() as $item) {
                                      $coach_email = $item->get_meta('_event_coach_email');
                                      if (!empty($coach_email) && !in_array(strtolower(trim($coach_email)), $sent_to)) {
                                          wp_mail($coach_email, $admin_subject, $admin_message, $admin_headers, $attachments);
                                          $sent_to[] = strtolower(trim($coach_email));
                                      }
                                  }
                              }
                          }


                          // ZUSÄTZLICHE Benachrichtigungs-E-Mail bei Status "gekuendigt"
                          if ($new_status === 'wc-gekuendigt') {
                              $notification_email = isset($email_settings['admin_notification_gekuendigt'])  // Geändert von notification_email_gekuendigt
                                  ? $email_settings['admin_notification_gekuendigt']
                                  : '';

                                  if (!empty($notification_email)) {
                                      $admin_subject = 'Neue Kündigung eingegangen';

                                      $contract_id = AB_Contract_Wizard::determine_contract_type($order);
                                      $contract_details = AB_Contract_Overview::get_contract_details($contract_id);

                                      // Teilnehmerdaten holen
                                      $participant_info = '';
                                      foreach ($order->get_items() as $item) {
                                          $participants = $item->get_meta('_event_participant_data');
                                          if (!empty($participants) && is_array($participants)) {
                                              $first_participant = reset($participants);
                                              $participant_vorname = $first_participant['vorname'] ?? '';
                                              $participant_nachname = $first_participant['name'] ?? '';
                                              $participant_geburtsdatum = $first_participant['geburtsdatum'] ?? '';

                                              $participant_info = sprintf(
                                                  'Teilnehmer: %s %s<br>' .
                                                  'Geburtsdatum: %s<br>',
                                                  $participant_vorname,
                                                  $participant_nachname,
                                                  $participant_geburtsdatum
                                              );
                                              break; // Nur den ersten Teilnehmer nehmen
                                          }
                                      }

                                      // AHV-Nummer aus Vertragsdaten
                                      $ahv_info = '';
                                      $contract_data = get_post_meta($order_id, '_ab_contract_data', true);
                                      if (!empty($contract_data['ahv_nummer'])) {
                                          $ahv_info = 'AHV-Nummer: ' . $contract_data['ahv_nummer'] . '<br>';
                                      }

                                      $admin_message = sprintf(
                                          'Hallo,<br><br>' .
                                          'Eine neue Kündigung ist eingegangen:<br><br>' .
                                          'Vertragsunterzeichner: %s %s<br>' .
                                          '%s' . // Teilnehmer-Info
                                          '%s' . // AHV-Info
                                          'Vertrag: %s<br>' .
                                          'Monatsbeitrag: %s €<br><br>' .
                                          'Bitte die Kündigung bearbeiten.',
                                          $order->get_billing_first_name(),
                                          $order->get_billing_last_name(),
                                          $participant_info,
                                          $ahv_info,
                                          $contract_details['trainingsumfang'],
                                          $contract_details['vertrag_preis']
                                      );

        $admin_headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $sender_name . ' <' . $sender_email . '>',
            'Reply-To: ' . $sender_name . ' <' . $sender_email . '>',
        ];

        $sent_to = [];

        wp_mail($notification_email, $admin_subject, $admin_message, $admin_headers);
        $sent_to[] = strtolower(trim($notification_email));

        foreach ($order->get_items() as $item) {
            $coach_email = $item->get_meta('_event_coach_email');
            if (!empty($coach_email) && !in_array(strtolower(trim($coach_email)), $sent_to)) {
                wp_mail($coach_email, $admin_subject, $admin_message, $admin_headers, $attachments);
                $sent_to[] = strtolower(trim($coach_email));
            }
        }
    }
}

                          // ZUSÄTZLICHE Benachrichtigungs-E-Mail bei Status "bestandkundeakz"
                          if ($new_status === 'wc-bestandkundeakz') {
                              $notification_email = isset($email_settings['admin_notification_bestandkundeakz'])
                                  ? $email_settings['admin_notification_bestandkundeakz']
                                  : '';

                                  if (!empty($notification_email)) {
                                      $admin_subject = 'Neuer Bestandskunde-Vertragsabschluss — Bestellung #' . $order->get_order_number();

                                      $contract_id = AB_Contract_Wizard::determine_contract_type($order);
                                      $contract_details = AB_Contract_Overview::get_contract_details($contract_id);

                                      // Teilnehmerdaten holen (aus _event_participant_data oder _ab_contract_data)
                                      $participant_info = '';
                                      $contract_data = get_post_meta($order_id, '_ab_contract_data', true);

                                      foreach ($order->get_items() as $item) {
                                          $participants = $item->get_meta('_event_participant_data');
                                          if (!empty($participants) && is_array($participants)) {
                                              $first_participant = reset($participants);
                                              $participant_info = sprintf(
                                                  '<tr><td style="padding:4px 8px;color:#666;">Teilnehmer:</td><td style="padding:4px 8px;">%s %s</td></tr>' .
                                                  '<tr><td style="padding:4px 8px;color:#666;">Geburtsdatum:</td><td style="padding:4px 8px;">%s</td></tr>',
                                                  $first_participant['vorname'] ?? '',
                                                  $first_participant['name'] ?? '',
                                                  $first_participant['geburtsdatum'] ?? ''
                                              );
                                              break;
                                          }
                                      }

                                      // Fallback: Vertragsdaten wenn keine participant_data
                                      if (empty($participant_info) && !empty($contract_data)) {
                                          $cd = (array) $contract_data;
                                          $participant_info = sprintf(
                                              '<tr><td style="padding:4px 8px;color:#666;">Teilnehmer:</td><td style="padding:4px 8px;">%s %s</td></tr>' .
                                              '<tr><td style="padding:4px 8px;color:#666;">Geburtsdatum:</td><td style="padding:4px 8px;">%s</td></tr>',
                                              $cd['vorname'] ?? '',
                                              $cd['nachname'] ?? '',
                                              $cd['geburtsdatum'] ?? ''
                                          );
                                      }

                                      // AHV-Nummer
                                      $ahv_row = '';
                                      if (!empty($contract_data['ahv_nummer'])) {
                                          $ahv_row = '<tr><td style="padding:4px 8px;color:#666;">AHV-Nummer:</td><td style="padding:4px 8px;">' . esc_html($contract_data['ahv_nummer']) . '</td></tr>';
                                      }

                                      // Event/Klasse Info
                                      $event_info = '';
                                      foreach ($order->get_items() as $item) {
                                          $event_title = $item->get_meta('_event_title_clean') ?: $item->get_meta('_event_title');
                                          $event_date = $item->get_meta('_event_date');
                                          $event_time = $item->get_meta('_event_time');
                                          $event_coach = $item->get_meta('_event_coach');
                                          if (!empty($event_title)) {
                                              $event_info .= '<tr><td style="padding:4px 8px;color:#666;">Klasse:</td><td style="padding:4px 8px;">' . esc_html($event_title) . '</td></tr>';
                                          }
                                          if (!empty($event_date)) {
                                              $event_info .= '<tr><td style="padding:4px 8px;color:#666;">Termin:</td><td style="padding:4px 8px;">' . esc_html($event_date) . (!empty($event_time) ? ' / ' . esc_html($event_time) : '') . '</td></tr>';
                                          }
                                          if (!empty($event_coach)) {
                                              $event_info .= '<tr><td style="padding:4px 8px;color:#666;">Coach:</td><td style="padding:4px 8px;">' . esc_html($event_coach) . '</td></tr>';
                                          }
                                          break;
                                      }

                                      $admin_message = '<div style="font-family:Arial,sans-serif;color:#333;max-width:600px;">'
                                          . '<h2 style="color:#1e3d59;margin-bottom:5px;">Bestandskunde — Vertrag abgeschlossen</h2>'
                                          . '<p style="color:#666;margin-top:0;">Bestellung #' . $order->get_order_number() . '</p>'
                                          . '<table style="width:100%;border-collapse:collapse;margin:15px 0;">'
                                          . '<tr><td colspan="2" style="padding:8px;background:#1e3d59;color:#fff;font-weight:bold;">Kundendaten</td></tr>'
                                          . '<tr><td style="padding:4px 8px;color:#666;">Vertragsunterzeichner:</td><td style="padding:4px 8px;">' . esc_html($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()) . '</td></tr>'
                                          . $participant_info
                                          . $ahv_row
                                          . '<tr><td style="padding:4px 8px;color:#666;">E-Mail:</td><td style="padding:4px 8px;"><a href="mailto:' . esc_attr($order->get_billing_email()) . '">' . esc_html($order->get_billing_email()) . '</a></td></tr>'
                                          . '<tr><td style="padding:4px 8px;color:#666;">Telefon:</td><td style="padding:4px 8px;">' . esc_html($order->get_billing_phone()) . '</td></tr>'
                                          . '<tr><td style="padding:4px 8px;color:#666;">Adresse:</td><td style="padding:4px 8px;">' . esc_html($order->get_billing_address_1() . ', ' . $order->get_billing_postcode() . ' ' . $order->get_billing_city()) . '</td></tr>'
                                          . '</table>'
                                          . '<table style="width:100%;border-collapse:collapse;margin:15px 0;">'
                                          . '<tr><td colspan="2" style="padding:8px;background:#1e3d59;color:#fff;font-weight:bold;">Vertragsdaten</td></tr>'
                                          . '<tr><td style="padding:4px 8px;color:#666;">Vertrag:</td><td style="padding:4px 8px;">' . esc_html($contract_details['trainingsumfang'] ?? '-') . '</td></tr>'
                                          . '<tr><td style="padding:4px 8px;color:#666;">Monatsbeitrag:</td><td style="padding:4px 8px;">' . esc_html($contract_details['vertrag_preis'] ?? '-') . ' CHF</td></tr>'
                                          . $event_info
                                          . '</table>'
                                          . '<p style="color:#666;font-size:12px;">Der Vertrag ist als PDF angehängt. Der Bestandskunde wurde im System als "Bestandskunde akzeptiert" markiert.</p>'
                                          . '<p style="margin-top:15px;"><a href="' . admin_url('post.php?post=' . $order_id . '&action=edit') . '" style="display:inline-block;background:#0066cc;color:#fff;padding:8px 16px;text-decoration:none;border-radius:4px;">Bestellung ansehen</a></p>'
                                          . '</div>';

                                      $admin_headers = [
                                          'Content-Type: text/html; charset=UTF-8',
                                          'From: ' . $sender_name . ' <' . $sender_email . '>',
                                          'Reply-To: ' . $sender_name . ' <' . $sender_email . '>',
                                      ];

                                      $sent_to = [];

                                      wp_mail($notification_email, $admin_subject, $admin_message, $admin_headers, $attachments);
                                      $sent_to[] = strtolower(trim($notification_email));

                                      // Coach-Email senden (nur wenn nicht bereits gesendet)
                                      foreach ($order->get_items() as $item) {
                                          $coach_email = $item->get_meta('_event_coach_email');
                                          if (!empty($coach_email) && !in_array(strtolower(trim($coach_email)), $sent_to)) {
                                              wp_mail($coach_email, $admin_subject, $admin_message, $admin_headers, $attachments);
                                              $sent_to[] = strtolower(trim($coach_email));
                                          }
                                      }
                                  }
                          }

                          // ZUSÄTZLICHE Benachrichtigungs-E-Mail bei Status "kdginitiiert" (Kündigung initiiert)
                          if ($new_status === 'wc-kdginitiiert') {
                              $notification_email = isset($email_settings['admin_notification_kdginitiiert'])
                                  ? $email_settings['admin_notification_kdginitiiert']
                                  : '';

                                  if (!empty($notification_email)) {
                                      $admin_subject = 'Kündigung initiiert';

                                      $contract_id = AB_Contract_Wizard::determine_contract_type($order);
                                      $contract_details = AB_Contract_Overview::get_contract_details($contract_id);

                                      // Teilnehmerdaten holen
                                      $participant_info = '';
                                      foreach ($order->get_items() as $item) {
                                          $participants = $item->get_meta('_event_participant_data');
                                          if (!empty($participants) && is_array($participants)) {
                                              $first_participant = reset($participants);
                                              $participant_vorname = $first_participant['vorname'] ?? '';
                                              $participant_nachname = $first_participant['name'] ?? '';
                                              $participant_geburtsdatum = $first_participant['geburtsdatum'] ?? '';

                                              $participant_info = sprintf(
                                                  'Teilnehmer: %s %s<br>' .
                                                  'Geburtsdatum: %s<br>',
                                                  $participant_vorname,
                                                  $participant_nachname,
                                                  $participant_geburtsdatum
                                              );
                                              break; // Nur den ersten Teilnehmer nehmen
                                          }
                                      }

                                      // AHV-Nummer aus Vertragsdaten
                                      $ahv_info = '';
                                      $contract_data = get_post_meta($order_id, '_ab_contract_data', true);
                                      if (!empty($contract_data['ahv_nummer'])) {
                                          $ahv_info = 'AHV-Nummer: ' . $contract_data['ahv_nummer'] . '<br>';
                                      }

                                      $admin_message = sprintf(
                                          'Hallo,<br><br>' .
                                          'Eine Kündigung wurde initiiert:<br><br>' .
                                          'Vertragsunterzeichner: %s %s<br>' .
                                          '%s' . // Teilnehmer-Info
                                          '%s' . // AHV-Info
                                          'Vertrag: %s<br>' .
                                          'Monatsbeitrag: %s €<br><br>' .
                                          'Bitte die Kündigung weiter bearbeiten.',
                                          $order->get_billing_first_name(),
                                          $order->get_billing_last_name(),
                                          $participant_info,
                                          $ahv_info,
                                          $contract_details['trainingsumfang'],
                                          $contract_details['vertrag_preis']
                                      );

        $admin_headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $sender_name . ' <' . $sender_email . '>',
            'Reply-To: ' . $sender_name . ' <' . $sender_email . '>',
        ];

        $sent_to = [];
        
        // Separates Mail an Admin
        wp_mail($notification_email, $admin_subject, $admin_message, $admin_headers);
        $sent_to[] = strtolower(trim($notification_email));

        // Zusätzlich: Finde Coach-Email und sende die gleiche Mail (nur wenn nicht bereits gesendet)
        foreach ($order->get_items() as $item) {
            $coach_email = $item->get_meta('_event_coach_email');
            if (!empty($coach_email) && !in_array(strtolower(trim($coach_email)), $sent_to)) {
                wp_mail($coach_email, $admin_subject, $admin_message, $admin_headers);
                $sent_to[] = strtolower(trim($coach_email));
            }
        }
    }
}

                          return $sent;

                      } catch (Exception $e) {
                          error_log('[AB Status Plugin] Fehler beim E-Mail-Versand: ' . $e->getMessage());
                          return false;
                      }
    }

    /**
     * Setzt die E-Mail-Marker zurück, wenn Status zurückgesetzt wird.
     * Damit kann bei erneutem Durchlauf die E-Mail wieder gesendet werden.
     */
    public static function reset_email_marker_on_status_change($order_id, $old_status, $new_status) {
        // Wenn Status auf "bkdvertrag" (Bestandskunde Vertrag) zurückgesetzt wird
        if ($new_status === 'bkdvertrag') {
            // Marker für bestandkundeakz löschen (für erneuten Vertragsabschluss)
            delete_post_meta($order_id, '_ab_email_sent_bestandkundeakz');
            // Marker für bkdvertrag löschen (damit E-Mail erneut gesendet wird)
            delete_post_meta($order_id, '_ab_email_sent_bkdvertrag');
            // Vertragsdaten zurücksetzen
            delete_post_meta($order_id, '_ab_contract_pdf');
            delete_post_meta($order_id, '_contract_status');

            error_log('[AB Status Plugin] E-Mail-Marker (bestandkundeakz + bkdvertrag), PDF-Pfad und Contract-Status zurückgesetzt für Order #' . $order_id);
        }

        // Wenn Status auf "vertragverschickt" zurückgesetzt wird
        if ($new_status === 'vertragverschickt') {
            // Marker für schuelerin löschen (für erneuten Vertragsabschluss)
            delete_post_meta($order_id, '_ab_email_sent_schuelerin');
            // Marker für vertragverschickt löschen (damit E-Mail erneut gesendet wird)
            delete_post_meta($order_id, '_ab_email_sent_vertragverschickt');
            // Vertragsdaten zurücksetzen
            delete_post_meta($order_id, '_ab_contract_pdf');
            delete_post_meta($order_id, '_contract_status');

            error_log('[AB Status Plugin] E-Mail-Marker (schuelerin + vertragverschickt), PDF-Pfad und Contract-Status zurückgesetzt für Order #' . $order_id);
        }
    }
}

// Hook für Status-Reset registrieren
add_action('woocommerce_order_status_changed', ['AB_Email_Sender', 'reset_email_marker_on_status_change'], 5, 3);
