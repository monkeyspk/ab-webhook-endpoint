<?php
if (!defined('ABSPATH')) {
    exit;
}

class AB_Contract_Admin_Panel {
    public static function init() {
        add_action('add_meta_boxes', [__CLASS__, 'add_contract_meta_box']);
        add_action('admin_init', [__CLASS__, 'handle_pdf_view']);
    }

    public static function handle_pdf_view() {
        if (isset($_GET['action']) && $_GET['action'] === 'view_contract_pdf' && isset($_GET['order_id'])) {
            // Prüfe Berechtigungen
            if (!current_user_can('manage_woocommerce')) {
                wp_die('Keine Berechtigung.');
            }

            // Prüfe Nonce
            if (!isset($_GET['nonce']) || !wp_verify_nonce($_GET['nonce'], 'view_contract_pdf')) {
                wp_die('Sicherheitscheck fehlgeschlagen.');
            }

            $order_id = intval($_GET['order_id']);
            $pdf_path = get_post_meta($order_id, '_ab_contract_pdf', true);

            if ($pdf_path && file_exists($pdf_path)) {
                header('Content-Type: application/pdf');
                header('Content-Disposition: inline; filename="vertrag-' . $order_id . '.pdf"');
                readfile($pdf_path);
                exit;
            }
            wp_die('PDF nicht gefunden.');
        }
    }

    public static function add_contract_meta_box() {
        add_meta_box(
            'ab_contract_data',
            'Vertragsdaten',
            [__CLASS__, 'render_contract_meta_box'],
            'shop_order',
            'normal',
            'high'
        );
    }

    public static function render_contract_meta_box($post) {
        $order = wc_get_order($post->ID);
        if (!$order) return;

        // Vertragsdaten aus den Metadaten holen
        $contract_data = get_post_meta($order->get_id(), '_ab_contract_data', true) ?: [];
        $pdf_path = get_post_meta($order->get_id(), '_ab_contract_pdf', true);
        $user_ip = get_post_meta($order->get_id(), '_contract_user_ip', true);


        // Prüfen ob ein Vertrag existiert - nur auf contract_data prüfen
        if (empty($contract_data)) {
            echo '<div class="no-contract-notice">';
            echo '<p>Noch kein Vertrag abgeschlossen.</p>';
            echo '</div>';
            return;
        }

        // PDF URL für Admin-Zugriff generieren
        $pdf_url = add_query_arg([
            'action' => 'view_contract_pdf',
            'order_id' => $order->get_id(),
            'nonce' => wp_create_nonce('view_contract_pdf')
        ], admin_url('admin.php'));

        // Hole das Abschlussdatum
        $completion_date = get_post_meta($order->get_id(), '_ab_contract_completion_date', true);

        // Wenn kein Abschlussdatum vorhanden ist, prüfe die Statusänderung
        if (empty($completion_date)) {
            $order_notes = $order->get_customer_order_notes();
            foreach ($order_notes as $note) {
                if (strpos($note->comment_content, 'Status von vertragverschickt zu schuelerin') !== false) {
                    $completion_date = $note->comment_date;
                    break;
                }
            }
        }

        // Prüfen ob Person minderjährig ist
        $is_minor = false;
        if (!empty($contract_data['geburtsdatum'])) {
            $birth_date = new DateTime($contract_data['geburtsdatum']);
            $today = new DateTime();
            $age = $today->diff($birth_date)->y;
            $is_minor = $age < 18;
        }

        // Zahlungsmethode ermitteln
        $payment_method = get_option('ab_payment_method', 'direct_debit');
        ?>
        <style>
        .contract-data-panel {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 20px;
            margin: 15px 0;
        }

        .contract-column {
            background: #fff;
            padding: 15px;
            border: 1px solid #e2e4e7;
            border-radius: 4px;
        }

        .contract-column h4 {
            margin-top: 0;
            padding-bottom: 10px;
            border-bottom: 1px solid #e2e4e7;
        }

        .contract-completion-date {
            margin-bottom: 15px;
        }

        .contract-section {
            margin-bottom: 20px;
        }

        .contract-section:last-child {
            margin-bottom: 0;
        }

        .form-table {
            margin: 0;
        }

        .form-table th {
            padding: 8px 10px 8px 0;
            width: 130px;
        }

        .form-table td {
            padding: 8px 10px;
        }
        </style>

        <div class="contract-data-panel">
            <!-- Spalte 1: Kontaktdaten -->
            <div class="contract-column">
                <div class="contract-section">
                    <h4>Persönliche Daten</h4>
                    <table class="form-table">
                        <tr>
                            <th>Anrede:</th>
                            <td><?php echo esc_html($contract_data['anrede'] ?? ''); ?></td>
                        </tr>
                        <tr>
                            <th>Name:</th>
                            <td><?php echo esc_html(($contract_data['vorname'] ?? '') . ' ' . ($contract_data['nachname'] ?? '')); ?></td>
                        </tr>
                        <tr>
                            <th>Geburtsdatum:</th>
                            <td><?php echo esc_html($contract_data['geburtsdatum'] ?? ''); ?></td>
                        </tr>
                        <?php if (!empty($contract_data['ahv_nummer'])): ?>
                        <tr>
                            <th>AHV-Nummer:</th>
                            <td><?php echo esc_html($contract_data['ahv_nummer']); ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <th>Adresse:</th>
                            <td>
                                <?php
                                echo esc_html(
                                    ($contract_data['strasse'] ?? '') . ' ' .
                                    ($contract_data['hausnummer'] ?? '') . ', ' .
                                    ($contract_data['plz'] ?? '') . ' ' .
                                    ($contract_data['ort'] ?? '')
                                );
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Telefon:</th>
                            <td><?php echo esc_html($contract_data['telefon'] ?? ''); ?></td>
                        </tr>
                        <tr>
                            <th>E-Mail:</th>
                            <td><?php echo esc_html($contract_data['email'] ?? ''); ?></td>
                        </tr>
                        <?php if (!empty($contract_data['besonderheiten'])): ?>
                        <tr>
                            <th>Besonderheiten:</th>
                            <td><?php echo nl2br(esc_html($contract_data['besonderheiten'])); ?></td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>

                <?php if ($is_minor): ?>
                <div class="contract-section">
                    <h4>Erziehungsberechtigte</h4>
                    <table class="form-table">
                        <tr>
                            <th>Name:</th>
                            <td><?php echo esc_html($contract_data['erziehungsberechtigter_name'] ?? ''); ?></td>
                        </tr>
                        <tr>
                            <th>Telefon:</th>
                            <td><?php echo esc_html($contract_data['erziehungsberechtigter_telefon'] ?? ''); ?></td>
                        </tr>
                        <tr>
                            <th>E-Mail:</th>
                            <td><?php echo esc_html($contract_data['erziehungsberechtigter_email'] ?? ''); ?></td>
                        </tr>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- Spalte 2: Zahlungsinformationen -->
            <div class="contract-column">
                <div class="contract-section">
                    <h4>Zahlungsinformationen</h4>
                    <table class="form-table">
                        <?php if ($payment_method === 'direct_debit'): ?>
                            <tr>
                                <th>Zahlungsart:</th>
                                <td>Lastschrift</td>
                            </tr>
                            <tr>
                                <th>Kontoinhaber:</th>
                                <td><?php echo esc_html($contract_data['kontoInhaber'] ?? ''); ?></td>
                            </tr>
                            <tr>
                                <th>Bank:</th>
                                <td><?php echo esc_html($contract_data['bank_name'] ?? ''); ?></td>
                            </tr>
                            <tr>
                                <th>IBAN:</th>
                                <td><?php echo esc_html($contract_data['iban'] ?? ''); ?></td>
                            </tr>
                            <tr>
                                <th>BIC:</th>
                                <td><?php echo esc_html($contract_data['bic'] ?? ''); ?></td>
                            </tr>
                        <?php elseif ($payment_method === 'bank_transfer'): ?>
                            <tr>
                                <th>Zahlungsart:</th>
                                <td>Dauerauftrag</td>
                            </tr>
                        <?php elseif ($payment_method === 'invoice'): ?>
                            <tr>
                                <th>Zahlungsart:</th>
                                <td>Rechnung</td>
                            </tr>
                            <?php if (!empty($contract_data['invoice_address'])): ?>
                            <tr>
                                <th>Rechnungsadresse:</th>
                                <td><?php echo nl2br(esc_html($contract_data['invoice_address'])); ?></td>
                            </tr>
                            <?php endif; ?>
                        <?php endif; ?>
                    </table>
                </div>
            </div>

            <!-- Spalte 3: Vertragsinformationen -->
            <div class="contract-column">
                <?php if ($completion_date): ?>
                <div class="contract-section">
                    <h4>Vertragsdetails</h4>
                    <div class="contract-completion-date">
                        <p><strong>Abgeschlossen am:</strong><br>
                        <?php echo date_i18n('d.m.Y H:i', strtotime($completion_date)); ?></p>
                    </div>
                </div>
                <?php endif; ?>

                <?php
                // Nutzer-IP abrufen
                $user_ip = get_post_meta($order->get_id(), '_contract_user_ip', true);
                if (!empty($user_ip)): ?>
                <div class="contract-section">
                    <h4>Nutzer-IP</h4>
                    <p><?php echo esc_html($user_ip); ?></p>
                </div>
                <?php endif; ?>

                <?php if ($pdf_path): ?>
<div class="contract-section">
    <h4>Vertragsdokument</h4>

    <!-- Admin-Zugriff über den bestehenden Mechanismus -->
    <p>
        <a href="<?php echo esc_url($pdf_url); ?>" target="_blank" class="button">
            Vertrag öffnen (PDF)
        </a>
    </p>

    <?php
    // Direkter HTTP-Link zum PDF
    $direct_pdf_url = ab_get_contract_pdf_url($order->get_id());
    if ($direct_pdf_url):
    ?>
    <p>
        <strong>Direkter Link (für Exporte/API):</strong><br>
        <input type="text" readonly class="regular-text" value="<?php echo esc_attr($direct_pdf_url); ?>"
               onclick="this.select();" style="margin-top: 8px; width: 100%;">
    </p>
    <?php endif; ?>
</div>
<?php endif; ?>
            </div>

        </div>
        <?php
    }
}

// Plugin-Initialisierung
add_action('init', ['AB_Contract_Admin_Panel', 'init']);
