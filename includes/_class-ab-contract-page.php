<?php
if (!defined('ABSPATH')) {
    exit;
}

// DomPDF Autoloader mit Fehlerprüfung
$dompdf_autoload_path = plugin_dir_path(__FILE__) . '../dompdf/autoload.inc.php';
if (!file_exists($dompdf_autoload_path)) {
    add_action('admin_notices', function() {
        echo '<div class="error"><p>DomPDF nicht gefunden. Bitte installieren Sie DomPDF korrekt.</p></div>';
    });
    return;
}
require_once $dompdf_autoload_path;

use Dompdf\Dompdf;
use Dompdf\Options;

class AB_Contract_Page {
    private static $instance = null;

    public static function init() {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        add_shortcode('ab_contract_form', [__CLASS__, 'render_contract_form']);
        add_action('init', [__CLASS__, 'handle_form_submission']);

        // Sicherheitscheck für Nonce
        if (!wp_doing_ajax()) {
            add_action('init', [__CLASS__, 'verify_nonce']);
        }
    }

    public static function verify_nonce() {
        if (isset($_POST['ab_contract_form_submitted'])) {
            if (!isset($_POST['contract_nonce']) || !wp_verify_nonce($_POST['contract_nonce'], 'ab_contract_action')) {
                wp_die('Sicherheitsüberprüfung fehlgeschlagen');
            }
        }
    }

    public static function render_contract_form($atts) {
        ob_start();

        $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
        if (!$order_id) {
            return '<p>Keine gültige Bestellung angegeben.</p>';
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return '<p>Bestellung wurde nicht gefunden.</p>';
        }

        // Prüfen ob Benutzer berechtigt ist
        if (!self::user_can_view_order($order)) {
            return '<p>Sie haben keine Berechtigung diese Bestellung einzusehen.</p>';
        }

        $contract_text = self::get_contract_text_for_order($order);
        ?>
        <h2>Vertragsabschluss</h2>
        <div class="contract-container" style="margin-bottom:1em; padding:1em; background:#f9f9f9; border:1px solid #eee;">
            <?php echo wp_kses_post($contract_text); ?>
        </div>

        <form method="post" id="contract-form">
            <?php wp_nonce_field('ab_contract_action', 'contract_nonce'); ?>
            <input type="hidden" name="ab_contract_form_submitted" value="1" />
            <input type="hidden" name="order_id" value="<?php echo esc_attr($order_id); ?>" />

            <p>
                <label for="geburtsdatum">Geburtsdatum:</label><br>
                <input type="date" name="geburtsdatum" id="geburtsdatum" required
                       max="<?php echo date('Y-m-d'); ?>" />
            </p>

            <p>
                <label for="hinweis">Anmerkungen (optional):</label><br>
                <textarea name="hinweis" id="hinweis" rows="4" style="width:100%;"
                          maxlength="1000"></textarea>
            </p>

            <p>
                <label>
                    <input type="checkbox" name="agb_confirm" value="1" required>
                    Ich akzeptiere die <a href="<?php echo esc_url(get_privacy_policy_url()); ?>" target="_blank">AGB</a>.
                </label>
            </p>

            <p><button type="submit" class="button">Vertrag abschließen</button></p>
        </form>
        <?php

        return ob_get_clean();
    }

    private static function user_can_view_order($order) {
        if (!is_user_logged_in()) {
            return false;
        }
        $user_id = get_current_user_id();
        return $order->get_customer_id() === $user_id || current_user_can('edit_shop_orders');
    }

    private static function get_contract_text_for_order(\WC_Order $order) {
        $contract_text = '';

        foreach ($order->get_items() as $item) {
            $title = $item->get_meta('_event_title');
            if (!$title) continue;

            if (stripos($title, 'kids') !== false) {
                $contract_text = "Hier steht der Kids-Vertrag ...";
            } elseif (stripos($title, 'junior') !== false) {
                $contract_text = "Hier steht der Juniors-Vertrag ...";
            } else {
                $contract_text = "Hier steht der Standard-Vertrag ...";
            }
            break;
        }

        return $contract_text ?: "Standardvertragstext";
    }

    public static function handle_form_submission() {
        if (!isset($_POST['ab_contract_form_submitted']) || $_POST['ab_contract_form_submitted'] != '1') {
            return;
        }

        // Nochmalige Nonce-Überprüfung
        if (!isset($_POST['contract_nonce']) || !wp_verify_nonce($_POST['contract_nonce'], 'ab_contract_action')) {
            wp_die('Sicherheitsüberprüfung fehlgeschlagen');
        }

        $order_id = intval($_POST['order_id'] ?? 0);
        $order = wc_get_order($order_id);

        if (!$order || !self::user_can_view_order($order)) {
            wp_die('Ungültige Bestellung oder keine Berechtigung');
        }

        try {
            // Daten validieren und säubern
            $geburtsdatum = self::validate_date($_POST['geburtsdatum'] ?? '');
            $hinweis = sanitize_textarea_field($_POST['hinweis'] ?? '');

            // PDF generieren
            $html = self::build_pdf_html($order, $geburtsdatum, $hinweis);
            $pdf_path = self::generate_pdf($html, $order_id);

            if ($pdf_path) {
                update_post_meta($order_id, '_ab_contract_pdf', $pdf_path);
                $order->update_status('schuelerin', 'Vertrag abgeschlossen.');

                // E-Mail über AB_Email_Sender senden
                AB_Email_Sender::send_status_email($order_id, 'wc-schuelerin');

                wp_redirect(add_query_arg([
                    'contract_done' => '1',
                    'order' => $order_id,
                ], $order->get_checkout_order_received_url()));
                exit;
            }

        } catch (Exception $e) {
            wp_die('Fehler beim Verarbeiten des Vertrags: ' . esc_html($e->getMessage()));
        }
    }

    private static function validate_date($date) {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) {
            throw new Exception('Ungültiges Datum');
        }
        return $date;
    }

    private static function build_pdf_html(\WC_Order $order, $geburtsdatum, $hinweis) {
        $contract_text = self::get_contract_text_for_order($order);

        $html = '<html><head><meta charset="UTF-8"></head><body>';
        $html .= '<h1>Vertrag</h1>';
        $html .= '<p>Bestellung #' . esc_html($order->get_id()) . '</p>';
        $html .= '<p>Name: ' . esc_html($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()) . '</p>';
        $html .= '<p>Geburtsdatum: ' . esc_html($geburtsdatum) . '</p>';
        if ($hinweis) {
            $html .= '<p>Anmerkungen: ' . nl2br(esc_html($hinweis)) . '</p>';
        }
        $html .= '<div class="contract-text">' . wp_kses_post($contract_text) . '</div>';
        $html .= '</body></html>';

        return $html;
    }

    // In class-ab-contract-page.php
    private static function generate_pdf($html, $order_id) {
        try {
            // DomPDF Optionen setzen
            $options = new Options();
            $options->setIsRemoteEnabled(true);
            $options->setIsHtml5ParserEnabled(true);

            $dompdf = new Dompdf($options);
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            // Verzeichnis erstellen und Berechtigungen setzen
            $upload_dir = wp_upload_dir();
            $base_path = $upload_dir['basedir'] . '/vertraege/';

            if (!file_exists($base_path)) {
                wp_mkdir_p($base_path);
                // Verzeichnis schützen mit neuer .htaccess
                $htaccess_content = "SetEnvIf Referer \"^https?://academyboard\.parkourone\.com/\" academyboard_access\n";
                $htaccess_content .= "Order Deny,Allow\n";
                $htaccess_content .= "Deny from all\n";
                $htaccess_content .= "Allow from env=academyboard_access";
                file_put_contents($base_path . '.htaccess', $htaccess_content);
            }

            $filename = sprintf('vertrag-%d-%s.pdf', $order_id, time());
            $file_path = $base_path . $filename;

            if (file_put_contents($file_path, $dompdf->output())) {
                return $file_path;
            }

            throw new Exception('PDF konnte nicht gespeichert werden');

        } catch (Exception $e) {
            error_log('PDF Generierungsfehler: ' . $e->getMessage());
            throw $e;
        }
    }
}
