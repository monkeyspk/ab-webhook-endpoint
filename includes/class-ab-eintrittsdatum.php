<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Eintrittsdatum: einmalig gesetztes Datum, wenn ein Kunde zum ersten
 * Mal Schüler_in wird. Wird bei weiteren Vertragsabschlüssen NICHT mehr
 * überschrieben. Admins können es manuell editieren.
 *
 * Speicherstrategie:
 * - Primär auf dem WP-User (user_meta "_ab_eintrittsdatum"), falls Kunde einen Account hat
 * - Zusätzlich als Order-Meta "_ab_eintrittsdatum" (für Admin-Sichtbarkeit pro Order)
 * - Fallback-Lookup über alle Orders mit gleicher billing_email
 */
class AB_Eintrittsdatum {

    const META_KEY = '_ab_eintrittsdatum';

    public static function init() {
        // Admin: Feld in der Order-Edit-Seite anzeigen und editierbar machen
        add_action('woocommerce_admin_order_data_after_billing_address', [__CLASS__, 'display_admin_field']);
        add_action('woocommerce_process_shop_order_meta', [__CLASS__, 'save_admin_field'], 20, 2);
    }

    /**
     * Setzt das Eintrittsdatum für den Kunden der Order — nur wenn noch nicht gesetzt.
     */
    public static function set_if_empty_for_order($order) {
        if (!$order instanceof WC_Order) {
            $order = wc_get_order($order);
        }
        if (!$order) {
            return false;
        }

        $email = $order->get_billing_email();
        $existing = self::get_for_email($email);

        if (!empty($existing)) {
            // Schon vorhanden — nur auf aktueller Order spiegeln, nicht überschreiben
            $order->update_meta_data(self::META_KEY, $existing);
            $order->save();
            return $existing;
        }

        $today = current_time('Y-m-d');

        // Auf User-Meta speichern, falls Kunde einen Account hat
        $user_id = $order->get_user_id();
        if ($user_id) {
            update_user_meta($user_id, self::META_KEY, $today);
        }

        // Auf Order spiegeln
        $order->update_meta_data(self::META_KEY, $today);
        $order->save();

        return $today;
    }

    /**
     * Liefert das Eintrittsdatum für eine E-Mail (sucht User und Orders ab).
     */
    public static function get_for_email($email) {
        if (empty($email)) {
            return '';
        }

        // 1. User-Meta prüfen
        $user = get_user_by('email', $email);
        if ($user) {
            $date = get_user_meta($user->ID, self::META_KEY, true);
            if (!empty($date)) {
                return $date;
            }
        }

        // 2. Über alle Orders mit dieser Mail suchen — frühestes Datum gewinnt
        $orders = wc_get_orders([
            'billing_email' => $email,
            'limit'         => -1,
            'orderby'       => 'date',
            'order'         => 'ASC',
            'meta_key'      => self::META_KEY,
        ]);

        foreach ($orders as $o) {
            $date = $o->get_meta(self::META_KEY);
            if (!empty($date)) {
                return $date;
            }
        }

        return '';
    }

    /**
     * Admin: Feld in der Order-Edit-Seite anzeigen.
     */
    public static function display_admin_field($order) {
        if (!$order instanceof WC_Order) {
            return;
        }

        $email = $order->get_billing_email();
        $date = $order->get_meta(self::META_KEY);
        if (empty($date)) {
            $date = self::get_for_email($email);
        }
        ?>
        <div class="ab-eintrittsdatum" style="margin-top:12px;padding-top:12px;border-top:1px solid #eee;">
            <p>
                <strong><?php esc_html_e('Eintrittsdatum', 'ab-webhook-endpoint'); ?>:</strong>
                <input type="date"
                       name="_ab_eintrittsdatum"
                       value="<?php echo esc_attr($date); ?>"
                       style="margin-left:8px;" />
                <br>
                <small style="color:#666;">
                    <?php esc_html_e('Einmalig beim ersten Vertragsabschluss gesetzt. Kann hier manuell angepasst werden.', 'ab-webhook-endpoint'); ?>
                </small>
            </p>
        </div>
        <?php
    }

    /**
     * Admin: Feld speichern wenn Admin es editiert.
     */
    public static function save_admin_field($order_id, $post) {
        if (!isset($_POST['_ab_eintrittsdatum'])) {
            return;
        }

        $date = sanitize_text_field($_POST['_ab_eintrittsdatum']);

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // Leerer String = Löschen
        if ($date === '') {
            $order->delete_meta_data(self::META_KEY);
        } else {
            $order->update_meta_data(self::META_KEY, $date);
        }
        $order->save();

        // Auch auf User spiegeln
        $user_id = $order->get_user_id();
        if ($user_id) {
            if ($date === '') {
                delete_user_meta($user_id, self::META_KEY);
            } else {
                update_user_meta($user_id, self::META_KEY, $date);
            }
        }
    }
}

AB_Eintrittsdatum::init();
