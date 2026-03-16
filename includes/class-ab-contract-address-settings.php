<?php
// Menü-Registrierung erfolgt über AB_Combined_Settings (kombinierte Seite)

// Diese Funktion rendert die Adressänderung-Einstellungsseite
function ab_contract_address_page() {
    // Speichere die Einstellungen, wenn das Formular gesendet wurde
    if (isset($_POST['ab_save_address'])) {
        if (isset($_POST['ab_address_nonce']) && wp_verify_nonce($_POST['ab_address_nonce'], 'ab_save_address')) {

            // Definiere die Felder, die gespeichert werden sollen
            $footer_fields = [
                'footer_row1_col1', 'footer_row1_col2', 'footer_row1_col3', 'footer_row1_col4',
                'footer_row2_col1', 'footer_row2_col2', 'footer_row2_col3', 'footer_row2_col4'
            ];

            // Speichere jedes Feld in den WordPress-Optionen
            foreach ($footer_fields as $field) {
                if (isset($_POST[$field])) {
                    update_option('ab_' . $field, sanitize_text_field($_POST[$field]));
                }
            }

            echo '<div class="notice notice-success is-dismissible"><p>Adressdaten wurden erfolgreich gespeichert!</p></div>';
        }
    }

    // Hole die aktuellen Werte oder setze Standardwerte
    $footer_values = [
        'footer_row1_col1' => get_option('ab_footer_row1_col1', 'ParkourONE Berlin –'),
        'footer_row1_col2' => get_option('ab_footer_row1_col2', 'Dietzgenstraße 25'),
        'footer_row1_col3' => get_option('ab_footer_row1_col3', 'M berlin@parkourone.com'),
        'footer_row1_col4' => get_option('ab_footer_row1_col4', 'UST DE 256255841'),
        'footer_row2_col1' => get_option('ab_footer_row2_col1', 'Benjamin Scheffler'),
        'footer_row2_col2' => get_option('ab_footer_row2_col2', '13156 Berlin'),
        'footer_row2_col3' => get_option('ab_footer_row2_col3', 'T +49 30 48 49 42 40'),
        'footer_row2_col4' => get_option('ab_footer_row2_col4', 'www.berlin.parkourone.com')
    ];

    ?>
    <div class="wrap">
        <h1>Adressdaten für Vertrags-PDF anpassen</h1>
        <p>Hier können Sie die Adressdaten im Footer des Vertrags-PDFs anpassen.</p>

        <form method="post">
            <?php wp_nonce_field('ab_save_address', 'ab_address_nonce'); ?>

            <table class="form-table">
                <tr>
                    <th colspan="4"><h3>Erste Zeile</h3></th>
                </tr>
                <tr>
                    <th><label for="footer_row1_col1">Spalte 1</label></th>
                    <th><label for="footer_row1_col2">Spalte 2</label></th>
                    <th><label for="footer_row1_col3">Spalte 3</label></th>
                    <th><label for="footer_row1_col4">Spalte 4</label></th>
                </tr>
                <tr>
                    <td><input type="text" id="footer_row1_col1" name="footer_row1_col1" value="<?php echo esc_attr($footer_values['footer_row1_col1']); ?>" class="regular-text"></td>
                    <td><input type="text" id="footer_row1_col2" name="footer_row1_col2" value="<?php echo esc_attr($footer_values['footer_row1_col2']); ?>" class="regular-text"></td>
                    <td><input type="text" id="footer_row1_col3" name="footer_row1_col3" value="<?php echo esc_attr($footer_values['footer_row1_col3']); ?>" class="regular-text"></td>
                    <td><input type="text" id="footer_row1_col4" name="footer_row1_col4" value="<?php echo esc_attr($footer_values['footer_row1_col4']); ?>" class="regular-text"></td>
                </tr>

                <tr>
                    <th colspan="4"><h3>Zweite Zeile</h3></th>
                </tr>
                <tr>
                    <th><label for="footer_row2_col1">Spalte 1</label></th>
                    <th><label for="footer_row2_col2">Spalte 2</label></th>
                    <th><label for="footer_row2_col3">Spalte 3</label></th>
                    <th><label for="footer_row2_col4">Spalte 4</label></th>
                </tr>
                <tr>
                    <td><input type="text" id="footer_row2_col1" name="footer_row2_col1" value="<?php echo esc_attr($footer_values['footer_row2_col1']); ?>" class="regular-text"></td>
                    <td><input type="text" id="footer_row2_col2" name="footer_row2_col2" value="<?php echo esc_attr($footer_values['footer_row2_col2']); ?>" class="regular-text"></td>
                    <td><input type="text" id="footer_row2_col3" name="footer_row2_col3" value="<?php echo esc_attr($footer_values['footer_row2_col3']); ?>" class="regular-text"></td>
                    <td><input type="text" id="footer_row2_col4" name="footer_row2_col4" value="<?php echo esc_attr($footer_values['footer_row2_col4']); ?>" class="regular-text"></td>
                </tr>
            </table>

            <p class="submit">
                <input type="submit" name="ab_save_address" class="button button-primary" value="Änderungen speichern">
            </p>
        </form>
    </div>
    <?php
}
