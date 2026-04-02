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
            return self::render_resend_link_form();
        }


        if (!self::user_can_view_order($order)) {
            return self::render_resend_link_form();
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


    /**
     * Self-Service: Code per E-Mail anfordern → Code eingeben → Weiterleitung zum Vertrag
     */
    private static function render_resend_link_form() {
        $nonce = wp_create_nonce('ab_contract_code_nonce');
        $ajax_url = esc_url(admin_url('admin-ajax.php'));
        ob_start();
        ?>
        <div class="ab-code-container" style="max-width:460px; margin:40px auto; font-family:Arial,sans-serif;">
            <div style="background:#fff; border-radius:8px; box-shadow:0 2px 8px rgba(0,0,0,0.1); padding:30px;">

                <!-- Step 1: E-Mail eingeben -->
                <div id="ab-code-step1">
                    <h2 style="margin-top:0; color:#1e3d59;">Vertrag öffnen</h2>
                    <p style="color:#555; line-height:1.6;">Dein Vertragslink ist nicht mehr gültig. Kein Problem — gib deine E-Mail-Adresse ein und wir senden dir einen Zugangscode.</p>

                    <div style="margin-bottom:15px;">
                        <label for="ab-code-email" style="display:block; margin-bottom:5px; font-weight:bold; color:#333;">E-Mail-Adresse</label>
                        <input type="email" id="ab-code-email" placeholder="deine@email.ch"
                               style="width:100%; padding:10px 12px; border:1px solid #ccc; border-radius:5px; font-size:15px; box-sizing:border-box;" />
                    </div>
                    <button id="ab-code-send" type="button"
                            style="width:100%; padding:12px; background-color:#0066cc; color:#fff; border:none; border-radius:5px; font-size:16px; cursor:pointer;">
                        Code anfordern
                    </button>
                </div>

                <!-- Step 2: Code eingeben -->
                <div id="ab-code-step2" style="display:none;">
                    <h2 style="margin-top:0; color:#1e3d59;">Code eingeben</h2>
                    <p style="color:#555; line-height:1.6;">Wir haben dir einen 6-stelligen Code per E-Mail gesendet. Gib ihn hier ein:</p>

                    <div style="margin-bottom:15px; text-align:center;">
                        <input type="text" id="ab-code-input" maxlength="6" placeholder="000000" autocomplete="one-time-code" inputmode="numeric"
                               style="width:200px; padding:12px; border:2px solid #c8d7e1; border-radius:8px; font-size:24px; font-weight:bold; letter-spacing:6px; text-align:center;" />
                    </div>
                    <button id="ab-code-verify" type="button"
                            style="width:100%; padding:12px; background-color:#0066cc; color:#fff; border:none; border-radius:5px; font-size:16px; cursor:pointer;">
                        Vertrag öffnen
                    </button>
                    <p style="margin-top:12px; text-align:center;">
                        <a href="#" id="ab-code-resend" style="color:#0066cc; font-size:13px; text-decoration:none;">Code nochmals senden</a>
                    </p>
                </div>

                <div id="ab-code-message" style="display:none; margin-top:15px; padding:12px; border-radius:5px;"></div>

                <p style="margin-top:20px; font-size:13px; color:#888;">
                    Verwende die E-Mail-Adresse, mit der du angemeldet wurdest. Der Code ist 15 Minuten gültig.
                </p>
            </div>
        </div>

        <script>
        (function() {
            var ajaxUrl = '<?php echo $ajax_url; ?>';
            var nonce = '<?php echo $nonce; ?>';
            var step1 = document.getElementById('ab-code-step1');
            var step2 = document.getElementById('ab-code-step2');
            var emailInput = document.getElementById('ab-code-email');
            var codeInput = document.getElementById('ab-code-input');
            var sendBtn = document.getElementById('ab-code-send');
            var verifyBtn = document.getElementById('ab-code-verify');
            var resendLink = document.getElementById('ab-code-resend');
            var msg = document.getElementById('ab-code-message');
            var storedEmail = '';

            function showMsg(text, type) {
                msg.style.display = 'block';
                msg.textContent = text;
                if (type === 'success') { msg.style.background = '#d4edda'; msg.style.color = '#155724'; }
                else if (type === 'error') { msg.style.background = '#f8d7da'; msg.style.color = '#721c24'; }
                else { msg.style.background = '#fff3cd'; msg.style.color = '#856404'; }
            }

            function hideMsg() { msg.style.display = 'none'; }

            function setLoading(btn, loading, originalText) {
                btn.disabled = loading;
                btn.textContent = loading ? 'Bitte warten...' : originalText;
                btn.style.opacity = loading ? '0.7' : '1';
            }

            function ajax(action, data, callback) {
                var params = 'action=' + action + '&nonce=' + nonce;
                for (var k in data) { params += '&' + k + '=' + encodeURIComponent(data[k]); }
                var xhr = new XMLHttpRequest();
                xhr.open('POST', ajaxUrl);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onload = function() {
                    try { callback(JSON.parse(xhr.responseText)); } catch(e) { callback(null); }
                };
                xhr.onerror = function() { callback(null); };
                xhr.send(params);
            }

            function sendCode() {
                var email = emailInput.value.trim();
                if (!email || email.indexOf('@') === -1) { showMsg('Bitte gib eine gültige E-Mail-Adresse ein.', 'warn'); return; }
                hideMsg();
                storedEmail = email;
                setLoading(sendBtn, true, 'Code anfordern');

                ajax('ab_send_contract_code', { email: email }, function(resp) {
                    setLoading(sendBtn, false, 'Code anfordern');
                    if (!resp) { showMsg('Verbindungsfehler. Bitte versuche es erneut.', 'error'); return; }
                    if (resp.success) {
                        step1.style.display = 'none';
                        step2.style.display = 'block';
                        showMsg(resp.data.message, 'success');
                        setTimeout(function() { codeInput.focus(); }, 100);
                    } else {
                        showMsg(resp.data.message, 'error');
                    }
                });
            }

            function verifyCode() {
                var code = codeInput.value.trim().replace(/\s/g, '');
                if (code.length !== 6) { showMsg('Bitte gib den vollständigen 6-stelligen Code ein.', 'warn'); return; }
                hideMsg();
                setLoading(verifyBtn, true, 'Vertrag öffnen');

                ajax('ab_verify_contract_code', { email: storedEmail, code: code }, function(resp) {
                    setLoading(verifyBtn, false, 'Vertrag öffnen');
                    if (!resp) { showMsg('Verbindungsfehler. Bitte versuche es erneut.', 'error'); return; }
                    if (resp.success && resp.data.redirect) {
                        showMsg('Code korrekt — du wirst weitergeleitet...', 'success');
                        setTimeout(function() { window.location.href = resp.data.redirect; }, 500);
                    } else {
                        showMsg(resp.data.message, 'error');
                        codeInput.value = '';
                        codeInput.focus();
                    }
                });
            }

            sendBtn.addEventListener('click', sendCode);
            emailInput.addEventListener('keypress', function(e) { if (e.key === 'Enter') sendCode(); });
            verifyBtn.addEventListener('click', verifyCode);
            codeInput.addEventListener('keypress', function(e) { if (e.key === 'Enter') verifyCode(); });
            codeInput.addEventListener('input', function() { this.value = this.value.replace(/[^0-9]/g, ''); });
            resendLink.addEventListener('click', function(e) {
                e.preventDefault();
                codeInput.value = '';
                hideMsg();
                setLoading(sendBtn, true, 'Code anfordern');
                ajax('ab_send_contract_code', { email: storedEmail }, function(resp) {
                    setLoading(sendBtn, false, 'Code anfordern');
                    if (resp && resp.success) { showMsg('Neuer Code wurde gesendet. Prüfe dein Postfach.', 'success'); }
                    else if (resp) { showMsg(resp.data.message, 'error'); }
                    else { showMsg('Verbindungsfehler.', 'error'); }
                });
            });
        })();
        </script>
        <?php
        return ob_get_clean();
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
        update_post_meta($order->get_id(), '_ab_contract_completion_date', current_time('mysql'));

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
        if (!empty($contract_data['ahv_nummer'])) {
            update_post_meta($order->get_id(), '_billing_ahv_nummer', $contract_data['ahv_nummer']);
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
