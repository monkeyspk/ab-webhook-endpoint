<?php
if (!defined('ABSPATH')) {
    exit;
}

class AB_Contract_Step_4 {
    public static function render($order, $contract_id) {
        $contract_data = get_post_meta($order->get_id(), '_ab_contract_data', true) ?: [];

        ob_start();
        ?>
        <form method="post" class="contract-wizard-form">
             <input type="hidden" name="form_action" value="save_step4">
             <input type="hidden" name="current_step" value="4">
             <input type="hidden" name="order_id" value="<?php echo esc_attr($order->get_id()); ?>">
             <input type="hidden" name="contract_id" value="<?php echo esc_attr($contract_id); ?>">
             <input type="hidden" name="token" value="<?php echo esc_attr(isset($_GET['token']) ? $_GET['token'] : ''); ?>">
             <?php wp_nonce_field('contract_wizard_nonce', 'nonce'); ?>


            <!-- Persönliche Daten Box -->
            <div class="accordion-step4">
                <h3>Persönliche Daten</h3><a href="?step=1&order_id=<?php echo esc_attr($order->get_id()); ?>" class="edit-link">✎ Bearbeiten</a>
                <div class="accordion-step4-content open">
                    <div class="form-row-flex">
                        <div class="form-col">
                            <strong>Anrede</strong>
                            <div><?php echo esc_html($contract_data['anrede'] ?? ''); ?></div>
                        </div>
                        <div class="form-col">
                            <strong>Name</strong>
                            <div><?php echo esc_html(($contract_data['vorname'] ?? '') . ' ' . ($contract_data['nachname'] ?? '')); ?></div>
                        </div>
                    </div>

                    <div class="form-row-flex">
                        <div class="form-col">
                            <strong>Straße & Hausnummer</strong>
                            <div><?php echo esc_html(($contract_data['strasse'] ?? '') . ' ' . ($contract_data['hausnummer'] ?? '')); ?></div>
                        </div>
                        <div class="form-col">
                            <strong>PLZ & Ort</strong>
                            <div><?php echo esc_html(($contract_data['plz'] ?? '') . ' ' . ($contract_data['ort'] ?? '')); ?></div>
                        </div>
                    </div>

                    <div class="form-row-flex">
                        <div class="form-col">
                            <strong>Telefon</strong>
                            <div><?php echo esc_html($contract_data['telefon'] ?? ''); ?></div>
                        </div>
                        <div class="form-col">
                            <strong>E-Mail</strong>
                            <div><?php echo esc_html($contract_data['email'] ?? ''); ?></div>
                        </div>
                    </div>

                    <?php if (!empty($contract_data['geburtsdatum'])): ?>
                        <div class="form-row-flex">
                            <div class="form-col">
                                <strong>Geburtsdatum</strong>
                                <div><?php echo esc_html($contract_data['geburtsdatum']); ?></div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (get_option('ab_ahv_enabled', '0') === '1' && !empty($contract_data['ahv_nummer'])): ?>
                        <div class="form-row-flex">
                            <div class="form-col">
                                <strong>AHV-Nummer</strong>
                                <div><?php echo esc_html($contract_data['ahv_nummer']); ?></div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($contract_data['erziehungsberechtigter_name'])): ?>
                <!-- Erziehungsberechtigte Box -->
                <div class="accordion-step4">
                    <h3>Erziehungsberechtigte</h3>
                    <div class="accordion-step4-content open">
                        <div class="form-row-flex">
                            <div class="form-col">
                                <strong>Name</strong>
                                <div><?php echo esc_html($contract_data['erziehungsberechtigter_name']); ?></div>
                            </div>
                            <div class="form-col">
                                <strong>Telefon</strong>
                                <div><?php echo esc_html($contract_data['erziehungsberechtigter_telefon'] ?? ''); ?></div>
                            </div>
                        </div>
                        <div class="form-row">
                            <strong>E-Mail</strong>
                            <div><?php echo esc_html($contract_data['erziehungsberechtigter_email'] ?? ''); ?></div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>




            <?php
            // Holen der aktuellen Zahlungsmethode
            $payment_method = get_option('ab_payment_method', 'direct_debit');

            // Nur anzeigen, wenn Lastschriftverfahren aktiviert ist
            if ($payment_method === 'direct_debit') {
                ?>
                <div class="accordion-step4">
                    <h3>Zahlungsinformationen</h3><a href="?step=3&order_id=<?php echo esc_attr($order->get_id()); ?>" class="edit-link">✎ bearbeiten</a>
                    <div class="accordion-step4-content open">
                        <div class="form-row-flex">
                            <div class="form-col">
                                <strong>Kontoinhaber</strong>
                                <div><?php echo esc_html($contract_data['kontoInhaber'] ?? ''); ?></div>
                            </div>
                            <div class="form-col">
                                <strong>Kreditinstitut</strong>
                                <div><?php echo esc_html($contract_data['bank_name'] ?? ''); ?></div>
                            </div>
                        </div>
                        <div class="form-row-flex">
                            <div class="form-col">
                                <strong>IBAN</strong>
                                <div><?php echo esc_html($contract_data['iban'] ?? ''); ?></div>
                            </div>
                            <div class="form-col">
                                <strong>BIC</strong>
                                <div><?php echo esc_html($contract_data['bic'] ?? ''); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php
            } elseif ($payment_method === 'bank_transfer') {
                ?>
                <div class="accordion-step4">
                    <h3>Zahlungsinformationen</h3><a href="?step=3&order_id=<?php echo esc_attr($order->get_id()); ?>" class="edit-link">✎ bearbeiten</a>
                    <div class="accordion-step4-content open">
                        <div class="form-row">
                            <strong>Zahlungsmethode</strong>
                            <div>Dauerauftrag (monatlich)</div>
                        </div>
                    </div>
                </div>
                <?php
              } elseif ($payment_method === 'invoice') {
                  ?>
                  <div class="accordion-step4">
                      <h3>Zahlungsinformationen</h3><a href="?step=3&order_id=<?php echo esc_attr($order->get_id()); ?>" class="edit-link">✎ bearbeiten</a>
                      <div class="accordion-step4-content open">
                          <div class="form-row">
                              <strong>Zahlungsmethode</strong>
                              <div>Rechnung monatlich</div>
                          </div>
                          <?php if (!empty($payment_details['invoice_text'])): ?>
    <div class="form-row">
        <strong>Details</strong>
        <div><?php echo wp_kses_post($payment_details['invoice_text']); ?></div>
    </div>
<?php endif; ?>
                          <div class="form-row">
                              <strong>Name</strong>
                              <div><?php echo esc_html(($contract_data['vorname'] ?? '') . ' ' . ($contract_data['nachname'] ?? '')); ?></div>
                          </div>
                          <div class="form-row">
                              <strong>Adresse</strong>
                              <div>
                                  <?php echo esc_html(($contract_data['strasse'] ?? '') . ' ' . ($contract_data['hausnummer'] ?? '')); ?><br>
                                  <?php echo esc_html(($contract_data['plz'] ?? '') . ' ' . ($contract_data['ort'] ?? '')); ?>
                              </div>
                          </div>
                          <div class="form-row">
                              <strong>Rechnungsversand per E-Mail an</strong>
                              <div><?php echo esc_html($contract_data['email'] ?? ''); ?></div>
                          </div>
                      </div>
                  </div>
                  <?php
              }
            ?>






            <!-- Abschlussbox -->
            <div class="accordion-step4">
                <div class="accordion-step4-content open">
                    <h4>Super, fast geschafft! Wenn deine Daten alle stimmen, kannst du jetzt deinen Vertrag abschließen. Danach bekommst du:</h4>
                    <ul style="margin-left: 1.5rem; list-style-type: disc;">
                        <li>Deinen Vertrag direkt per E-Mail</li>
                        <?php
                        $item_4 = ab_we_get_first_event_item($order);
                        $wa_link_4 = $item_4 ? $item_4->get_meta('_event_whatsapp_link') : '';
                        if (!empty($wa_link_4)): ?>
                        <li>Einen Link zur Whatsapp-Gruppe deiner Klasse</li>
                        <?php endif; ?>
                        <li>Zugang zum ParkourONE Academyboard für die Einsicht deiner nächsten Trainingstermine</li>
                    </ul>
                </div>
            </div>

            <div class="contract-wizard-navigation">
                <a href="?step=3&order_id=<?php echo esc_attr($order->get_id()); ?>" class="button">← Zurück</a>
                <button type="submit" class="button button-primary contract-submit-btn" data-loading-text="Vertrag wird verarbeitet...">
                    Vertrag kostenpflichtig abschließen
                </button>
            </div>
        </form>
        <?php
        return ob_get_clean();
    }

    public static function process_form() {
        // FIX: Wenn AJAX aktiv ist, diesen Weg komplett überspringen
        // Der AJAX-Handler übernimmt die Verarbeitung
        if (defined('DOING_AJAX') && DOING_AJAX) {
            return;
        }

        if (!isset($_POST['action']) || $_POST['action'] !== 'save_step4' || !wp_verify_nonce($_POST['nonce'], 'contract_wizard_nonce')) {
            return;
        }

        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $contract_id = isset($_POST['contract_id']) ? intval($_POST['contract_id']) : 0;

        if (!$order_id || !$contract_id) {
            wp_die('Ungültige Anfrage');
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_die('Order nicht gefunden');
        }

        // FIX: Idempotenz-Prüfung - Wurde der Vertrag bereits abgeschlossen?
        $contract_status = get_post_meta($order_id, '_contract_status', true);
        if ($contract_status === 'completed') {
            error_log('[AB Contract Step4] Vertrag bereits abgeschlossen für Order ' . $order_id . ' - Redirect zu Step 5');
            $redirect_url = add_query_arg([
                'step' => '5',
                'order_id' => $order_id,
                'contract_id' => $contract_id,
                'token' => isset($_POST['token']) ? $_POST['token'] : ''
            ], remove_query_arg('action'));
            wp_redirect($redirect_url);
            exit;
        }

        // FIX: Lock prüfen um Race Conditions zu verhindern
        $lock_key = '_ab_contract_processing_' . $order_id;
        $lock_value = get_transient($lock_key);
        if ($lock_value) {
            error_log('[AB Contract Step4] Vertrag wird bereits verarbeitet für Order ' . $order_id . ' - Warte...');
            sleep(2); // Kurz warten und dann zu Step 5 weiterleiten
            $redirect_url = add_query_arg([
                'step' => '5',
                'order_id' => $order_id,
                'contract_id' => $contract_id,
                'token' => isset($_POST['token']) ? $_POST['token'] : ''
            ], remove_query_arg('action'));
            wp_redirect($redirect_url);
            exit;
        }
        set_transient($lock_key, true, 30); // 30 Sekunden Lock

        $contract_data = get_post_meta($order_id, '_ab_contract_data', true) ?: [];
        $html = AB_Contract_PDF::build_html($order, $contract_data);
        $pdf_path = AB_Contract_PDF::generate($html, $order_id);

        if ($pdf_path) {
            // Fix: Korrekter Meta-Key für PDF-Pfad (konsistent mit email-sender.php)
            update_post_meta($order_id, '_ab_contract_pdf', $pdf_path);
            update_post_meta($order_id, '_contract_status', 'completed');
            update_post_meta($order_id, '_ab_contract_completion_date', current_time('mysql'));

            // Bankdaten auch als separate Order-Meta-Felder speichern für WooCommerce-Ansicht
            if (!empty($contract_data['iban'])) {
                update_post_meta($order_id, '_billing_iban', $contract_data['iban']);
            }
            if (!empty($contract_data['bic'])) {
                update_post_meta($order_id, '_billing_bic', $contract_data['bic']);
            }
            if (!empty($contract_data['kontoInhaber'])) {
                update_post_meta($order_id, '_billing_kontoinhaber', $contract_data['kontoInhaber']);
            }
            if (!empty($contract_data['bank_name'])) {
                update_post_meta($order_id, '_billing_bank_name', $contract_data['bank_name']);
            }

            $order->update_status('schuelerin', 'Vertrag abgeschlossen');
            AB_Email_Sender::send_status_email($order_id, 'wc-schuelerin');

            // Lock freigeben
            delete_transient($lock_key);

            do_action('ab_contract_completed', $order_id, $contract_id, $pdf_path);
            error_log('Redirecting to step 5');
            $redirect_url = add_query_arg(
                [
                    'step' => '5',
                    'order_id' => $order_id,
                    'contract_id' => $contract_id,
                    'token' => isset($_POST['token']) ? $_POST['token'] : (isset($_GET['token']) ? $_GET['token'] : '')
                ],
                remove_query_arg('action')
            );
            error_log('Redirect URL: ' . $redirect_url);
            wp_redirect($redirect_url);
            exit;
        }

        // Lock freigeben bei Fehler
        delete_transient($lock_key);

        wp_die('Fehler beim Generieren des PDFs');
    }

}

// Hook für die Formularverarbeitung
add_action('init', ['AB_Contract_Step_4', 'process_form']);
