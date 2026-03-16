<?php
if (!defined('ABSPATH')) {
    exit;
}

class AB_Contract_Step_1 {
    public static function render($order, $contract_id) {
        // Hole die gespeicherten Vertragsdaten
        $contract_data = get_post_meta($order->get_id(), '_ab_contract_data', true) ?? [];

        // Hole die Teilnehmerdaten
        $participant_data = self::get_participant_data($order);

        // Setze die Standardwerte, priorisiere Participant-Daten über Rechnungsdaten
        $vorname = $contract_data['vorname'] ?? $participant_data['vorname'] ?? '';
        $nachname = $contract_data['nachname'] ?? $participant_data['nachname'] ?? '';
        $geburtsdatum = $contract_data['geburtsdatum'] ?? $participant_data['geburtsdatum'] ?? '';
        $anrede = $contract_data['anrede'] ?? '';

        // Adressdaten aus WooCommerce
        $strasse = $contract_data['strasse'] ?? self::extract_street_name($order->get_billing_address_1());
        $hausnummer = $contract_data['hausnummer'] ?? $order->get_billing_address_2();
        $plz = $contract_data['plz'] ?? $order->get_billing_postcode();
        $ort = $contract_data['ort'] ?? $order->get_billing_city();
        $telefon = $contract_data['telefon'] ?? $order->get_billing_phone();
        $email = $contract_data['email'] ?? $order->get_billing_email();
        $besonderheiten = $contract_data['besonderheiten'] ?? '';
        $ahv_nummer = $contract_data['ahv_nummer'] ?? '';

        // Berechne das Alter
        $is_minor = self::is_minor($geburtsdatum);

        // Wenn minderjährig, hole die Rechnungsdaten für Erziehungsberechtigte
        if ($is_minor) {
            $erziehungsberechtigter_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
            $erziehungsberechtigter_telefon = $order->get_billing_phone();
            $erziehungsberechtigter_email = $order->get_billing_email();
        }

        ob_start();
        ?>
        <form method="post" class="contract-wizard-form">
            <input type="hidden" name="form_action" value="save_step1">
            <input type="hidden" name="current_step" value="1">
            <input type="hidden" name="order_id" value="<?php echo esc_attr($order->get_id()); ?>">
            <input type="hidden" name="token" value="<?php echo isset($_GET['token']) ? esc_attr($_GET['token']) : ''; ?>">


            <?php wp_nonce_field('contract_wizard_nonce', 'nonce'); ?>

            <div class="form-row">
                <label for="anrede">Anrede *</label>
                <select id="anrede" name="anrede" required>
                    <option value="">Bitte wählen</option>
                    <option value="Herr" <?php selected($anrede, 'Herr'); ?>>Herr</option>
                    <option value="Frau" <?php selected($anrede, 'Frau'); ?>>Frau</option>
                    <option value="Divers" <?php selected($anrede, 'Divers'); ?>>Divers</option>
                </select>
            </div>

            <div class="form-row-flex">
                <div class="form-col">
                    <label for="vorname">Vorname *</label>
                    <input type="text" id="vorname" name="vorname"
                           value="<?php echo esc_attr($vorname); ?>"
                           required>
                </div>
                <div class="form-col">
                    <label for="nachname">Name *</label>
                    <input type="text" id="nachname" name="nachname"
                           value="<?php echo esc_attr($nachname); ?>"
                           required>
                </div>
            </div>

            <div class="form-row">
                <label for="geburtsdatum">Geburtsdatum *</label>
                <input type="date" id="geburtsdatum" name="geburtsdatum"
                       value="<?php echo esc_attr($geburtsdatum); ?>"
                       max="<?php echo date('Y-m-d'); ?>"
                       required>
            </div>

            <?php if (get_option('ab_ahv_enabled', '0') === '1'): ?>
                <div class="form-row">
                    <label for="ahv_nummer">AHV-Nummer * <a href="#" id="ahv-info-toggle" style="font-weight:normal;font-size:0.85em;margin-left:6px;">Warum AHV-Nummer?</a></label>
                    <input type="text" id="ahv_nummer" name="ahv_nummer"
                           value="<?php echo esc_attr($ahv_nummer); ?>"
                           placeholder="756.XXXX.XXXX.XX"
                           required>
                </div>

                <!-- AHV Info Modal -->
                <div id="ahv-info-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;justify-content:center;align-items:center;">
                    <div style="background:#fff;border-radius:12px;padding:30px;max-width:520px;width:90%;max-height:80vh;overflow-y:auto;position:relative;box-shadow:0 4px 20px rgba(0,0,0,0.15);">
                        <button id="ahv-modal-close" style="position:absolute;top:12px;right:16px;background:none;border:none;font-size:1.5em;cursor:pointer;color:#666;">&times;</button>
                        <h3 style="margin-top:0;">Warum benötigen wir die AHV-Nummer?</h3>
                        <h4 style="margin-bottom:6px;">J+S Programm</h4>
                        <p style="margin-top:0;">ParkourONE ist ein anerkannter Jugend+Sport Verein. J+S ist das grösste Sportförderungsprogramm des Bundes und unterstützt Sportangebote für Kinder und Jugendliche. Damit wir die Fördergelder beantragen können, benötigen wir die AHV-Nummer aller teilnehmenden Kinder und Jugendlichen.</p>
                        <h4 style="margin-bottom:6px;">Vorteile des J+S Programms</h4>
                        <ul style="margin-top:0;padding-left:1.2em;">
                            <li>Bundesförderung für qualitativ hochwertiges Training</li>
                            <li>Ausgebildete J+S-Coaches mit anerkannter Zertifizierung</li>
                            <li>Günstigere Mitgliedschaftsbeiträge dank Fördergeldern</li>
                            <li>Regelmässige Qualitätskontrollen und Weiterbildungen</li>
                        </ul>
                        <p style="font-size:0.85em;color:#666;margin-bottom:0;">Deine Daten werden vertraulich behandelt und ausschliesslich für die J+S-Anmeldung verwendet.</p>
                    </div>
                </div>

                <script>
                (function() {
                    var toggle = document.getElementById('ahv-info-toggle');
                    var modal = document.getElementById('ahv-info-modal');
                    var close = document.getElementById('ahv-modal-close');
                    if (toggle && modal) {
                        toggle.addEventListener('click', function(e) {
                            e.preventDefault();
                            modal.style.display = 'flex';
                        });
                        close.addEventListener('click', function() {
                            modal.style.display = 'none';
                        });
                        modal.addEventListener('click', function(e) {
                            if (e.target === modal) modal.style.display = 'none';
                        });
                    }
                })();
                </script>
            <?php endif; ?>

            <div class="form-row-flex">
                <div class="form-col">
                    <label for="strasse">Straße *</label>
                    <input type="text" id="strasse" name="strasse"
                           value="<?php echo esc_attr($strasse); ?>"
                           required>
                </div>
                <div class="form-col-narrow">
                    <label for="hausnummer">Hausnummer *</label>
                    <input type="text" id="hausnummer" name="hausnummer"
                           value="<?php echo esc_attr($hausnummer); ?>"
                           required>
                </div>
            </div>

            <div class="form-row-flex">
                <div class="form-col-narrow">
                    <label for="plz">PLZ *</label>
                    <input type="text" id="plz" name="plz"
                           value="<?php echo esc_attr($plz); ?>"
                           pattern="[0-9]{4,5}"
                           required>
                </div>
                <div class="form-col">
                    <label for="ort">Ort *</label>
                    <input type="text" id="ort" name="ort"
                           value="<?php echo esc_attr($ort); ?>"
                           required>
                </div>
            </div>

            <div class="form-row-flex form-row-flex-data">
                <div class="form-col contact-field">
                    <label for="telefon">Telefon *</label>
                    <input type="tel" id="telefon" name="telefon"
                           value="<?php echo esc_attr($telefon); ?>"
                           required>
                </div>
                <div class="form-col contact-field">
                    <label for="email">E-Mail *</label>
                    <input type="email" id="email" name="email"
                           value="<?php echo esc_attr($email); ?>"
                           required>
                </div>
            </div>



            <div class="form-row">
                <label for="besonderheiten">Besonderheiten / Allergien</label>
                <textarea
                    id="besonderheiten"
                    name="besonderheiten"
                    rows="4"
                    placeholder="Besonderheiten, Allergien oder wichtige Hinweise, die unsere Coaches im Training wissen sollten."><?php echo esc_textarea($besonderheiten); ?></textarea>
            </div>






            <!-- Zusätzliche Sektion für Erziehungsberechtigte -->
            <?php if ($is_minor): ?>
                <div class="guardian-section">
                    <div class="content">
                        <h3>Erziehungsberechtigte</h3>
                        <div class="form-row">
                            <label for="erziehungsberechtigter_name">Name der Erziehungsberechtigten Person *</label>
                            <input type="text"
                                   id="erziehungsberechtigter_name"
                                   name="erziehungsberechtigter_name"
                                   value="<?php echo esc_attr($erziehungsberechtigter_name); ?>"
                                   required>
                        </div>
                        <div class="form-row">
                            <label for="erziehungsberechtigter_telefon">Telefonnummer *</label>
                            <input type="tel"
                                   id="erziehungsberechtigter_telefon"
                                   name="erziehungsberechtigter_telefon"
                                   value="<?php echo esc_attr($erziehungsberechtigter_telefon); ?>"
                                   required>
                        </div>
                        <div class="form-row">
                            <label for="erziehungsberechtigter_email">E-Mail *</label>
                            <input type="email"
                                   id="erziehungsberechtigter_email"
                                   name="erziehungsberechtigter_email"
                                   value="<?php echo esc_attr($erziehungsberechtigter_email); ?>"
                                   required>
                        </div>
                    </div>
                </div>
            <?php endif; ?>









            <?php echo AB_Contract_Wizard::get_navigation_buttons(1); ?>
        </form>
        <?php
        return ob_get_clean();
    }




    /**
        * Berechnet, ob eine Person minderjährig ist.
        *
        * @param string $geburtsdatum Geburtsdatum im Format 'YYYY-MM-DD'.
        * @return bool True, wenn minderjährig, sonst False.
        */
       private static function is_minor($geburtsdatum) {
           if (empty($geburtsdatum)) {
               return false;
           }

           // Verschiedene Datumsformate akzeptieren
           $birth_date = DateTime::createFromFormat('Y-m-d', $geburtsdatum)
                      ?: DateTime::createFromFormat('d.m.Y', $geburtsdatum)
                      ?: DateTime::createFromFormat('d-m-Y', $geburtsdatum);

           if (!$birth_date) {
               // Letzter Fallback
               try { $birth_date = new DateTime($geburtsdatum); } catch (Exception $e) { return false; }
           }

           $today = new DateTime();
           $age = $today->diff($birth_date)->y;

           return $age < 18;
       }




    private static function get_participant_data($order) {
        foreach ($order->get_items() as $item) {
            $participants = $item->get_meta('_event_participant_data');
            if (!empty($participants) && is_array($participants)) {
                $first_participant = reset($participants);
                $geb = $first_participant['geburtsdatum'] ?? '';

                // Geburtsdatum ins HTML-date Format (YYYY-MM-DD) normalisieren
                // Akzeptiert: dd.mm.yyyy, dd-mm-yyyy, dd/mm/yyyy
                if (!empty($geb) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $geb)) {
                    $parsed = DateTime::createFromFormat('d.m.Y', $geb)
                           ?: DateTime::createFromFormat('d-m-Y', $geb)
                           ?: DateTime::createFromFormat('d/m/Y', $geb);
                    if ($parsed) {
                        $geb = $parsed->format('Y-m-d');
                    }
                }

                return [
                    'vorname' => $first_participant['vorname'] ?? '',
                    'nachname' => $first_participant['name'] ?? '',
                    'geburtsdatum' => $geb
                ];
            }
        }

        return [];
    }

    private static function extract_street_name($full_address) {
        return preg_replace('/\s+\d+\s*[a-zA-Z]?$/', '', $full_address);
    }

    private static function extract_street_number($full_address) {
        return preg_match('/\s+(\d+\s*[a-zA-Z]?)$/', $full_address, $matches) ? $matches[1] : '';
    }
}
