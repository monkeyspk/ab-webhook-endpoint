<?php
if (!defined('ABSPATH')) {
    exit;
}

class AB_Gutschein_Settings {

    const OPTION_KEY = 'ab_gutschein_settings';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_menu_page']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('wp_ajax_ab_create_gutschein_product', [__CLASS__, 'ajax_create_product']);
    }

    public static function add_menu_page() {
        add_submenu_page(
            'parkourone',
            'Gutschein Einstellungen',
            'Gutscheine',
            'manage_woocommerce',
            'ab-gutschein-settings',
            [__CLASS__, 'render_settings_page']
        );
    }

    public static function register_settings() {
        register_setting('ab_gutschein_settings', self::OPTION_KEY);
    }

    public static function get_setting($key, $default = '') {
        $options = get_option(self::OPTION_KEY, []);
        return isset($options[$key]) ? $options[$key] : $default;
    }

    public static function get_preset_amounts() {
        $amounts_str = self::get_setting('preset_amounts', '25,50,100');
        $amounts = array_map('trim', explode(',', $amounts_str));
        $amounts = array_filter($amounts, function($v) { return is_numeric($v) && floatval($v) > 0; });
        return array_map('floatval', $amounts);
    }

    public static function ajax_create_product() {
        check_ajax_referer('ab_gutschein_create_product', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Keine Berechtigung']);
            return;
        }

        $product = new WC_Product_Simple();
        $product->set_name('Gutschein');
        $product->set_status('publish');
        $product->set_catalog_visibility('visible');
        $product->set_price(0);
        $product->set_regular_price(0);
        $product->set_virtual(true);
        $product->set_sold_individually(false);
        $product->set_manage_stock(false);
        $product->set_description('Verschenke unvergessliche Parkour-Erlebnisse mit einem Parkour ONE Gutschein!');
        $product->set_short_description('Parkour ONE Gutschein - der perfekte Geschenk fuer Parkour-Fans.');
        $product_id = $product->save();

        if ($product_id) {
            update_post_meta($product_id, '_ab_is_gutschein', 'yes');

            // Produkt-ID in Settings speichern
            $options = get_option(self::OPTION_KEY, []);
            $options['product_id'] = $product_id;
            update_option(self::OPTION_KEY, $options);

            wp_send_json_success([
                'message' => 'Gutschein-Produkt erstellt (ID: ' . $product_id . ')',
                'product_id' => $product_id
            ]);
        } else {
            wp_send_json_error(['message' => 'Fehler beim Erstellen des Produkts']);
        }
    }

    public static function render_settings_page() {
        $options = get_option(self::OPTION_KEY, []);
        $product_id = $options['product_id'] ?? '';
        ?>
        <div class="wrap">
            <h1>AB Gutschein Einstellungen</h1>

            <form method="post" action="options.php">
                <?php settings_fields('ab_gutschein_settings'); ?>

                <!-- Produkt-Setup -->
                <div style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin-bottom: 20px;">
                    <h2 style="margin-top: 0;">Gutschein-Produkt</h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row">Produkt-ID</th>
                            <td>
                                <input type="number" class="regular-text" name="<?php echo self::OPTION_KEY; ?>[product_id]"
                                       value="<?php echo esc_attr($product_id); ?>"
                                       placeholder="WooCommerce Produkt-ID" id="ab-gutschein-product-id">
                                <p class="description">
                                    Die WooCommerce Produkt-ID des Gutschein-Produkts.
                                    <?php if (!empty($product_id)) : ?>
                                        <a href="<?php echo esc_url(get_edit_post_link($product_id)); ?>" target="_blank">Produkt bearbeiten</a>
                                    <?php endif; ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Produkt erstellen</th>
                            <td>
                                <button type="button" class="button" id="ab-create-gutschein-product">
                                    Gutschein-Produkt automatisch erstellen
                                </button>
                                <span id="ab-create-product-feedback" style="margin-left: 10px;"></span>
                                <p class="description">Erstellt ein neues virtuelles WooCommerce-Produkt mit dem Meta-Flag <code>_ab_is_gutschein</code>.</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Betrags-Einstellungen -->
                <div style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin-bottom: 20px;">
                    <h2 style="margin-top: 0;">Betragsoptionen</h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row">Preset-Betraege</th>
                            <td>
                                <input type="text" class="regular-text" name="<?php echo self::OPTION_KEY; ?>[preset_amounts]"
                                       value="<?php echo esc_attr($options['preset_amounts'] ?? '25,50,100'); ?>"
                                       placeholder="25,50,100">
                                <p class="description">Komma-getrennte Betraege fuer die Schnellauswahl (z.B. 25,50,100).</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Mindestbetrag (eigener Wert)</th>
                            <td>
                                <input type="number" class="small-text" name="<?php echo self::OPTION_KEY; ?>[min_amount]"
                                       value="<?php echo esc_attr($options['min_amount'] ?? '10'); ?>"
                                       min="1" step="1"> &euro;
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Maximalbetrag (eigener Wert)</th>
                            <td>
                                <input type="number" class="small-text" name="<?php echo self::OPTION_KEY; ?>[max_amount]"
                                       value="<?php echo esc_attr($options['max_amount'] ?? '500'); ?>"
                                       min="1" step="1"> &euro;
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Coupon-Einstellungen -->
                <div style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin-bottom: 20px;">
                    <h2 style="margin-top: 0;">Coupon-Einstellungen</h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row">Code-Prefix</th>
                            <td>
                                <input type="text" class="small-text" name="<?php echo self::OPTION_KEY; ?>[coupon_prefix]"
                                       value="<?php echo esc_attr($options['coupon_prefix'] ?? 'PO'); ?>"
                                       placeholder="PO" maxlength="5">
                                <p class="description">Prefix fuer den Gutschein-Code (z.B. PO ergibt PO-XXXX-XXXX).</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Gueltigkeit</th>
                            <td>
                                <input type="number" class="small-text" name="<?php echo self::OPTION_KEY; ?>[expiry_days]"
                                       value="<?php echo esc_attr($options['expiry_days'] ?? '365'); ?>"
                                       min="1" step="1"> Tage
                                <p class="description">Wie lange der Gutschein gueltig ist (Standard: 365 Tage = 1 Jahr).</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- PDF-Einstellungen -->
                <div style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin-bottom: 20px;">
                    <h2 style="margin-top: 0;">PDF-Gutschein</h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row">PDF als E-Mail-Anhang</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="<?php echo self::OPTION_KEY; ?>[pdf_enabled]" value="1"
                                        <?php checked( ! empty( $options['pdf_enabled'] ) ); ?>>
                                    PDF-Gutschein generieren und an E-Mails anhaengen
                                </label>
                                <p class="description">Wenn aktiviert, wird ein druckbarer PDF-Gutschein (A4 Querformat) erstellt und sowohl der Empfaenger- als auch der Kaeufer-Mail angehaengt.</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Hinweis: E-Mail-Einstellungen -->
                <div style="background: #f0f6fc; border: 1px solid #72aee6; padding: 15px 20px; margin-bottom: 20px; border-radius: 4px;">
                    <p style="margin: 0;">
                        <strong>E-Mail-Einstellungen</strong> fuer Gutschein-Mails (Betreff, Inhalt, Aktivierung) findest du im
                        <a href="<?php echo admin_url('admin.php?page=ab-email-settings'); ?>"><strong>AB Email Customizer</strong></a>.
                    </p>
                </div>

                <?php submit_button('Einstellungen speichern'); ?>
            </form>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('#ab-create-gutschein-product').on('click', function() {
                var $btn = $(this);
                var $feedback = $('#ab-create-product-feedback');
                $btn.prop('disabled', true);
                $feedback.text('Wird erstellt...');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'ab_create_gutschein_product',
                        nonce: '<?php echo wp_create_nonce('ab_gutschein_create_product'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $feedback.css('color', '#00a32a').text(response.data.message);
                            $('#ab-gutschein-product-id').val(response.data.product_id);
                        } else {
                            $feedback.css('color', '#d63638').text(response.data.message);
                        }
                    },
                    error: function() {
                        $feedback.css('color', '#d63638').text('Fehler bei der Anfrage.');
                    },
                    complete: function() {
                        $btn.prop('disabled', false);
                    }
                });
            });
        });
        </script>
        <?php
    }
}
