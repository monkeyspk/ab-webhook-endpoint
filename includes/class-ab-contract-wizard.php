<?php
if (!defined('ABSPATH')) {
    exit;
}


class AB_Contract_Wizard {
  private static $steps = [
         1 => 'Persönliche Daten',
         2 => 'Vertragsbedingungen',
         3 => 'Zahlungsinformationen',
         4 => 'Zusammenfassung',
     ];

    public static function init() {
        require_once plugin_dir_path(__FILE__) . 'steps/class-ab-contract-step-1.php';
        require_once plugin_dir_path(__FILE__) . 'steps/class-ab-contract-step-2.php';
        require_once plugin_dir_path(__FILE__) . 'steps/class-ab-contract-step-3.php';
        require_once plugin_dir_path(__FILE__) . 'steps/class-ab-contract-step-4.php';
        require_once plugin_dir_path(__FILE__) . 'steps/class-ab-contract-step-5.php';  // NEUE ZEILE
        require_once plugin_dir_path(__FILE__) . 'steps/class-ab-contract-pdf.php';

        add_shortcode('ab_contract_wizard', [__CLASS__, 'render_wizard']);
        AB_Contract_Ajax_Handler::init();
    }

    public static function render_wizard($atts) {
        $step = isset($_GET['step']) ? intval($_GET['step']) : 1;
        $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;

        if (!$order_id) {
            return '<p>Keine gültige Bestellung angegeben.</p>';
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return '<p>Bestellung nicht gefunden.</p>';
        }

        if (!current_user_can('edit_shop_orders') &&
        !in_array($order->get_status(), ['vertragverschickt', 'schuelerin', 'bkdvertrag', 'bestandkundeakz'])) {
            // Die E-Mail-Adresse aus den Einstellungen holen
            $email_setting = get_option('ab_footer_row1_col3', 'M berlin@parkourone.com');

            // Den eigentlichen E-Mail-Teil extrahieren
            // Prüfen, ob im Format "M email@domain.com" oder ähnlich
            if (preg_match('/^M\s+(.+@.+\..+)$/', $email_setting, $matches)) {
                // E-Mail aus den Übereinstimmungen extrahieren
                $school_email = $matches[1];
            }
            // Falls es nur "muenster@parkourone.com" ohne Präfix ist
            elseif (filter_var($email_setting, FILTER_VALIDATE_EMAIL)) {
                $school_email = $email_setting;
            }
            // Fallback für andere Formate
            else {
                // Versuche, irgendeine E-Mail-Adresse im Text zu finden
                preg_match('/([a-zA-Z0-9._-]+@[a-zA-Z0-9._-]+\.[a-zA-Z0-9_-]+)/', $email_setting, $matches);
                $school_email = !empty($matches[1]) ? $matches[1] : 'info@parkourone.com';
            }

            // Nachricht mit der E-Mail-Adresse erstellen
            return '<p>Der Vertragslink ist nicht mehr gültig. Melde dich bitte bei <a href="mailto:' .
                   esc_attr($school_email) . '">' . esc_html($school_email) . '</a> wenn du weiterhin einsteigen möchtest.</p>';
        }


        if (!self::user_can_view_order($order)) {
            return '<p>Sie haben keine Berechtigung, diese Bestellung einzusehen.</p>';
        }

        $contract_id = self::determine_contract_type($order);
        if (!$contract_id) {
            return '<p>Entschuldigung, es konnte kein passender Vertragstyp gefunden werden. ' .
                   'Bitte kontaktiere den Support unter Angabe deiner Bestellnummer #' .
                   $order->get_order_number() . '.</p>';
        }

        ob_start();
        ?>
        <div class="contract-wizard-container">
            <div class="wizard-steps">
                <?php
                echo self::render_progress_bar($step);

                switch($step) {
                    case 1:
                        echo AB_Contract_Step_1::render($order, $contract_id);
                        break;
                    case 2:
                        echo AB_Contract_Step_2::render($order, $contract_id);
                        break;
                    case 3:
                        echo AB_Contract_Step_3::render($order, $contract_id);
                        break;
                    case 4:
                        echo AB_Contract_Step_4::render($order, $contract_id);
                        break;
                    case 5:                                                     // NEUER CASE
                        echo AB_Contract_Step_5::render($order, $contract_id); // NEUER CASE
                        break;                                                  // NEUER CASE
                    default:
                        echo AB_Contract_Step_1::render($order, $contract_id);
                }
                ?>
            </div>
            <?php echo AB_Contract_Overview::render($contract_id); ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function render_progress_bar($current_step) {
        $output = '<div class="contract-wizard-progress">';
        $output .= '<ul class="progress-steps">';

        foreach (self::$steps as $step_num => $step_name) {
            $class = $step_num === $current_step ? 'active' :
                    ($step_num < $current_step ? 'completed' : '');

            $output .= sprintf(
                '<li class="%s"><span class="step-number">%d</span><span class="step-name">%s</span></li>',
                $class,
                $step_num,
                esc_html($step_name)
            );
        }

        $output .= '</ul></div>';
        return $output;
    }

    public static function get_navigation_buttons($current_step) {
        $url_params = [
            'order_id' => $_GET['order_id'],
            'token' => $_GET['token'] ?? ''
        ];

        $output = '<div class="contract-wizard-navigation">';

        // Zurück-Button (außer bei Step 1)
        if ($current_step > 1) {
            $back_url = add_query_arg(array_merge($url_params, ['step' => $current_step - 1]));
            $output .= sprintf(
                '<a href="%s" class="button">← Zurück</a>',
                esc_url($back_url)
            );
        } else {
            $output .= '<div></div>'; // Platzhalter für Flexbox-Layout
        }

        // Weiter/Abschließen-Button
        if ($current_step < 4) {
            $output .= '<button type="submit" class="button button-primary">Weiter →</button>';
        } else {
            $output .= '<button type="submit" class="button button-primary">Vertrag abschließen</button>';
        }

        $output .= '</div>';
        return $output;
    }




    public static function build_pdf_preview($order, $contract_data) {
        ob_start();
        ?>
        <div class="pdf-preview">
            <h4>Vertrag Nr. <?php echo $order->get_id(); ?></h4>
            <p>Dies ist eine Vorschau des Vertrags. Das finale PDF wird alle Details beinhalten.</p>
            <p><em>Nach Abschluss erhalten Sie das vollständige Dokument per E-Mail.</em></p>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function user_can_view_order(\WC_Order $order) {
      if (!current_user_can('edit_shop_orders') &&
        !in_array($order->get_status(), ['vertragverschickt', 'schuelerin', 'bkdvertrag', 'bestandkundeakz'])) {
        error_log('Bestellung ' . $order->get_id() . ' hat keinen gültigen Wizard-Status');
  return false;
}

        $user_id = get_current_user_id();
        if ($user_id && ($user_id == $order->get_user_id() || current_user_can('edit_shop_orders'))) {
            return true;
        }

        $provided_token = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';
        $saved_token = get_post_meta($order->get_id(), '_ab_contract_token', true);

        if (empty($saved_token)) {
            error_log('Kein Token in der Bestellung ' . $order->get_id() . ' gefunden');
            return false;
        }

        if (empty($provided_token)) {
            error_log('Kein Token in der URL für Bestellung ' . $order->get_id() . ' gefunden');
            return false;
        }

        if ($provided_token === $saved_token) {
            return true;
        }

        error_log('Token stimmt nicht überein für Bestellung ' . $order->get_id());
        return false;
    }


    public static function determine_contract_type($order) {
        // Manueller Override: Bestandskunden haben _ab_contract_type_id statt _event_course_id
        $manual_contract_type = get_post_meta($order->get_id(), '_ab_contract_type_id', true);
        if (!empty($manual_contract_type)) {
            $contract_post = get_post($manual_contract_type);
            if ($contract_post && $contract_post->post_type === 'ab_contract_type') {
                error_log('[AB Contract] Manueller Vertragstyp gefunden via _ab_contract_type_id: ' . $manual_contract_type);
                return (int) $manual_contract_type;
            }
        }

        $event_description = '';
        $event_course_id = '';

        // Debug
        error_log('Determining contract type for order: ' . $order->get_id());

        // Event-Daten aus der Bestellung holen
        foreach ($order->get_items() as $item) {
            $item_event_description = $item->get_meta('_event_description', true);
            $item_course_id = $item->get_meta('_event_course_id', true);

            error_log('Item event description: ' . $item_event_description);
            error_log('Item course_id: ' . $item_course_id);

            if ($item_event_description) {
                $event_description = $item_event_description;
            }
            if ($item_course_id) {
                $event_course_id = $item_course_id;
            }
            if ($event_description || $event_course_id) {
                break;
            }
        }

        // PRIMÄR: Suche nach course_id (robust gegen Umbenennungen)
        if (!empty($event_course_id)) {
            error_log('Suche Vertragstyp nach course_id: ' . $event_course_id);

            $args = [
                'post_type' => 'ab_contract_type',
                'meta_query' => [
                    [
                        'key' => '_ab_course_id',
                        'value' => $event_course_id,
                        'compare' => '='
                    ]
                ],
                'posts_per_page' => -1
            ];

            $contracts = get_posts($args);

            if (!empty($contracts)) {
                $contract = $contracts[0];
                error_log("Vertragstyp gefunden via course_id: ID {$contract->ID}, Titel: " . get_the_title($contract->ID));
                return $contract->ID;
            }

            error_log('Kein Vertragstyp mit course_id ' . $event_course_id . ' gefunden - Fallback auf event_description');
        }

        // FALLBACK: Suche nach event_description (für Kompatibilität)
        if (empty($event_description)) {
            error_log('No event description found in order');
            return false;
        }

        // HTML-Entities dekodieren
        $decoded_event_description = html_entity_decode($event_description);
        error_log('Decoded event description: ' . $decoded_event_description);

        $args = [
            'post_type' => 'ab_contract_type',
            'meta_query' => [
                [
                    'key' => '_ab_event_description',
                    'value' => $decoded_event_description,
                    'compare' => '=' // Exakte Übereinstimmung
                ]
            ],
            'posts_per_page' => -1
        ];

        $contracts = get_posts($args);
        error_log('Gefundene Verträge via event_description: ' . count($contracts));

        foreach($contracts as $contract) {
            $title = get_the_title($contract->ID);
            $desc = get_post_meta($contract->ID, '_ab_event_description', true);
            error_log("Gefundener Vertrag ID {$contract->ID}: Titel: {$title}, Event: {$desc}");
        }

        return !empty($contracts) ? $contracts[0]->ID : false;
    }


    // In class-ab-contract-wizard.php

    public static function finalize_contract(\WC_Order $order) {
        error_log('Starting contract finalization for order ' . $order->get_id());

        // FIX: Idempotenz-Prüfung - Wurde der Vertrag bereits abgeschlossen?
        $contract_status = get_post_meta($order->get_id(), '_contract_status', true);
        if ($contract_status === 'completed') {
            error_log('[AB Contract] Vertrag bereits abgeschlossen für Order ' . $order->get_id() . ' - Abbruch');
            return true; // Return true damit keine Fehlermeldung kommt
        }

        // FIX: Lock setzen um Race Conditions zu verhindern
        $lock_key = '_ab_contract_processing_' . $order->get_id();
        $lock_value = get_transient($lock_key);
        if ($lock_value) {
            error_log('[AB Contract] Vertrag wird bereits verarbeitet für Order ' . $order->get_id() . ' - Abbruch');
            return true;
        }
        set_transient($lock_key, true, 30); // 30 Sekunden Lock

        // Prüfe contract_data
        $contract_data = get_post_meta($order->get_id(), '_ab_contract_data', true);
        if (empty($contract_data)) {
            error_log('No contract data found for order ' . $order->get_id());
            delete_transient($lock_key);
            return false;
        }
        error_log('Contract data found: ' . print_r($contract_data, true));

        // PDF erstellen
        $html = AB_Contract_PDF::build_html($order, $contract_data);
        if (empty($html)) {
            error_log('Failed to build HTML for PDF');
            return false;
        }

        // PDF generieren
        $pdf_path = AB_Contract_PDF::generate($html, $order->get_id());
        if (!$pdf_path) {
            error_log('Failed to generate PDF');
            return false;
        }

        // Metadaten aktualisieren
        update_post_meta($order->get_id(), '_ab_contract_pdf', $pdf_path);
        update_post_meta($order->get_id(), '_contract_status', 'completed');

        // Bankdaten auch als separate Order-Meta-Felder speichern für WooCommerce-Ansicht
        if (!empty($contract_data['iban'])) {
            update_post_meta($order->get_id(), '_billing_iban', $contract_data['iban']);
        }
        if (!empty($contract_data['bic'])) {
            update_post_meta($order->get_id(), '_billing_bic', $contract_data['bic']);
        }
        if (!empty($contract_data['kontoInhaber'])) {
            update_post_meta($order->get_id(), '_billing_kontoinhaber', $contract_data['kontoInhaber']);
        }
        if (!empty($contract_data['bank_name'])) {
            update_post_meta($order->get_id(), '_billing_bank_name', $contract_data['bank_name']);
        }

        $current_status = $order->get_status();
        if ($current_status === 'bkdvertrag') {
            $order->update_status('bestandkundeakz', 'Bestandskunde-Vertrag abgeschlossen.');
            AB_Email_Sender::send_status_email($order->get_id(), 'wc-bestandkundeakz');
        } else {
            $order->update_status('schuelerin', 'Vertrag abgeschlossen.');
            AB_Email_Sender::send_status_email($order->get_id(), 'wc-schuelerin');
        }

        // Lock wieder freigeben
        delete_transient($lock_key);

        error_log('Contract successfully finalized for order ' . $order->get_id());
        return true;
    }




}
