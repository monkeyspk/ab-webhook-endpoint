<?php
class AB_Contract_Step_3 {
    public static function render($order, $contract_id) {
        $payment_method = get_option('ab_payment_method', 'direct_debit');
        $payment_details = get_option('ab_payment_details', []);
        $contract_data = get_post_meta($order->get_id(), '_ab_contract_data', true) ?: [];

        ob_start();
        ?>
        <form method="post" class="contract-wizard-form">
            <input type="hidden" name="form_action" value="save_step3">
            <input type="hidden" name="current_step" value="3">
            <input type="hidden" name="order_id" value="<?php echo esc_attr($order->get_id()); ?>">
            <input type="hidden" name="token" value="<?php echo isset($_GET['token']) ? esc_attr($_GET['token']) : ''; ?>">

            <?php wp_nonce_field('contract_wizard_nonce', 'nonce'); ?>

            <?php if ($payment_method === 'direct_debit'): ?>
                <p class="contract-paragraph">
                    <?php echo wp_kses_post(get_option('ab_sepa_intro_text')); ?>
                </p>


                <div class="form-row">
                <label for="kontoInhaber">Vorname und Name (Kontoinhaber) *</label>
                <input type="text" id="kontoInhaber" name="kontoInhaber"
                       value="<?php echo esc_attr($contract_data['kontoInhaber'] ?? ''); ?>"
                       placeholder="Max Mustermann"
                       required>
            </div>

            <div class="form-row">
                <label for="bank_name">Kreditinstitut (Name) *</label>
                <input type="text" id="bank_name" name="bank_name"
                       value="<?php echo esc_attr($contract_data['bank_name'] ?? ''); ?>"
                       placeholder="z.B. Sparkasse, Volksbank etc."
                       required>
            </div>

            <div class="form-row">
              <label for="iban">IBAN *</label>
              <input type="text" id="iban" name="iban"
                     value="<?php echo esc_attr($contract_data['iban'] ?? ''); ?>"
                     placeholder="DE00 1234 5678 9012 3456 78"
                     required>

            </div>

            <div class="form-row">
                <label for="bic">BIC *</label>
                <input type="text" id="bic" name="bic"
                       pattern="[A-Za-z]{6}[A-Za-z0-9]{2,5}"
                       value="<?php echo esc_attr($contract_data['bic'] ?? ''); ?>"
                       placeholder="z.B. BELADEBEXXX"
                       required>
            </div>

            <div class="accordion">
                <button type="button" class="accordion-toggle">
                    <?php echo esc_html(get_option('ab_sepa_accordion_title', 'Wichtige Informationen zu deinem SEPA-Lastschriftmandat')); ?>
                </button>
                <div class="accordion-content">
                    <div class="sepa-notice">
                        <?php echo wp_kses_post(get_option('ab_sepa_accordion_content', '')); ?>
                    </div>
                </div>
            </div>


                <div class="form-row checkbox-container">
                    <input type="checkbox" id="sepa_mandate" name="sepa_mandate" required>
                    <label for="sepa_mandate">
                       Hiermit genehmige ich das Lastschriftverfahren zu den oben genannten Bedingungen.
                    </label>
                </div>

            <?php elseif ($payment_method === 'bank_transfer'): ?>
                <p class="contract-paragraph">
                    Danke, dass du dich für unsere Angebote entschieden hast! Bei uns bezahlst du per Dauerauftrag. Bitte richte diesen mit den folgenden Bankinformationen ein, damit die Zahlungen reibungslos abgewickelt werden können.
                </p>
                <div class="bank-info">
                    <p><strong>IBAN:</strong> <?php echo esc_html($payment_details['company_iban'] ?? ''); ?></p>
                    <p><strong>BIC:</strong> <?php echo esc_html($payment_details['company_bic'] ?? ''); ?></p>
                    <p>Verwende als Verwendungszweck deine Vertragsnummer.</p>
                </div>

              <?php elseif ($payment_method === 'invoice'): ?>
                  <h3>Zahlungsmethode: Rechnung</h3>
                  <p class="contract-paragraph">
                      <?php echo wp_kses_post($payment_details['invoice_text'] ?? 'Du erhältst monatlich eine Rechnung per E-Mail und hast 10 Werktage Zeit zur Bezahlung.'); ?>
                  </p>
                  <div class="invoice-info">
                      <p>Wir verwenden für den Rechnungsversand die von dir bereits angegebene E-Mail-Adresse.</p>
                  </div>
              <?php endif; ?>

            <?php echo AB_Contract_Wizard::get_navigation_buttons(3); ?>
        </form>
        <?php
        return ob_get_clean();
    }

    public static function sanitize_payment_data($data) {
        return [
            'kontoInhaber' => sanitize_text_field($data['kontoInhaber'] ?? ''),
            'bank_name' => sanitize_text_field($data['bank_name'] ?? ''),
            'iban' => $data['iban'] ?? '', // Keine Validierung mehr, erlaubt jede Eingabe
            'bic' => sanitize_text_field($data['bic'] ?? ''),
        ];
    }

 }
