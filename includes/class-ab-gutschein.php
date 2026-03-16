<?php
if (!defined('ABSPATH')) {
    exit;
}

class AB_Gutschein {

    const PRODUCT_META_KEY = '_ab_is_gutschein';

    public static function init() {
        // Frontend Shortcode
        add_shortcode('ab_gutschein', [__CLASS__, 'render_gutschein_shortcode']);

        // Enqueue Scripts/Styles
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);

        // AJAX: Add gift card to cart
        add_action('wp_ajax_ab_gutschein_add_to_cart', [__CLASS__, 'ajax_add_to_cart']);
        add_action('wp_ajax_nopriv_ab_gutschein_add_to_cart', [__CLASS__, 'ajax_add_to_cart']);

        // WooCommerce Cart Hooks
        add_filter('woocommerce_add_cart_item_data', [__CLASS__, 'add_cart_item_data'], 10, 3);
        add_filter('woocommerce_get_item_data', [__CLASS__, 'display_cart_item_data'], 10, 2);
        add_action('woocommerce_before_calculate_totals', [__CLASS__, 'set_custom_cart_item_price']);
        add_action('woocommerce_checkout_create_order_line_item', [__CLASS__, 'save_order_item_meta'], 10, 4);

        // Bei Zahlung: Gutschein-Orders auf Status "gutschein" setzen
        add_filter('woocommerce_payment_complete_order_status', [__CLASS__, 'set_gutschein_order_status'], 10, 3);
        add_action('woocommerce_checkout_order_processed', [__CLASS__, 'maybe_set_gutschein_status_on_checkout']);

        // Status "gutschein": Coupon generieren + E-Mail senden
        add_action('woocommerce_order_status_gutschein', [__CLASS__, 'on_order_completed'], 10, 2);

        // Fallback: Auch bei manueller Aenderung auf completed (z.B. Admin)
        add_action('woocommerce_order_status_completed', [__CLASS__, 'on_order_completed'], 10, 2);

        // Admin: Gutschein-Meta in Order anzeigen
        add_action('woocommerce_admin_order_data_after_billing_address', [__CLASS__, 'display_admin_order_meta']);

        // Standard WC Processing/Completed-Mail unterdruecken fuer Gutschein-Orders
        add_filter('woocommerce_email_enabled_customer_completed_order', [__CLASS__, 'maybe_suppress_completed_email'], 10, 2);
        add_filter('woocommerce_email_enabled_customer_processing_order', [__CLASS__, 'maybe_suppress_processing_email'], 10, 2);

        // Gutschein-Coupon nicht fuer Gutschein-Produkte einloesbar
        add_filter('woocommerce_coupon_is_valid_for_product', [__CLASS__, 'prevent_coupon_on_gutschein'], 10, 4);

        // Bei Stornierung/Refund: Coupon deaktivieren
        add_action('woocommerce_order_status_cancelled', [__CLASS__, 'on_order_cancelled']);
        add_action('woocommerce_order_status_refunded', [__CLASS__, 'on_order_cancelled']);

        // Admin: Gutschein-PDF anzeigen/downloaden
        add_action('admin_init', [__CLASS__, 'handle_gutschein_pdf_view']);
    }

    // -------------------------
    // Produkt-Identifikation
    // -------------------------

    public static function is_gutschein_product($product_id) {
        return get_post_meta($product_id, self::PRODUCT_META_KEY, true) === 'yes';
    }

    public static function order_has_gutschein($order) {
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            if (self::is_gutschein_product($product_id)) {
                return true;
            }
        }
        return false;
    }

    public static function is_pure_gutschein_order($order) {
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            if (!self::is_gutschein_product($product_id)) {
                return false;
            }
        }
        return true;
    }

    // -------------------------
    // Frontend Shortcode
    // -------------------------

    public static function render_gutschein_shortcode($atts) {
        $product_id = AB_Gutschein_Settings::get_setting('product_id', '');
        if (empty($product_id) || !self::is_gutschein_product($product_id)) {
            return '<p>Gutschein-Produkt nicht konfiguriert.</p>';
        }

        // Assets direkt beim Shortcode-Render laden (zuverlaessiger als has_shortcode)
        self::do_enqueue_assets();

        $preset_amounts = AB_Gutschein_Settings::get_preset_amounts();
        $min_amount = floatval(AB_Gutschein_Settings::get_setting('min_amount', 10));
        $max_amount = floatval(AB_Gutschein_Settings::get_setting('max_amount', 500));

        ob_start();
        ?>
        <div class="ab-gutschein-container">

            <div class="ab-gutschein-header">
                <h2>Parkour ONE Gutschein</h2>
                <p class="ab-gutschein-subtitle">Verschenke unvergessliche Parkour-Erlebnisse!</p>
            </div>

            <!-- Betragsauswahl -->
            <div class="ab-gutschein-amounts">
                <label class="ab-gutschein-label">Wert auswaehlen</label>
                <div class="ab-gutschein-preset-buttons">
                    <?php foreach ($preset_amounts as $amount) : ?>
                        <button type="button" class="ab-gutschein-amount-btn" data-amount="<?php echo esc_attr($amount); ?>">
                            <?php echo number_format($amount, 0, ',', '.'); ?> &euro;
                        </button>
                    <?php endforeach; ?>
                </div>
                <div class="ab-gutschein-custom-amount">
                    <label for="ab-gutschein-custom-input">Oder eigenen Betrag eingeben:</label>
                    <div class="ab-gutschein-input-group">
                        <input type="number" id="ab-gutschein-custom-input"
                               min="<?php echo esc_attr($min_amount); ?>"
                               max="<?php echo esc_attr($max_amount); ?>"
                               step="1" placeholder="z.B. 75">
                        <span class="ab-gutschein-currency">&euro;</span>
                    </div>
                </div>
            </div>

            <!-- Empfaenger -->
            <div class="ab-gutschein-recipient">
                <label class="ab-gutschein-label">Gutschein verschenken (optional)</label>
                <div class="ab-gutschein-form-row">
                    <label for="ab-gutschein-recipient-email">E-Mail des Empfaengers</label>
                    <input type="email" id="ab-gutschein-recipient-email"
                           placeholder="empfaenger@email.de">
                </div>
                <div class="ab-gutschein-form-row">
                    <label for="ab-gutschein-message">Persoenliche Nachricht</label>
                    <textarea id="ab-gutschein-message" rows="3" maxlength="500"
                              placeholder="Alles Gute zum Geburtstag! Viel Spass beim Parkour..."></textarea>
                </div>
            </div>

            <!-- Vorschau-Karte -->
            <div class="ab-gutschein-preview">
                <div class="ab-gutschein-card">
                    <span class="ab-gutschein-card-label">Gutschein</span>
                    <span class="ab-gutschein-card-amount">0,00 &euro;</span>
                    <span class="ab-gutschein-card-brand">Parkour ONE</span>
                </div>
            </div>

            <!-- Warenkorb-Button -->
            <div class="ab-gutschein-actions">
                <button type="button" id="ab-gutschein-add-to-cart" disabled>
                    In den Warenkorb
                </button>
                <div class="ab-gutschein-feedback" style="display:none;"></div>
            </div>

            <input type="hidden" id="ab-gutschein-product-id" value="<?php echo esc_attr($product_id); ?>">
            <input type="hidden" id="ab-gutschein-nonce" value="<?php echo wp_create_nonce('ab_gutschein_nonce'); ?>">
        </div>
        <?php
        return ob_get_clean();
    }

    public static function enqueue_assets() {
        // Fallback: Versuche ueber has_shortcode zu laden (fuer Header-CSS)
        global $post;
        if (is_object($post) && has_shortcode($post->post_content, 'ab_gutschein')) {
            self::do_enqueue_assets();
        }
    }

    /**
     * Laedt CSS + JS + AJAX-URL. Kann mehrfach aufgerufen werden, wp_enqueue verhindert Duplikate.
     */
    public static function do_enqueue_assets() {
        wp_enqueue_style(
            'ab-gutschein',
            plugins_url('assets/css/gutschein.css', dirname(__FILE__)),
            [],
            '1.0.0'
        );
        wp_enqueue_script(
            'ab-gutschein',
            plugins_url('assets/js/gutschein.js', dirname(__FILE__)),
            ['jquery'],
            '1.0.0',
            true
        );
        wp_localize_script('ab-gutschein', 'abGutschein', [
            'ajaxurl' => admin_url('admin-ajax.php'),
        ]);
    }

    // -------------------------
    // AJAX Add to Cart
    // -------------------------

    public static function ajax_add_to_cart() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ab_gutschein_nonce')) {
            wp_send_json_error(['message' => 'Sicherheitscheck fehlgeschlagen.']);
            return;
        }

        $product_id = intval($_POST['product_id'] ?? 0);
        $amount = floatval($_POST['amount'] ?? 0);
        $recipient_email = sanitize_email($_POST['recipient_email'] ?? '');
        $message = sanitize_textarea_field($_POST['message'] ?? '');

        // Validierung
        if (!$product_id || !self::is_gutschein_product($product_id)) {
            wp_send_json_error(['message' => 'Ungueltiges Gutschein-Produkt.']);
            return;
        }

        $min_amount = floatval(AB_Gutschein_Settings::get_setting('min_amount', 10));
        $max_amount = floatval(AB_Gutschein_Settings::get_setting('max_amount', 500));

        if ($amount < $min_amount || $amount > $max_amount) {
            wp_send_json_error(['message' => sprintf('Bitte waehle einen Betrag zwischen %s und %s EUR.', number_format($min_amount, 0, ',', '.'), number_format($max_amount, 0, ',', '.'))]);
            return;
        }

        if (!empty($recipient_email) && !is_email($recipient_email)) {
            wp_send_json_error(['message' => 'Bitte gib eine gueltige E-Mail-Adresse ein.']);
            return;
        }

        // Cart Item Data wird ueber den Filter woocommerce_add_cart_item_data angehaengt
        // Wir setzen die Daten temporaer in die Session
        WC()->session->set('ab_gutschein_pending', [
            'amount'          => $amount,
            'recipient_email' => $recipient_email,
            'message'         => $message,
        ]);

        $cart_item_key = WC()->cart->add_to_cart($product_id, 1);

        WC()->session->set('ab_gutschein_pending', null);

        if ($cart_item_key) {
            wp_send_json_success([
                'message'  => 'Gutschein wurde zum Warenkorb hinzugefuegt!',
                'cart_url' => wc_get_cart_url(),
            ]);
        } else {
            wp_send_json_error(['message' => 'Fehler beim Hinzufuegen zum Warenkorb.']);
        }
    }

    // -------------------------
    // WooCommerce Cart Integration
    // -------------------------

    public static function add_cart_item_data($cart_item_data, $product_id, $variation_id) {
        if (!self::is_gutschein_product($product_id)) {
            return $cart_item_data;
        }

        $pending = WC()->session ? WC()->session->get('ab_gutschein_pending') : null;
        if (!empty($pending)) {
            $cart_item_data['ab_gutschein_amount'] = floatval($pending['amount']);
            $cart_item_data['ab_gutschein_recipient_email'] = sanitize_email($pending['recipient_email']);
            $cart_item_data['ab_gutschein_message'] = sanitize_textarea_field($pending['message']);
            // Unique Key damit verschiedene Betraege nicht zusammengemergt werden
            $cart_item_data['unique_key'] = md5(microtime() . rand());
        }

        return $cart_item_data;
    }

    public static function display_cart_item_data($item_data, $cart_item) {
        if (isset($cart_item['ab_gutschein_amount'])) {
            $item_data[] = [
                'key'   => 'Gutschein-Wert',
                'value' => number_format($cart_item['ab_gutschein_amount'], 2, ',', '.') . ' &euro;',
            ];
        }
        if (!empty($cart_item['ab_gutschein_recipient_email'])) {
            $item_data[] = [
                'key'   => 'Empfaenger',
                'value' => esc_html($cart_item['ab_gutschein_recipient_email']),
            ];
        }
        if (!empty($cart_item['ab_gutschein_message'])) {
            $item_data[] = [
                'key'   => 'Nachricht',
                'value' => esc_html(wp_trim_words($cart_item['ab_gutschein_message'], 10)),
            ];
        }
        return $item_data;
    }

    public static function set_custom_cart_item_price($cart) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }
        if (did_action('woocommerce_before_calculate_totals') >= 2) {
            return;
        }

        foreach ($cart->get_cart() as $cart_item) {
            if (isset($cart_item['ab_gutschein_amount'])) {
                $cart_item['data']->set_price($cart_item['ab_gutschein_amount']);
            }
        }
    }

    public static function save_order_item_meta($item, $cart_item_key, $values, $order) {
        if (isset($values['ab_gutschein_amount'])) {
            $item->add_meta_data('_ab_gutschein_amount', $values['ab_gutschein_amount']);
        }
        if (!empty($values['ab_gutschein_recipient_email'])) {
            $item->add_meta_data('_ab_gutschein_recipient_email', $values['ab_gutschein_recipient_email']);
        }
        if (!empty($values['ab_gutschein_message'])) {
            $item->add_meta_data('_ab_gutschein_message', $values['ab_gutschein_message']);
        }
    }

    // -------------------------
    // Status-Steuerung: Gutschein-Orders auf Status "gutschein" setzen
    // -------------------------

    /**
     * Bei payment_complete: Setzt den Ziel-Status auf "gutschein" statt "processing/completed"
     * fuer reine Gutschein-Orders.
     */
    public static function set_gutschein_order_status($status, $order_id, $order) {
        if (!$order instanceof WC_Order) {
            return $status;
        }

        if (self::is_pure_gutschein_order($order)) {
            return 'wc-gutschein';
        }

        return $status;
    }

    /**
     * Fallback fuer Gateways die checkout_order_processed nutzen (z.B. Gutschein-auf-Gutschein, 0-EUR Orders).
     */
    public static function maybe_set_gutschein_status_on_checkout($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        if (!self::is_pure_gutschein_order($order)) {
            return;
        }

        // Nur setzen wenn nicht schon gutschein-Status
        if ($order->get_status() !== 'gutschein') {
            $order->update_status('wc-gutschein', 'Gutschein-Bestellung automatisch auf Status Gutschein gesetzt.');
            error_log('[AB Gutschein] Order #' . $order_id . ' auf Status gutschein gesetzt (checkout_order_processed)');
        }
    }

    /**
     * Standard WC Processing-Mail unterdruecken fuer Gutschein-Orders.
     */
    public static function maybe_suppress_processing_email($enabled, $order) {
        if (!$order instanceof WC_Order) {
            return $enabled;
        }
        if (self::is_pure_gutschein_order($order)) {
            return false;
        }
        return $enabled;
    }

    // -------------------------
    // Order Status Gutschein: Coupon generieren
    // -------------------------

    public static function on_order_completed($order_id, $order = null) {
        if (!$order) {
            $order = wc_get_order($order_id);
        }
        if (!$order) {
            return;
        }

        // Nur verarbeiten wenn Order Gutschein-Items hat
        if (!self::order_has_gutschein($order)) {
            return;
        }

        // Duplikat-Schutz
        $existing_code = $order->get_meta('_ab_gutschein_coupon_code');
        if (!empty($existing_code)) {
            error_log('[AB Gutschein] Coupon bereits generiert fuer Order #' . $order_id . ' - ueberspringe');
            return;
        }

        $expiry_days = intval(AB_Gutschein_Settings::get_setting('expiry_days', 365));

        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            if (!self::is_gutschein_product($product_id)) {
                continue;
            }

            $amount = floatval($item->get_meta('_ab_gutschein_amount'));
            if ($amount <= 0) {
                $amount = floatval($item->get_total());
            }
            if ($amount <= 0) {
                continue;
            }

            $recipient_email = $item->get_meta('_ab_gutschein_recipient_email');
            $message = $item->get_meta('_ab_gutschein_message');
            $sender_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();

            // Coupon-Code generieren
            $coupon_code = self::generate_coupon_code();

            // WooCommerce Coupon erstellen
            $expiry_date = date('Y-m-d', strtotime('+' . $expiry_days . ' days'));
            $expiry_formatted = date('d.m.Y', strtotime('+' . $expiry_days . ' days'));
            self::create_wc_coupon($coupon_code, $amount, $order_id, $expiry_date);

            // Meta auf Order speichern
            $order->update_meta_data('_ab_gutschein_coupon_code', $coupon_code);
            $order->update_meta_data('_ab_gutschein_coupon_amount', $amount);
            $order->update_meta_data('_ab_gutschein_coupon_expiry', $expiry_formatted);
            $order->save();

            // E-Mail senden
            AB_Gutschein_Email::send_gutschein_email(
                $order,
                $coupon_code,
                $amount,
                $expiry_formatted,
                $recipient_email,
                $message,
                $sender_name
            );

            error_log('[AB Gutschein] Coupon ' . $coupon_code . ' erstellt fuer Order #' . $order_id . ' (Wert: ' . $amount . ' EUR)');
        }
    }

    // -------------------------
    // Coupon-Code Generierung
    // -------------------------

    public static function generate_coupon_code() {
        $prefix = AB_Gutschein_Settings::get_setting('coupon_prefix', 'PO');

        $max_attempts = 10;
        for ($i = 0; $i < $max_attempts; $i++) {
            $part1 = strtoupper(wp_generate_password(4, false));
            $part2 = strtoupper(wp_generate_password(4, false));
            $code = $prefix . '-' . $part1 . '-' . $part2;

            // Pruefen ob Coupon schon existiert
            $existing = new WC_Coupon($code);
            if ($existing->get_id() === 0) {
                return $code;
            }
        }

        // Fallback mit Timestamp
        return $prefix . '-' . strtoupper(wp_generate_password(4, false)) . '-' . substr(time(), -4);
    }

    public static function create_wc_coupon($code, $amount, $order_id, $expiry_date) {
        $coupon = new WC_Coupon();
        $coupon->set_code($code);
        $coupon->set_discount_type('fixed_cart');
        $coupon->set_amount($amount);
        $coupon->set_date_expires($expiry_date);
        $coupon->set_individual_use(false);
        $coupon->set_usage_limit(0); // Unbegrenzt nutzbar bis Balance aufgebraucht
        $coupon->set_usage_limit_per_user(0);

        $coupon_id = $coupon->save();

        // Eigene Meta-Daten
        update_post_meta($coupon_id, '_ab_gutschein_order_id', $order_id);
        update_post_meta($coupon_id, '_ab_gutschein_original_amount', $amount);

        return $coupon_id;
    }

    // -------------------------
    // Admin Order Meta Display
    // -------------------------

    public static function display_admin_order_meta($order) {
        $coupon_code = $order->get_meta('_ab_gutschein_coupon_code');
        if (empty($coupon_code)) {
            return;
        }

        $amount = $order->get_meta('_ab_gutschein_coupon_amount');
        $expiry = $order->get_meta('_ab_gutschein_coupon_expiry');
        ?>
        <div style="margin-top: 15px; padding: 10px; background: #edfaef; border-left: 4px solid #00a32a;">
            <h3 style="margin: 0 0 5px;">Gutschein</h3>
            <p style="margin: 2px 0;"><strong>Code:</strong> <code><?php echo esc_html($coupon_code); ?></code></p>
            <p style="margin: 2px 0;"><strong>Wert:</strong> <?php echo number_format(floatval($amount), 2, ',', '.'); ?> &euro;</p>
            <p style="margin: 2px 0;"><strong>Gueltig bis:</strong> <?php echo esc_html($expiry); ?></p>
            <?php
            // Restguthaben anzeigen
            $coupon = new WC_Coupon($coupon_code);
            if ($coupon->get_id()) {
                $remaining = $coupon->get_amount();
                echo '<p style="margin: 2px 0;"><strong>Restguthaben:</strong> ' . number_format(floatval($remaining), 2, ',', '.') . ' &euro;</p>';
            }

            // PDF-Download-Button
            $pdf_path = $order->get_meta('_ab_gutschein_pdf');
            if ($pdf_path && file_exists($pdf_path)) {
                $pdf_url = wp_nonce_url(
                    admin_url('admin.php?action=ab_gutschein_pdf_view&order_id=' . $order->get_id()),
                    'ab_gutschein_pdf_' . $order->get_id()
                );
                echo '<p style="margin: 8px 0 2px;"><a href="' . esc_url($pdf_url) . '" class="button button-small" target="_blank">PDF Gutschein anzeigen</a></p>';
            }
            ?>
        </div>
        <?php
    }

    // -------------------------
    // E-Mail-Unterdrueckung
    // -------------------------

    public static function maybe_suppress_completed_email($enabled, $order) {
        if ($order && self::is_pure_gutschein_order($order)) {
            return false;
        }
        return $enabled;
    }

    // -------------------------
    // Gutschein-auf-Gutschein verhindern
    // -------------------------

    public static function prevent_coupon_on_gutschein($valid, $product, $coupon, $values) {
        $coupon_id = $coupon->get_id();
        $is_gutschein_coupon = get_post_meta($coupon_id, '_ab_gutschein_order_id', true);

        if ($is_gutschein_coupon && self::is_gutschein_product($product->get_id())) {
            return false;
        }
        return $valid;
    }

    // -------------------------
    // Stornierung/Refund
    // -------------------------

    public static function on_order_cancelled($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $coupon_code = $order->get_meta('_ab_gutschein_coupon_code');
        if (empty($coupon_code)) {
            return;
        }

        $coupon = new WC_Coupon($coupon_code);
        if ($coupon->get_id()) {
            // Coupon deaktivieren: Usage Limit auf aktuelle Usage setzen
            $coupon->set_usage_limit($coupon->get_usage_count());
            $coupon->save();
            error_log('[AB Gutschein] Coupon ' . $coupon_code . ' deaktiviert wegen Stornierung von Order #' . $order_id);
        }
    }

    // -------------------------
    // Admin: Gutschein-PDF View Handler
    // -------------------------

    public static function handle_gutschein_pdf_view() {
        if (empty($_GET['action']) || $_GET['action'] !== 'ab_gutschein_pdf_view') {
            return;
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_die('Keine Berechtigung.');
        }

        $order_id = intval($_GET['order_id'] ?? 0);
        if (!$order_id) {
            wp_die('Ungueltige Bestellung.');
        }

        if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'ab_gutschein_pdf_' . $order_id)) {
            wp_die('Sicherheitscheck fehlgeschlagen.');
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_die('Bestellung nicht gefunden.');
        }

        $pdf_path = $order->get_meta('_ab_gutschein_pdf');
        if (!$pdf_path || !file_exists($pdf_path)) {
            wp_die('PDF nicht gefunden.');
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="gutschein-' . $order_id . '.pdf"');
        header('Content-Length: ' . filesize($pdf_path));
        readfile($pdf_path);
        exit;
    }
}
