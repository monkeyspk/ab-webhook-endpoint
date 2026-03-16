<?php
if (!defined('ABSPATH')) {
    exit;
}

class AB_Contract_Step_5 {
    public static function render($order, $contract_id) {
        $pdf_path = get_post_meta($order->get_id(), '_contract_pdf_path', true);

        ob_start();
        ?>
        <div class="contract-success">
            <div class="success-message">
                <h2>🎉 Herzlichen Glückwunsch!</h2>
                <p>Dein Vertrag wurde erfolgreich abgeschlossen. Wir freuen uns, dich bei ParkourONE begrüßen zu dürfen!</p>
            </div>

            <div class="contract-summary">
                <?php if ($pdf_path && file_exists($pdf_path)): ?>
                    <div class="contract-download">
                        <div class="pdf-preview">
                            <img src="<?php echo esc_url(plugins_url('assets/images/pdf-icon.svg', dirname(__FILE__))); ?>" alt="PDF Icon" class="pdf-icon">
                            <h4>Dein Vertrag (PDF)</h4>
                        </div>
                        <a href="<?php echo esc_url(content_url('uploads/vertraege/' . basename($pdf_path))); ?>"
                           class="download-button"
                           target="_blank">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                <polyline points="7 10 12 15 17 10"/>
                                <line x1="12" y1="15" x2="12" y2="3"/>
                            </svg>
                            Vertrag herunterladen
                        </a>
                        <p class="info-text">
                            Wir haben dir den Vertrag auch per E-Mail zugeschickt.
                        </p>
                    </div>
                <?php endif; ?>

                <!-- Nächste Schritte -->
                <div class="next-steps">
                    <h4>Deine nächsten Schritte</h4>
                    <div class="steps-grid">
                        <div class="step-item">
                            <div class="step-icon">📧</div>
                            <div class="step-text">Du erhältst eine E-Mail mit deinem Vertrag</div>
                        </div>
                        <div class="step-item">
                            <div class="step-icon">💬</div>
                            <div class="step-text">Wir senden dir einen Link zur WhatsApp-Gruppe deiner Klasse</div>
                        </div>
                        <div class="step-item">
                            <div class="step-icon">🎓</div>
                            <div class="step-text">Du bekommst Zugangsdaten zum ParkourONE Academyboard</div>
                        </div>
                    </div>
                </div>
            </div>

            <style>
                .contract-success {
                    max-width: 800px;
                    margin: 0 auto;
                    padding: 20px;
                }
                .success-message {
                    text-align: center;
                    margin-bottom: 40px;
                    padding: 30px;
                    border-radius: 8px;
                }
                .success-message h2 {
                    color: #4CAF50;
                    font-size: 28px;
                    margin-bottom: 15px;
                }
                .success-message p {
                    font-size: 18px;
                    color: #666;
                }
                .contract-summary {
                    background: #fff;
                    border-radius: 8px;
                    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                    padding: 30px;
                }
                .contract-download {
                    text-align: center;
                    padding: 30px;
                    background: #f9f9f9;
                    border-radius: 8px;
                    margin-bottom: 30px;
                }
                .pdf-preview {
                    margin-bottom: 20px;
                }
                .pdf-icon {
                    width: 64px;
                    height: 64px;
                    margin-bottom: 15px;
                }
                .download-button {
                    display: inline-flex;
                    align-items: center;
                    gap: 8px;
                    background: #4CAF50;
                    color: white;
                    padding: 12px 24px;
                    border-radius: 6px;
                    text-decoration: none;
                    font-weight: 500;
                    transition: background-color 0.2s;
                }
                .download-button:hover {
                    background: #45a049;
                    color: white;
                }
                .info-text {
                    color: #666;
                    font-size: 0.9em;
                    margin-top: 15px;
                }
                .next-steps {
                    padding: 30px;
                    border-radius: 8px;
                }
                .next-steps h4 {
                    text-align: center;
                    margin-bottom: 25px;
                    color: #333;
                }
                .steps-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                    gap: 20px;
                }
                .step-item {
                    text-align: center;
                    padding: 20px;
                }
                .step-icon {
                    font-size: 32px;
                    margin-bottom: 15px;
                }
                .step-text {
                    color: #666;
                    line-height: 1.4;
                }
            </style>
        </div>
        <?php
        return ob_get_clean();
    }
}
