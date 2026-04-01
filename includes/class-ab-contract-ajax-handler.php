<?php
if (!defined('ABSPATH')) {
    exit;
}

class AB_Contract_Ajax_Handler {
    public static function init() {
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_scripts']);
        add_action('wp_ajax_handle_contract_wizard', [__CLASS__, 'handle_ajax_submission']);
        add_action('wp_ajax_nopriv_handle_contract_wizard', [__CLASS__, 'handle_ajax_submission']);
        add_action('wp_ajax_ab_send_contract_code', [__CLASS__, 'handle_send_contract_code']);
        add_action('wp_ajax_nopriv_ab_send_contract_code', [__CLASS__, 'handle_send_contract_code']);
        add_action('wp_ajax_ab_verify_contract_code', [__CLASS__, 'handle_verify_contract_code']);
        add_action('wp_ajax_nopriv_ab_verify_contract_code', [__CLASS__, 'handle_verify_contract_code']);
    }

    public static function enqueue_scripts() {
        global $post;

        if (!is_object($post)) {
            return;
        }

        if (has_shortcode($post->post_content, 'ab_contract_wizard')) {
            wp_enqueue_script(
                'ab-contract-wizard',
                plugins_url('assets/js/contract-wizard.js', dirname(__FILE__)),
                ['jquery'],
                '1.0.0',
                true
            );
            wp_localize_script('ab-contract-wizard', 'contractWizard', [
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('contract_wizard_nonce')
            ]);
        }
    }

    public static function handle_ajax_submission() {
        // Log eingehender Daten zur Fehleranalyse
        error_log('AJAX Submission empfangen:');
        error_log(print_r($_POST, true));

        // Nonce-Überprüfung
        if (!check_ajax_referer('contract_wizard_nonce', 'nonce', false)) {
            error_log('Nonce-Verifikation fehlgeschlagen');
            wp_send_json_error(['message' => 'Sicherheitscheck fehlgeschlagen']);
            return;
        }

        // Eingehende Parameter validieren
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $current_step = isset($_POST['current_step']) ? intval($_POST['current_step']) : 1;
        $action = isset($_POST['form_action']) ? sanitize_text_field($_POST['form_action']) : '';

        // Bestellung validieren
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(['message' => 'Ungültige Bestellung']);
            return;
        }

        // Vertragsdaten initialisieren
        $contract_data = get_post_meta($order->get_id(), '_ab_contract_data', true);
        if (!is_array($contract_data)) {
            $contract_data = [];
        }

        // Aktionen basierend auf dem Schritt
        switch ($action) {
          case 'save_step1':
              $contract_data['anrede'] = sanitize_text_field($_POST['anrede'] ?? '');
              $contract_data['vorname'] = sanitize_text_field($_POST['vorname'] ?? '');
              $contract_data['nachname'] = sanitize_text_field($_POST['nachname'] ?? '');
              $contract_data['geburtsdatum'] = sanitize_text_field($_POST['geburtsdatum'] ?? '');
              $contract_data['telefon'] = sanitize_text_field($_POST['telefon'] ?? '');
              $contract_data['email'] = sanitize_email($_POST['email'] ?? '');
              $contract_data['strasse'] = sanitize_text_field($_POST['strasse'] ?? '');
              $contract_data['hausnummer'] = sanitize_text_field($_POST['hausnummer'] ?? '');
              $contract_data['plz'] = sanitize_text_field($_POST['plz'] ?? '');
              $contract_data['ort'] = sanitize_text_field($_POST['ort'] ?? '');
              $contract_data['besonderheiten'] = sanitize_textarea_field($_POST['besonderheiten'] ?? '');

              if (!empty($_POST['ahv_nummer'])) {
                  $contract_data['ahv_nummer'] = sanitize_text_field($_POST['ahv_nummer']);
              }

              // Falls minderjährig, auch Erziehungsberechtigten speichern
              if (!empty($_POST['erziehungsberechtigter_name'])) {
                  $contract_data['erziehungsberechtigter_name'] = sanitize_text_field($_POST['erziehungsberechtigter_name']);
                  $contract_data['erziehungsberechtigter_telefon'] = sanitize_text_field($_POST['erziehungsberechtigter_telefon']);
                  $contract_data['erziehungsberechtigter_email'] = sanitize_email($_POST['erziehungsberechtigter_email']);
              }

              break;

              case 'save_step2':
    // Step 2 speichert nichts mehr, aber muss korrekt weiterleiten
    break;




    case 'save_step3':
        $contract_data['kontoInhaber'] = sanitize_text_field($_POST['kontoInhaber'] ?? '');
        $contract_data['bank_name'] = sanitize_text_field($_POST['bank_name'] ?? '');
        $contract_data['iban'] = sanitize_text_field($_POST['iban'] ?? '');
        $contract_data['bic'] = sanitize_text_field($_POST['bic'] ?? '');
        break;



        case 'save_step4':
            error_log('Processing save_step4');
            $contract_id = isset($_POST['contract_id']) ? intval($_POST['contract_id']) : 0;

            error_log('Contract ID from POST: ' . $contract_id);
            error_log('Order ID from POST: ' . $order_id);
            error_log('POST data: ' . print_r($_POST, true));

            if (!$contract_id) {
                error_log('Missing contract_id in save_step4');
                wp_send_json_error(['message' => 'Keine Vertrags-ID gefunden']);
                return;
            }

            if (AB_Contract_Wizard::finalize_contract($order)) {
               // Hole die URL der Vertragsseite
               $vertrag_page = get_page_by_path('vertrag');
               if ($vertrag_page) {
                   $base_url = get_permalink($vertrag_page->ID);
                   $success_url = add_query_arg([
                       'step' => '5',
                       'order_id' => $order_id,
                       'contract_id' => $contract_id,
                       'token' => isset($_POST['token']) ? $_POST['token'] : ''
                   ], $base_url);
                   error_log('Redirecting to Step 5: ' . $success_url);
                   wp_send_json_success(['redirect' => $success_url]);
               }
           } else {
                error_log('Contract finalization failed for order ' . $order->get_id());
                wp_send_json_error(['message' => 'Fehler beim Finalisieren des Vertrags']);
            }
            return;

            default:
                wp_send_json_error(['message' => 'Ungültige Aktion']);
                return;
        }

        // Vertragsdaten speichern
        update_post_meta($order->get_id(), '_ab_contract_data', $contract_data);

        // Nächste URL generieren
        $token = isset($_POST['token']) ? sanitize_text_field($_POST['token']) : '';

        // **AUF VERTRAG PAGE UMLEITNE
        $vertrag_page = get_page_by_path('vertrag');
        if ($vertrag_page) {
            $base_url = get_permalink($vertrag_page->ID);
        } else {
            // Fehlerbehandlung, falls die Seite nicht gefunden wird
            wp_send_json_error(['message' => 'Vertragsseite nicht gefunden']);
            return;
        }

        $next_url = add_query_arg([
            'order_id' => $order_id,
            'step' => $current_step + 1,
            'token' => $token
        ], $base_url);

        wp_send_json_success(['redirect' => $next_url]);
    }

    /**
     * Schritt 1: 6-stelligen Code per E-Mail senden
     */
    public static function handle_send_contract_code() {
        if (!check_ajax_referer('ab_contract_code_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Sicherheitscheck fehlgeschlagen. Bitte lade die Seite neu.']);
            return;
        }

        $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
        if (empty($email) || !is_email($email)) {
            wp_send_json_error(['message' => 'Bitte gib eine gültige E-Mail-Adresse ein.']);
            return;
        }

        // Rate-Limiting: Max 5 Anfragen pro Stunde pro E-Mail
        $rate_key = 'ab_code_send_' . md5($email);
        $attempts = get_transient($rate_key);
        if ($attempts !== false && intval($attempts) >= 5) {
            wp_send_json_error(['message' => 'Du hast bereits mehrere Codes angefordert. Bitte versuche es in einer Stunde erneut oder kontaktiere uns direkt.']);
            return;
        }

        // Rate-Limit Zähler erhöhen (IMMER — gegen Enumeration)
        $current_attempts = $attempts !== false ? intval($attempts) : 0;
        set_transient($rate_key, $current_attempts + 1, HOUR_IN_SECONDS);

        // Offene Bestellungen mit dieser E-Mail suchen
        $orders = wc_get_orders([
            'billing_email' => $email,
            'status'        => ['vertragverschickt', 'bkdvertrag'],
            'limit'         => 5,
            'orderby'       => 'date',
            'order'         => 'DESC',
        ]);

        // Gleiche Meldung ob Bestellung gefunden oder nicht (Anti-Enumeration)
        $success_msg = 'Falls ein offener Vertrag mit dieser E-Mail-Adresse existiert, erhältst du in Kürze einen Code per E-Mail.';

        if (empty($orders)) {
            wp_send_json_success(['message' => $success_msg]);
            return;
        }

        // 6-stelligen Code generieren und per Transient speichern (15 Min gültig)
        $code = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
        $code_key = 'ab_contract_code_' . md5(strtolower($email));
        set_transient($code_key, [
            'code'      => $code,
            'email'     => strtolower($email),
            'attempts'  => 0,
        ], 15 * MINUTE_IN_SECONDS);

        // Code per E-Mail senden
        $first_name = $orders[0]->get_billing_first_name();
        $email_settings = get_option('ab_email_settings', []);
        $sender_email = !empty($email_settings['sender_email']) ? $email_settings['sender_email'] : get_option('admin_email');
        $sender_name = !empty($email_settings['sender_name']) ? $email_settings['sender_name'] : get_bloginfo('name');
        $logo_url = $email_settings['logo_url'] ?? '';

        $subject = 'Dein Zugangscode — ParkourONE';

        $body = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0; padding:0; background:#f5f5f5;">';
        $body .= '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5; padding:20px;">';
        $body .= '<tr><td align="center">';
        $body .= '<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff; border-radius:8px; overflow:hidden;">';

        if ($logo_url) {
            $body .= '<tr><td style="padding:20px; text-align:center; background:#1e3d59;">';
            $body .= '<img src="' . esc_url($logo_url) . '" alt="Logo" style="max-width:200px; height:auto;">';
            $body .= '</td></tr>';
        }

        $body .= '<tr><td style="padding:30px; font-family:Arial,sans-serif; font-size:15px; line-height:1.6; color:#333;">';
        $body .= '<p>Hallo ' . esc_html($first_name) . ',</p>';
        $body .= '<p>dein Zugangscode für den Vertrag lautet:</p>';
        $body .= '<div style="text-align:center; margin:25px 0;">';
        $body .= '<span style="display:inline-block; font-size:32px; font-weight:bold; letter-spacing:8px; background:#f0f6fc; color:#1e3d59; padding:15px 30px; border-radius:8px; border:2px solid #c8d7e1;">' . esc_html($code) . '</span>';
        $body .= '</div>';
        $body .= '<p>Gib diesen Code auf der Webseite ein, um deinen Vertrag zu öffnen. Der Code ist <strong>15 Minuten</strong> gültig.</p>';
        $body .= '<p style="color:#888; font-size:13px;">Falls du keinen Code angefordert hast, kannst du diese E-Mail ignorieren.</p>';
        $body .= '<p style="margin-top:20px;">ONE for All &amp; All for ONE<br>Viele Grüsse,<br><strong>Dein Team von ParkourONE</strong></p>';
        $body .= '</td></tr>';
        $body .= '</table></td></tr></table></body></html>';

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $sender_name . ' <' . $sender_email . '>',
        ];

        wp_mail($email, $subject, $body, $headers);
        error_log('[AB Contract] Zugangscode gesendet an ' . $email);

        wp_send_json_success(['message' => $success_msg]);
    }

    /**
     * Schritt 2: Code verifizieren und zum Vertrag weiterleiten
     */
    public static function handle_verify_contract_code() {
        if (!check_ajax_referer('ab_contract_code_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Sicherheitscheck fehlgeschlagen. Bitte lade die Seite neu.']);
            return;
        }

        $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
        $code = isset($_POST['code']) ? sanitize_text_field($_POST['code']) : '';

        if (empty($email) || empty($code)) {
            wp_send_json_error(['message' => 'Bitte E-Mail und Code eingeben.']);
            return;
        }

        // Gespeicherten Code laden
        $code_key = 'ab_contract_code_' . md5(strtolower($email));
        $stored = get_transient($code_key);

        if ($stored === false) {
            wp_send_json_error(['message' => 'Der Code ist abgelaufen. Bitte fordere einen neuen Code an.']);
            return;
        }

        // Brute-Force-Schutz: Max 5 Versuche pro Code
        if ($stored['attempts'] >= 5) {
            delete_transient($code_key);
            wp_send_json_error(['message' => 'Zu viele Fehlversuche. Bitte fordere einen neuen Code an.']);
            return;
        }

        // Falscher Code → Versuchszähler hochsetzen
        if ($stored['code'] !== $code) {
            $stored['attempts']++;
            $remaining_ttl = max(1, 15 * MINUTE_IN_SECONDS); // TTL beibehalten
            set_transient($code_key, $stored, $remaining_ttl);
            $remaining = 5 - $stored['attempts'];
            wp_send_json_error(['message' => 'Falscher Code. Noch ' . $remaining . ' Versuche übrig.']);
            return;
        }

        // Code korrekt — Transient löschen (einmalig nutzbar)
        delete_transient($code_key);

        // Bestellung finden
        $orders = wc_get_orders([
            'billing_email' => $email,
            'status'        => ['vertragverschickt', 'bkdvertrag'],
            'limit'         => 1,
            'orderby'       => 'date',
            'order'         => 'DESC',
        ]);

        if (empty($orders)) {
            wp_send_json_error(['message' => 'Keine offene Bestellung gefunden. Bitte kontaktiere uns direkt.']);
            return;
        }

        $order = $orders[0];
        $order_id = $order->get_id();

        // Neuen Token generieren für die Session
        $token = wp_generate_password(32, false);
        update_post_meta($order_id, '_ab_contract_token', $token);

        $redirect_url = add_query_arg([
            'order_id' => $order_id,
            'step'     => 1,
            'token'    => $token,
        ], home_url('/vertrag/'));

        $order->add_order_note('Kunde hat Vertragszugang per Code-Verifizierung erhalten.');
        error_log('[AB Contract] Code verifiziert für Order #' . $order_id . ' — Weiterleitung zum Wizard');

        wp_send_json_success(['redirect' => $redirect_url]);
    }
}


function duplicate_ab_contract($post_id) {
    $post = get_post($post_id);

    if (!$post || $post->post_type !== 'ab_contract_type') {
        return;
    }

    $new_post = array(
        'post_title'    => 'Kopie von ' . $post->post_title,
        'post_content'  => $post->post_content,
        'post_status'   => 'draft',
        'post_type'     => $post->post_type,
        'post_author'   => get_current_user_id(),
    );

    $new_post_id = wp_insert_post($new_post);

    if ($new_post_id) {
        // Kopiere alle Metadaten
        $meta_data = get_post_meta($post_id);
        foreach ($meta_data as $key => $value) {
            update_post_meta($new_post_id, $key, maybe_unserialize($value[0]));
        }
    }

    return $new_post_id;
}

function handle_duplicate_contract() {
    if (!isset($_GET['post']) || !wp_verify_nonce($_GET['_wpnonce'], 'duplicate_contract_nonce')) {
        wp_die('Ungültige Anfrage');
    }

    $post_id = intval($_GET['post']);
    $new_post_id = duplicate_ab_contract($post_id);

    if ($new_post_id) {
        wp_redirect(admin_url('post.php?post=' . $new_post_id . '&action=edit'));
        exit;
    } else {
        wp_die('Fehler beim Duplizieren des Vertrags');
    }
}
add_action('admin_post_duplicate_contract', 'handle_duplicate_contract');
