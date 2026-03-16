<?php
class AB_Payment_Settings {
    public static function init() {
        // Menü-Registrierung erfolgt über AB_Combined_Settings (kombinierte Seite)
        add_action('admin_init', [__CLASS__, 'register_settings']);
    }

    public static function register_settings() {
        register_setting('ab_payment_settings', 'ab_payment_method');
        register_setting('ab_payment_settings', 'ab_payment_details');

        add_settings_section(
            'ab_payment_section',
            'Zahlungsmethode',
            null,
            'ab-payment-settings'
        );

        add_settings_field(
            'ab_payment_method',
            'Zahlungsmethode',
            [__CLASS__, 'render_method_field'],
            'ab-payment-settings',
            'ab_payment_section'
        );

        add_settings_field(
            'ab_payment_details',
            'Details',
            [__CLASS__, 'render_details_field'],
            'ab-payment-settings',
            'ab_payment_section'
        );

        // Prüfen, ob die Zahlungsmethode "direct_debit" (Lastschrift) gewählt ist
        $method = get_option('ab_payment_method', 'direct_debit');
        if ($method === 'direct_debit') {


          register_setting('ab_payment_settings', 'ab_sepa_intro_text');

          add_settings_field(
              'ab_sepa_intro_text',
              'Einleitungstext für Lastschrift',
              [__CLASS__, 'render_sepa_intro_field'],
              'ab-payment-settings',
              'ab_payment_section'
          );

            register_setting('ab_payment_settings', 'ab_sepa_accordion_title');
            register_setting('ab_payment_settings', 'ab_sepa_accordion_content');

            add_settings_field(
                'ab_sepa_accordion_title',
                'SEPA-Akkordeon Titel',
                [__CLASS__, 'render_sepa_title_field'],
                'ab-payment-settings',
                'ab_payment_section'
            );

            add_settings_field(
                'ab_sepa_accordion_content',
                'SEPA-Akkordeon Inhalt',
                [__CLASS__, 'render_sepa_content_field'],
                'ab-payment-settings',
                'ab_payment_section'
            );
        }

        register_setting('ab_payment_settings', 'ab_ahv_enabled');

        add_settings_field(
            'ab_ahv_enabled',
            'AHV-Nummer abfragen',
            [__CLASS__, 'render_ahv_field'],
            'ab-payment-settings',
            'ab_payment_section'
        );
    }

    public static function render_ahv_field() {
        $enabled = get_option('ab_ahv_enabled', '0');
        ?>
        <label>
            <input type="checkbox" name="ab_ahv_enabled" value="1" <?php checked($enabled, '1'); ?>>
            AHV-Nummer im Vertragswizard abfragen (für Schweizer Seite)
        </label>
        <?php
    }

    public static function render_settings_page() {
        ?>
        <div class="wrap">
            <h1>Zahlungseinstellungen</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('ab_payment_settings');
                do_settings_sections('ab-payment-settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public static function render_method_field() {
        $method = get_option('ab_payment_method', 'direct_debit');
        ?>
        <select name="ab_payment_method" id="ab_payment_method">
            <?php foreach (AB_Payment_Methods::get_methods() as $key => $label): ?>
                <option value="<?php echo esc_attr($key); ?>"
                        <?php selected($method, $key); ?>>
                    <?php echo esc_html($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    public static function render_details_field() {
        $details = get_option('ab_payment_details', []);
        $method = get_option('ab_payment_method', 'direct_debit');
        $fields = AB_Payment_Methods::get_fields()[$method] ?? [];

        foreach ($fields as $key => $field): ?>
            <div class="field-row">
                <label><?php echo esc_html($field['label']); ?></label>
                <?php if ($field['type'] === 'textarea'): ?>
                    <textarea name="ab_payment_details[<?php echo esc_attr($key); ?>]"
                              rows="4" style="width: 100%;"><?php
                        echo esc_textarea($details[$key] ?? '');
                    ?></textarea>
                <?php else: ?>
                    <input type="<?php echo esc_attr($field['type']); ?>"
                           name="ab_payment_details[<?php echo esc_attr($key); ?>]"
                           value="<?php echo esc_attr($details[$key] ?? ''); ?>"
                           style="width: 100%;">
                <?php endif; ?>
            </div>
        <?php endforeach;
    }

    public static function render_sepa_title_field() {
        $title = get_option('ab_sepa_accordion_title', 'Wichtige Informationen zu deinem SEPA-Lastschriftmandat');
        ?>
        <input type="text" name="ab_sepa_accordion_title" value="<?php echo esc_attr($title); ?>" style="width: 100%;">
        <?php
    }

    public static function render_sepa_content_field() {
        $content = get_option('ab_sepa_accordion_content', '');
        wp_editor(
            wp_kses_post($content),
            'ab_sepa_accordion_content',
            [
                'textarea_name' => 'ab_sepa_accordion_content',
                'textarea_rows' => 6,
                'media_buttons' => false,
                'teeny'        => false,
                'quicktags'    => true,
                'wpautop'      => false,
                'tinymce'      => [
                    'verify_html' => false,
                    'forced_root_block' => false
                ]
            ]
        );
    }

    public static function render_sepa_intro_field() {
        $intro_text = get_option('ab_sepa_intro_text', 'Super, dass du alle deine persönlichen Daten eingetragen hast! Bei uns kannst du bequem per Lastschriftverfahren bezahlen. Damit wir dies für dich einrichten können, benötigen wir noch die folgenden Bankdaten.');
        wp_editor(
            wp_kses_post($intro_text),
            'ab_sepa_intro_text',
            [
                'textarea_name' => 'ab_sepa_intro_text',
                'textarea_rows' => 4,
                'media_buttons' => false,
                'teeny'        => false,
                'quicktags'    => true,
                'wpautop'      => false,
                'tinymce'      => [
                    'verify_html' => false,
                    'forced_root_block' => false
                ]
            ]
        );
    }





}
