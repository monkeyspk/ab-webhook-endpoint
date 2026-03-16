<?php
if (!defined('ABSPATH')) {
    exit;
}

class AB_Contract_Ajax_Handler {
    public static function init() {
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_scripts']);
        add_action('wp_ajax_handle_contract_wizard', [__CLASS__, 'handle_ajax_submission']);
        add_action('wp_ajax_nopriv_handle_contract_wizard', [__CLASS__, 'handle_ajax_submission']);
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
