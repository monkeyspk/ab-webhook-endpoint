<?php
if (!defined('ABSPATH')) {
    exit;
}

class AB_Gutschein_Balance {

    public static function init() {
        // Balance bei JEDEM Status-Wechsel pruefen (nicht nur completed/processing)
        add_action('woocommerce_order_status_changed', [__CLASS__, 'on_order_status_changed'], 20, 4);

        // Sicherheitsnetz: Discount im Warenkorb auf Restguthaben begrenzen
        add_filter('woocommerce_coupon_get_discount_amount', [__CLASS__, 'cap_discount_at_balance'], 10, 5);

        // Restguthaben im Warenkorb anzeigen
        add_filter('woocommerce_cart_totals_coupon_label', [__CLASS__, 'display_balance_label'], 10, 2);

        // Validierung: Balance > 0 pruefen
        add_filter('woocommerce_coupon_is_valid', [__CLASS__, 'validate_gutschein_coupon'], 10, 3);

        // Fehlermeldung anpassen
        add_filter('woocommerce_coupon_error', [__CLASS__, 'custom_coupon_error_message'], 10, 3);
    }

    /**
     * Bei jedem Status-Wechsel: Balance reduzieren wenn der neue Status als "bezahlt" gilt.
     */
    public static function on_order_status_changed($order_id, $old_status, $new_status, $order) {
        // Alle Status die als "bezahlt" gelten
        $paid_statuses = array_merge(
            wc_get_is_paid_statuses(),
            ['processing', 'completed', 'probetraining', 'schuelerin', 'gutschein']
        );
        $paid_statuses = array_unique($paid_statuses);

        if (in_array($new_status, $paid_statuses, true)) {
            self::reduce_coupon_balance($order_id, $order);
        }
    }

    /**
     * Begrenzt den WooCommerce-Discount auf das tatsaechliche Restguthaben.
     * Verhindert dass WC den vollen Coupon-Betrag abzieht wenn die Balance
     * eigentlich schon reduziert wurde.
     */
    public static function cap_discount_at_balance($discount, $discounting_amount, $cart_item, $single, $coupon) {
        if (!is_a($coupon, 'WC_Coupon')) {
            return $discount;
        }

        $gutschein_order_id = get_post_meta($coupon->get_id(), '_ab_gutschein_order_id', true);
        if (empty($gutschein_order_id)) {
            return $discount;
        }

        $remaining = floatval($coupon->get_amount());
        if ($remaining <= 0) {
            return 0;
        }

        // Discount darf nie hoeher sein als das Restguthaben
        return min($discount, $remaining);
    }

    /**
     * Reduziert den Coupon-Betrag nach Einloesung.
     */
    public static function reduce_coupon_balance($order_id, $order = null) {
        if (!$order) {
            $order = wc_get_order($order_id);
        }
        if (!$order) {
            return;
        }

        $used_coupons = $order->get_coupon_codes();
        if (empty($used_coupons)) {
            return;
        }

        foreach ($used_coupons as $coupon_code) {
            $coupon = new WC_Coupon($coupon_code);
            if (!$coupon->get_id()) {
                continue;
            }

            // Nur fuer unsere Gutschein-Coupons
            $gutschein_order_id = get_post_meta($coupon->get_id(), '_ab_gutschein_order_id', true);
            if (empty($gutschein_order_id)) {
                continue;
            }

            // Bereits verarbeitet fuer diese Order?
            $processed_key = '_ab_balance_reduced_order_' . $order_id;
            if (get_post_meta($coupon->get_id(), $processed_key, true) === 'yes') {
                continue;
            }

            // Discount-Betrag fuer diesen Coupon in dieser Order ermitteln
            $discount_amount = 0;
            foreach ($order->get_items('coupon') as $coupon_item) {
                if (strtolower($coupon_item->get_code()) === strtolower($coupon_code)) {
                    $discount_amount = floatval($coupon_item->get_discount());
                    break;
                }
            }

            if ($discount_amount <= 0) {
                continue;
            }

            // Coupon-Betrag reduzieren
            $current_amount = floatval($coupon->get_amount());
            $new_amount = max(0, $current_amount - $discount_amount);

            $coupon->set_amount($new_amount);

            // Wenn Guthaben aufgebraucht: Coupon praktisch deaktivieren
            if ($new_amount <= 0) {
                $coupon->set_usage_limit($coupon->get_usage_count());
            }

            $coupon->save();

            // Marker setzen
            update_post_meta($coupon->get_id(), $processed_key, 'yes');

            // Order-Note fuer Nachvollziehbarkeit
            $order->add_order_note(sprintf(
                'Gutschein %s: %s EUR abgezogen, Restguthaben: %s EUR',
                $coupon_code,
                number_format($discount_amount, 2, ',', '.'),
                number_format($new_amount, 2, ',', '.')
            ));

            error_log(sprintf(
                '[AB Gutschein] Balance reduziert fuer Coupon %s: %s - %s = %s EUR (Order #%d)',
                $coupon_code,
                number_format($current_amount, 2),
                number_format($discount_amount, 2),
                number_format($new_amount, 2),
                $order_id
            ));
        }
    }

    /**
     * Zeigt Restguthaben im Warenkorb an.
     */
    public static function display_balance_label($label, $coupon) {
        if (!is_a($coupon, 'WC_Coupon')) {
            $coupon = new WC_Coupon($coupon);
        }

        $gutschein_order_id = get_post_meta($coupon->get_id(), '_ab_gutschein_order_id', true);
        if (empty($gutschein_order_id)) {
            return $label;
        }

        $remaining = floatval($coupon->get_amount());
        return sprintf(
            '%s <small>(Restguthaben: %s &euro;)</small>',
            $label,
            number_format($remaining, 2, ',', '.')
        );
    }

    /**
     * Validiert dass der Gutschein noch Guthaben hat.
     */
    public static function validate_gutschein_coupon($valid, $coupon, $discount) {
        if (!$valid) {
            return $valid;
        }

        $gutschein_order_id = get_post_meta($coupon->get_id(), '_ab_gutschein_order_id', true);
        if (empty($gutschein_order_id)) {
            return $valid;
        }

        $remaining = floatval($coupon->get_amount());
        if ($remaining <= 0) {
            throw new Exception(__('Dieser Gutschein wurde bereits vollstaendig eingeloest.', 'ab-webhook-endpoint'));
        }

        return $valid;
    }

    /**
     * Benutzerdefinierte Fehlermeldung fuer aufgebrauchte Gutscheine.
     */
    public static function custom_coupon_error_message($err, $err_code, $coupon) {
        if ($err_code === WC_Coupon::E_WC_COUPON_USAGE_LIMIT_REACHED) {
            $gutschein_order_id = get_post_meta($coupon->get_id(), '_ab_gutschein_order_id', true);
            if (!empty($gutschein_order_id)) {
                return 'Dieser Gutschein wurde bereits vollstaendig eingeloest.';
            }
        }
        return $err;
    }
}
