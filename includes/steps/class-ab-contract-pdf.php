<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Dompdf\Dompdf;
use Dompdf\Options;

class AB_Contract_PDF {

    /**
     * Baut das HTML für das Vertrags-PDF im Material Design-Stil zusammen.
     *
     * Seite 1: Persönliche Daten inkl. Erziehungsberechtigte (falls vorhanden) – zusätzlich mit
     *         Titel („Trainingsvertrag (Trainingsumfang)") und Vertragsinformationen (Vertrags Nr, Abgeschlossen am)
     * Seite 2: Vertragsdetails (mit Hinweis "Dieser Vertrag wurde digital abgeschlossen.")
     * Seite 3: Zahlungsinformationen
     * Seite 4: Vertragsbedingungen inkl. AGB, Datenschutz & Akkordeon-Inhalte
     *
     * Auf allen Seiten erscheint ein fester Header (nur Logo, 50 % kleiner) und ein Footer (4‑spaltig, linksbündig, reduziertes Padding),
     * der dank fixierter Positionierung immer am unteren Rand der Seite steht.
     *
     * @param \WC_Order $order
     * @param array     $contract_data Gespeicherte Vertragsdaten
     * @return string HTML-Inhalt des PDFs
     */
    public static function build_html( \WC_Order $order, $contract_data ) {

        // Debug-Ausgaben
        error_log('Building contract PDF for order: ' . $order->get_id());

        // Ermitteln des Vertragstyps und der Vertragsdetails
        $contract_id = AB_Contract_Wizard::determine_contract_type( $order );
        error_log('Determined contract type: ' . $contract_id);
        $contract_details = AB_Contract_Overview::get_contract_details( $contract_id, $order->get_id() );
        error_log('Contract details (via AB_Contract_Overview): ' . print_r($contract_details, true));

        // Fallback: Falls keine Vertragsdetails vorhanden sind, ließe sich direkt über die Post-Metadaten lesen
        if ( empty( $contract_details ) || empty( $contract_details['vertrag_preis'] ) ) {
            error_log('Fallback: Lese Vertragsdetails direkt aus den Post-Metadaten.');
            $contract_details = [
                'vertrag_preis'    => get_post_meta($contract_id, '_ab_vertrag_preis', true),
                'trainingsumfang'  => get_post_meta($contract_id, '_ab_trainingsumfang', true),
                'verlaengerung'    => get_post_meta($contract_id, '_ab_verlaengerung', true),
                'kuendigungsfrist' => get_post_meta($contract_id, '_ab_kuendigungsfrist', true),
                'probezeit'        => get_post_meta($contract_id, '_ab_probezeit', true),
            ];
        }
        if(empty($contract_details['vertrag_preis'])) {
            error_log('Warning: Contract price is empty');
            $contract_details = array_merge($contract_details, [
                'vertrag_preis'    => '',
                'trainingsumfang'  => '',
                'verlaengerung'    => '',
                'kuendigungsfrist' => '',
                'probezeit'        => ''
            ]);
        }

        // Für die Akkordeon-Inhalte auf Seite 4: Meta-Felder auslesen
        $accordion_basic      = get_post_meta($contract_id, '_ab_accordion_basic', true);
        $accordion_training   = get_post_meta($contract_id, '_ab_accordion_training', true);
        $accordion_conditions = get_post_meta($contract_id, '_ab_accordion_conditions', true);
        $accordion_titles = [
            'basic'      => get_post_meta($contract_id, '_ab_accordion_title_basic', true) ?: 'Allgemeine Vertragsbedingungen',
            'training'   => get_post_meta($contract_id, '_ab_accordion_title_training', true) ?: 'Leistungen ParkourONE',
            'conditions' => get_post_meta($contract_id, '_ab_accordion_title_conditions', true) ?: 'Abwesenheit / Abmeldung',
        ];

        // Klasse aus der Bestellung ermitteln
        $class_name = self::get_training_name_from_order($order);

        // Footer-Daten laden
        $footer_data = [
            'row1' => [
                get_option('ab_footer_row1_col1', 'ParkourONE Berlin –'),
                get_option('ab_footer_row1_col2', 'Dietzgenstraße 25'),
                get_option('ab_footer_row1_col3', 'M berlin@parkourone.com'),
                get_option('ab_footer_row1_col4', 'UST DE 256255841'),
            ],
            'row2' => [
                get_option('ab_footer_row2_col1', 'Benjamin Scheffler'),
                get_option('ab_footer_row2_col2', '13156 Berlin'),
                get_option('ab_footer_row2_col3', 'T +49 30 48 49 42 40'),
                get_option('ab_footer_row2_col4', 'www.berlin.parkourone.com'),
            ]
        ];

        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <!-- Einbinden der Google Fonts (Roboto) -->
            <link href="https://fonts.googleapis.com/css?family=Roboto:400,500,700&display=swap" rel="stylesheet">
            <style>
                /* Seitenränder: 20px für alle Seiten */
                /* Seitenränder: 20px für alle Seiten */
                @page {
                    margin: 20px;
                }
                body {
                    font-family: 'Roboto', sans-serif;
                    background-color: #ffffff;
                    margin: 0;
                    padding: 0;
                    color: #424242;
                }
                /* Fester Header im Material Design-Stil (nur Logo) */
                .header {
                    position: fixed;
                    top: 20px;
                    left: 20px;
                    right: 20px;
                    height: 60px;
                    background-color: #ffffff;
                    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
                    padding: 8px 24px;
                    z-index: 100;
                }
                .header .right {
                    float: right;
                    text-align: right;
                }
                .header .right img {
                    max-height: 40px; /* 50% kleiner als ursprünglich */
                }
                /* Fester Footer, fixiert am unteren Rand der Seite */
                .footer {
                    position: fixed;
                    bottom: 20px;
                    left: 20px;
                    right: 20px;
                    background-color: #ffffff;
                    box-shadow: 0 -2px 4px rgba(0, 0, 0, 0.1);
                    padding: 4px 24px;
                    font-size: 12px;
                    text-align: left;
                }
                .footer-table {
                    width: 100%;
                    border-collapse: collapse;
                }
                .footer-table td {
                    width: 25%;
                    text-align: left;
                    padding: 2px 0;
                    color: #616161;
                }

                /* Seitencontainer: Entferne die automatischen Seitenumbrüche für normale Content-Seiten */
                .content-section {
                    padding: 140px 20px 10px 20px; /* Reduziertes Padding unten */
                    position: relative;
                }

                /* Zahlungsinformationen: Erzwinge Seitenumbrüche davor und danach */
                .payment-section {
                    page-break-before: always;
                    page-break-after: always;
                    padding: 140px 20px 80px 20px;
                    position: relative;
                }

                /* Letzte Sektion: Vermeidet Seitenumbruch danach */
                .last-section {
                    page-break-after: avoid !important;
                    padding-bottom: 80px; /* Ausreichend Platz für den Footer */
                }

                /* Für Kompatibilität: Alte Klassen mit neuen Eigenschaften */
                .page {
                    padding: 140px 20px 10px 20px;
                    position: relative;
                    /* Kein automatischer Seitenumbruch mehr */
                }

                /* Vertragsdetails-Sektion: Immer auf einer neuen Seite beginnen */
                .contract-details-section {
                    page-break-before: always;
                    padding: 140px 20px 10px 20px;
                    position: relative;
                }

                .vertragsbedingungen {
                    margin-top: 0;
                    page-break-after: avoid !important;
                }

                /* Einheitliches Styling für alle Karten (Inhaltsbereiche) */
                .card {
                    background-color: #ffffff;
                    border-radius: 4px;
                    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.12), 0 1px 2px rgba(0, 0, 0, 0.24);
                    margin: 16px 0;
                    padding: 16px;
                }
                .card h2 {
                    margin-top: 0;
                    font-size: 18px;
                    font-weight: 500;
                    border-bottom: 1px solid #e0e0e0;
                    padding-bottom: 8px;
                    margin-bottom: 16px;
                    color: #212121;
                }
                .data-grid {
                    width: 100%;
                    border-collapse: collapse;
                }
                .data-grid td {
                    padding: 8px;
                    vertical-align: top;
                    border-bottom: 1px solid #eeeeee;
                    font-size: 12px;
                }
                .data-grid td:first-child {
                    font-weight: 500;
                    width: 35%;
                    color: #424242;
                }
                p {
                    line-height: 1.6;
                    font-size: 10px;
                }

                /* Header für Seite 1 (zusätzlicher Titel und Vertragsinformationen) */
                .page1-header {
                    margin-bottom: 20px;
                    padding-left: 16px;
                }
                .contract-title {
                    font-size: 20px;
                    font-weight: 500;
                    margin: 0 0 8px 0;
                    color: #212121;
                }
                .contract-info {
                    font-size: 12px;
                    color: #757575;
                }
                .contract-info span {
                    margin-right: 20px;
                }
                /* Styling für die Links zu AGB und Datenschutz */
                .agreements-links p {
                    margin: 4px 0;
                    font-size: 10px;
                }
                .agreements-links a:hover {
                    text-decoration: underline;
                }
                /* Clearfix */
                .clearfix::after {
                    content: "";
                    display: table;
                    clear: both;
                }

            </style>
        </head>
        <body>

          <!-- Globaler, fixierter Footer (wird auf jeder Seite wiederholt) -->
          <div class="footer">
  <table class="footer-table">
      <tr>
          <td><?php echo esc_html($footer_data['row1'][0]); ?></td>
          <td><?php echo esc_html($footer_data['row1'][1]); ?></td>
          <td><?php echo esc_html($footer_data['row1'][2]); ?></td>
          <td><?php echo esc_html($footer_data['row1'][3]); ?></td>
      </tr>
      <tr>
          <td><?php echo esc_html($footer_data['row2'][0]); ?></td>
          <td><?php echo esc_html($footer_data['row2'][1]); ?></td>
          <td><?php echo esc_html($footer_data['row2'][2]); ?></td>
          <td><?php echo esc_html($footer_data['row2'][3]); ?></td>
      </tr>
  </table>
</div>
            <!-- Fester Header (auf allen Seiten) – nur Logo -->
            <div class="right">
                <?php
                // Logo als data:-URI inlinen (DomPDF läuft mit isRemoteEnabled=false).
                $divi_options = get_option('et_divi');
                $logo_data    = '';
                if (!empty($divi_options['divi_logo'])) {
                    $logo_data = ab_inline_local_image($divi_options['divi_logo']);
                }
                if (empty($logo_data) && function_exists('et_get_option')) {
                    $logo = et_get_option('divi_logo');
                    if (!empty($logo)) {
                        $logo_data = ab_inline_local_image($logo);
                    }
                }
                if (!empty($logo_data)) {
                    echo '<img src="' . esc_attr($logo_data) . '" alt="' . esc_attr(get_bloginfo('name')) . '" />';
                } else {
                    echo '<span>' . esc_html(get_bloginfo('name')) . '</span>';
                }
                ?>
            </div>

            <!-- Seite 1: Persönliche Daten mit extra Header (Trainingsvertrag, Vertrags Nr & Abgeschlossen am) -->
            <div class="page">
                <div class="page1-header">
                    <h1 class="contract-title">Trainingsvertrag (<?php echo esc_html($contract_details['trainingsumfang']); ?>)</h1>
                    <div class="contract-info">
                        <span>Vertrags Nr: <?php echo $order->get_id(); ?></span>
                        <span>Abgeschlossen am: <?php echo date('d.m.Y'); ?></span>
                        <?php if (!empty($class_name)): ?>
                        <span>Klasse: <?php echo esc_html($class_name); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card">
                    <h2>Persönliche Daten</h2>
                    <table class="data-grid">
                        <?php if (!empty($contract_data['anrede'])): ?>
                            <tr>
                                <td>Anrede:</td>
                                <td><?php echo esc_html($contract_data['anrede']); ?></td>
                            </tr>
                        <?php endif; ?>
                        <tr>
                            <td>Name:</td>
                            <td><?php echo esc_html($contract_data['vorname'] . ' ' . $contract_data['nachname']); ?></td>
                        </tr>
                        <?php if (!empty($contract_data['geburtsdatum'])): ?>
                            <tr>
                                <td>Geburtsdatum:</td>
                                <td><?php echo esc_html($contract_data['geburtsdatum']); ?></td>
                            </tr>
                        <?php endif; ?>
                        <?php if (!empty($contract_data['ahv_nummer'])): ?>
                            <tr>
                                <td>AHV-Nummer:</td>
                                <td><?php echo esc_html($contract_data['ahv_nummer']); ?></td>
                            </tr>
                        <?php endif; ?>
                        <?php if (!empty($contract_data['strasse']) || !empty($contract_data['hausnummer'])): ?>
                            <tr>
                                <td>Adresse:</td>
                                <td>
                                    <?php
                                        echo esc_html(
                                            trim($contract_data['strasse'] . ' ' . $contract_data['hausnummer'])
                                            . ', ' . $contract_data['plz'] . ' ' . $contract_data['ort']
                                        );
                                    ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                        <tr>
                            <td>Telefon:</td>
                            <td><?php echo esc_html($contract_data['telefon']); ?></td>
                        </tr>
                        <tr>
                            <td>E-Mail:</td>
                            <td><?php echo esc_html($contract_data['email']); ?></td>
                        </tr>
                        <?php if (!empty($contract_data['besonderheiten'])): ?>
                            <tr>
                                <td>Besonderheiten / Allergien:</td>
                                <td><?php echo esc_html($contract_data['besonderheiten']); ?></td>
                            </tr>
                        <?php endif; ?>
                    </table>
                </div>
                <?php if (!empty($contract_data['erziehungsberechtigter_name'])): ?>
                    <div class="card">
                        <h2>Erziehungsberechtigte</h2>
                        <table class="data-grid">
                            <tr>
                                <td>Name:</td>
                                <td><?php echo esc_html($contract_data['erziehungsberechtigter_name']); ?></td>
                            </tr>
                            <tr>
                                <td>Telefon:</td>
                                <td><?php echo esc_html($contract_data['erziehungsberechtigter_telefon']); ?></td>
                            </tr>
                            <tr>
                                <td>E-Mail:</td>
                                <td><?php echo esc_html($contract_data['erziehungsberechtigter_email']); ?></td>
                            </tr>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Seite 2: Vertragsdetails (immer auf einer neuen Seite) -->
            <div class="contract-details-section">
                <div class="card">
                    <h2>Vertragsdetails</h2>
                    <table class="data-grid">
                        <?php if (!empty($contract_details['vertrag_preis'])): ?>
                            <tr>
                                <td>Monatlicher Preis:</td>
                                <td><?php echo esc_html($contract_details['vertrag_preis']); ?> <?php echo get_woocommerce_currency_symbol(); ?></td>
                            </tr>
                        <?php endif; ?>
                        <?php if (!empty($contract_details['trainingsumfang'])): ?>
                            <tr>
                                <td>Trainingsumfang:</td>
                                <td><?php echo esc_html($contract_details['trainingsumfang']); ?></td>
                            </tr>
                        <?php endif; ?>
                        <?php if (!empty($contract_details['verlaengerung'])): ?>
                            <tr>
                                <td>Verlängerung:</td>
                                <td><?php echo esc_html($contract_details['verlaengerung']); ?></td>
                            </tr>
                        <?php endif; ?>
                        <?php if (!empty($contract_details['kuendigungsfrist'])): ?>
                            <tr>
                                <td>Kündigungsfrist:</td>
                                <td><?php echo esc_html($contract_details['kuendigungsfrist']); ?></td>
                            </tr>
                        <?php endif; ?>
                        <?php if (!empty($contract_details['probezeit'])): ?>
                            <tr>
                                <td>Probezeit:</td>
                                <td><?php echo esc_html($contract_details['probezeit']); ?></td>
                            </tr>
                        <?php endif; ?>
                    </table>
                    <?php if( !empty($accordion_basic) ): ?>
           <h3><?php echo esc_html($accordion_titles['basic']); ?></h3>
           <?php echo wpautop( $accordion_basic ); ?>
       <?php endif; ?>
                    <p>Dieser Vertrag wurde digital abgeschlossen.</p>
                </div>
            </div>

            <!-- Seite 3: Zahlungsinformationen -->
      <div class="payment-section">
          <div class="card">
              <h2>Zahlungsinformationen</h2>
              <?php
              // Hole die aktuelle Zahlungsmethode
              $payment_method = get_option('ab_payment_method', 'direct_debit');
              $payment_details = get_option('ab_payment_details', []);

              // Wenn Lastschriftverfahren gewählt wurde
              if ($payment_method === 'direct_debit'):
                  // Hole SEPA-Einleitungstext
                  $sepa_intro = get_option('ab_sepa_intro_text', '');
                  if (!empty($sepa_intro)): ?>
                      <div class="sepa-intro">
                          <?php echo wpautop($sepa_intro); ?>
                      </div>
                  <?php endif; ?>

                  <table class="data-grid">
                      <?php if (!empty($contract_data['kontoInhaber'])): ?>
                          <tr>
                              <td>Kontoinhaber:</td>
                              <td><?php echo esc_html($contract_data['kontoInhaber']); ?></td>
                          </tr>
                      <?php endif; ?>
                      <?php if (!empty($contract_data['bank_name'])): ?>
                          <tr>
                              <td>Kreditinstitut:</td>
                              <td><?php echo esc_html($contract_data['bank_name']); ?></td>
                          </tr>
                      <?php endif; ?>
                      <?php if (!empty($contract_data['iban'])): ?>
                          <tr>
                              <td>IBAN:</td>
                              <td><?php echo esc_html($contract_data['iban']); ?></td>
                          </tr>
                      <?php endif; ?>
                      <?php if (!empty($contract_data['bic'])): ?>
                          <tr>
                              <td>BIC:</td>
                              <td><?php echo esc_html($contract_data['bic']); ?></td>
                          </tr>
                      <?php endif; ?>
                  </table>

                  <?php
                  // SEPA-Akkordeon Inhalt
                  $sepa_title = get_option('ab_sepa_accordion_title', '');
                  $sepa_content = get_option('ab_sepa_accordion_content', '');
                  if (!empty($sepa_title) && !empty($sepa_content)): ?>
                      <h3><?php echo esc_html($sepa_title); ?></h3>
                      <?php echo wpautop($sepa_content); ?>
                  <?php endif;

              // Wenn Dauerauftrag gewählt wurde
              elseif ($payment_method === 'bank_transfer'): ?>
                  <table class="data-grid">
                      <?php if (!empty($payment_details['company_bank'])): ?>
                          <tr>
                              <td>Bank:</td>
                              <td><?php echo esc_html($payment_details['company_bank']); ?></td>
                          </tr>
                      <?php endif; ?>
                      <?php if (!empty($payment_details['company_iban'])): ?>
                          <tr>
                              <td>IBAN:</td>
                              <td><?php echo esc_html($payment_details['company_iban']); ?></td>
                          </tr>
                      <?php endif; ?>
                      <?php if (!empty($payment_details['company_bic'])): ?>
                          <tr>
                              <td>BIC:</td>
                              <td><?php echo esc_html($payment_details['company_bic']); ?></td>
                          </tr>
                      <?php endif; ?>
                  </table>

              <!-- Wenn Rechnung gewählt wurde -->
              <?php elseif ($payment_method === 'invoice'): ?>
                  <?php if (!empty($payment_details['invoice_text'])): ?>
                      <?php echo wpautop($payment_details['invoice_text']); ?>
                  <?php endif; ?>
                  <?php if (!empty($contract_data['invoice_address'])): ?>
                      <table class="data-grid">
                          <tr>
                              <td>Rechnungsadresse:</td>
                              <td><?php echo esc_html($contract_data['invoice_address']); ?></td>
                          </tr>
                      </table>
                  <?php endif; ?>
              <?php endif; ?>
          </div>
      </div>

            <!-- Seite 4: Vertragsbedingungen inkl. AGB, Datenschutz & Akkordeon-Inhalte -->
      <div class="content-section last-section">
          <div class="card">
              <h2>Vertragsbedingungen</h2>
              <div class="agreements-links">
                <p>
                <strong>AGB:</strong> Die AGB von <?php echo esc_html($footer_col1); ?> können unter folgendem Link eingesehen werden:
                <a href="<?php echo esc_url(get_site_url() . '/agb'); ?>" target="_blank">
                    <?php echo esc_url(get_site_url() . '/agb'); ?>
                </a>
              </p>
              <p>
                <strong>DATENSCHUTZBESTIMMUNGEN:</strong> Die Datenschutzbestimmungen von <?php echo esc_html($footer_col1); ?> können unter folgendem Link eingesehen werden:
                <a href="<?php echo esc_url(get_site_url() . '/datenschutz'); ?>" target="_blank">
                    <?php echo esc_url(get_site_url() . '/datenschutz'); ?>
                </a>
              </p>
              </div>
              <?php
                  $contract_type = get_post( $contract_id );
                  if ( $contract_type ) {
                      echo wpautop( $contract_type->post_content );
                  }
              ?>
              <?php if( !empty($accordion_training) || !empty($accordion_conditions) ): ?>
                  <?php if( !empty($accordion_training) ): ?>
                      <h3><?php echo esc_html($accordion_titles['training']); ?></h3>
                      <?php echo wpautop( $accordion_training ); ?>
                  <?php endif; ?>
                  <?php if( !empty($accordion_conditions) ): ?>
                      <h3><?php echo esc_html($accordion_titles['conditions']); ?></h3>
                      <?php echo wpautop( $accordion_conditions ); ?>
                  <?php endif; ?>
              <?php endif; ?>
          </div>
      </div>

        </body>
        </html>
        <?php
        return ob_get_clean();
    }

    /**
     * Generiert das PDF anhand des HTML-Inhalts.
     *
     * @param string $html     Das HTML, das gerendert werden soll.
     * @param int    $order_id Die Bestell-ID (für einen eindeutigen Dateinamen).
     * @return string|false    Pfad zur PDF-Datei oder false im Fehlerfall.
     */
    public static function generate( $html, $order_id ) {
        $dompdf_autoload_path = plugin_dir_path( __FILE__ ) . '../../dompdf/autoload.inc.php';
        if ( ! file_exists( $dompdf_autoload_path ) ) {
            error_log( 'DomPDF nicht gefunden.' );
            return false;
        }
        require_once $dompdf_autoload_path;

        $options = new Options();
        $options->setIsRemoteEnabled( false );
        $dompdf = new Dompdf( $options );
        $dompdf->loadHtml( $html );
        $dompdf->setPaper( 'A4', 'portrait' );
        $dompdf->render();

        $upload_dir = wp_upload_dir();
        $base_path  = $upload_dir['basedir'] . '/vertraege/';
        if ( ! file_exists( $base_path ) ) {
            wp_mkdir_p( $base_path );
            if ( ! file_exists( $base_path . '.htaccess' ) ) {
                file_put_contents( $base_path . '.htaccess', "deny from all" );
            }
        }

        $filename  = 'vertrag-' . $order_id . '-' . time() . '.pdf';
        $file_path = $base_path . $filename;
        file_put_contents( $file_path, $dompdf->output() );

        return $file_path;
    }

    /**
     * Erstellt eine einfache Vorschau des Vertrags.
     *
     * @param \WC_Order $order
     * @param array     $contract_data
     * @return string HTML-Inhalt der Vorschau
     */
    public static function preview( $order, $contract_data ) {
        ob_start();
        ?>
        <div class="pdf-preview">
            <h4>Vertrag Nr. <?php echo $order->get_id(); ?></h4>
            <p>Dies ist eine Vorschau des Vertrags. Das finale PDF wird alle Details beinhalten.</p>
            <p><em>Nach Abschluss erhalten Sie das vollständige Dokument per E-Mail.</em></p>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Ermittelt den Klassennamen aus der Bestellung
     *
     * @param \WC_Order $order
     * @return string Klassenname
     */
    private static function get_training_name_from_order($order) {
        // 1. Versuche '_event_title_clean' von der Bestellung
        $clean_event_title = $order->get_meta('_event_title_clean');
        if (!empty($clean_event_title)) {
            return $clean_event_title;
        }

        // 2. Versuche normalen Event-Titel aus Bestellposition
        foreach ($order->get_items() as $item) {
            if ($title = $item->get_meta('_event_title_clean')) {
                return $title;
            }
            // Fallback auf den regulären Event-Titel
            if ($title = $item->get_meta('_event_title')) {
                return $title;
            }
        }

        // 3. Fallback auf Produktname
        foreach ($order->get_items() as $item) {
            if ($product = $item->get_product()) {
                return $product->get_name();
            }
        }

        return '';
    }
}
