<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Dompdf\Dompdf;
use Dompdf\Options;

class AB_Gutschein_PDF {

    /**
     * Baut das HTML fuer einen A4-Querformat Gutschein.
     * Einfaches Flow-Layout, Navy-Card wie in der E-Mail.
     */
    public static function build_html( $coupon_code, $amount, $expiry_date, $message = '', $sender_name = '' ) {

        // Logo (gleicher Pattern wie AB_Contract_PDF)
        $logo_html = '';
        $divi_options = get_option( 'et_divi' );
        if ( ! empty( $divi_options['divi_logo'] ) ) {
            $logo_html = '<img src="' . esc_url( $divi_options['divi_logo'] ) . '" style="max-height: 40px;" />';
        } elseif ( function_exists( 'et_get_option' ) ) {
            $logo = et_get_option( 'divi_logo' );
            if ( ! empty( $logo ) ) {
                $logo_html = '<img src="' . esc_url( $logo ) . '" style="max-height: 40px;" />';
            }
        }
        if ( empty( $logo_html ) ) {
            $custom_logo_id = get_theme_mod( 'custom_logo' );
            if ( $custom_logo_id ) {
                $logo_url = wp_get_attachment_image_url( $custom_logo_id, 'medium' );
                if ( $logo_url ) {
                    $logo_html = '<img src="' . esc_url( $logo_url ) . '" style="max-height: 40px;" />';
                }
            }
        }
        if ( empty( $logo_html ) ) {
            $logo_html = '<span style="font-size:22px;font-weight:700;color:#1d1d1f;">' . esc_html( get_bloginfo( 'name' ) ) . '</span>';
        }

        // Hero-Bild als data:URI (kugelsicher fuer DOMPDF)
        $hero_src = '';
        $hero_path = plugin_dir_path( __FILE__ ) . '../assets/images/gutschein-hero.jpg';
        if ( file_exists( $hero_path ) ) {
            $hero_data = base64_encode( file_get_contents( $hero_path ) );
            $hero_src = 'data:image/jpeg;base64,' . $hero_data;
        }

        // Footer-Daten
        $footer          = get_option( 'parkourone_footer', [] );
        $company_name    = $footer['company_name'] ?? get_bloginfo( 'name' );
        $company_address = $footer['company_address'] ?? '';
        $phone           = $footer['phone'] ?? '';
        $email_addr      = $footer['email'] ?? get_option( 'admin_email' );

        $footer_parts = array_filter( [ $company_name, $company_address, $phone, $email_addr ] );
        $footer_text  = implode( ' &middot; ', array_map( 'esc_html', $footer_parts ) );

        // Betrag formatieren
        $formatted_amount = number_format( $amount, 2, ',', '.' );

        // Optionaler Nachrichten-Block (max 200 Zeichen)
        $message_html = '';
        if ( ! empty( $message ) ) {
            $display_msg = mb_strlen( $message ) > 200 ? mb_substr( $message, 0, 197 ) . '...' : $message;
            $message_html = '<div style="margin: 3mm 50mm 5mm; text-align: center;">'
                . '<p style="font-size: 12px; font-style: italic; color: #86868b; line-height: 1.5; margin: 0;">'
                . '&bdquo;' . nl2br( esc_html( $display_msg ) ) . '&ldquo;</p>';
            if ( ! empty( $sender_name ) ) {
                $message_html .= '<p style="font-size: 10px; color: #86868b; margin: 1mm 0 0;">&mdash; ' . esc_html( $sender_name ) . '</p>';
            }
            $message_html .= '</div>';
        }

        $site_url = preg_replace( '#^https?://#', '', home_url() );

        ob_start();
        ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        @page { size: A4 landscape; margin: 8mm 12mm; }
        body {
            font-family: Helvetica, Arial, sans-serif;
            margin: 0;
            padding: 0;
            color: #1d1d1f;
        }
    </style>
</head>
<body>

    <!-- LOGO -->
    <div style="padding: 0 0 4mm; text-align: left;">
        <?php echo $logo_html; ?>
    </div>

    <!-- SCHWARZE LINIE -->
    <div style="border-top: 2px solid #1d1d1f; margin-bottom: 4mm;"></div>

    <!-- HERO-BILD -->
    <?php if ( $hero_src ) : ?>
    <div style="text-align: center; margin-bottom: 5mm;">
        <img src="<?php echo $hero_src; ?>" style="width: 100%;" />
    </div>
    <?php endif; ?>

    <!-- NAVY GUTSCHEIN-KARTE (wie in der E-Mail) -->
    <div style="background-color: #1e3d59; padding: 7mm 15mm; text-align: center; margin: 0 25mm;">
        <p style="color: #9aaaba; font-size: 14px; margin: 0 0 2mm; text-transform: uppercase; letter-spacing: 3px;">Gutschein</p>
        <p style="color: #ffffff; font-size: 48px; font-weight: 700; margin: 0 0 4mm;"><?php echo esc_html( $formatted_amount ); ?> &euro;</p>
        <div style="background-color: #405a72; display: inline-block; padding: 3mm 8mm;">
            <span style="color: #ffffff; font-size: 24px; font-weight: 600; letter-spacing: 3px; font-family: 'Courier New', Courier, monospace;"><?php echo esc_html( $coupon_code ); ?></span>
        </div>
        <p style="color: #9aaaba; font-size: 13px; margin: 4mm 0 0;">G&uuml;ltig bis: <?php echo esc_html( $expiry_date ); ?></p>
    </div>

    <!-- OPTIONALE NACHRICHT -->
    <?php echo $message_html; ?>

    <!-- EINLOESE-INFO -->
    <div style="text-align: center; margin: 3mm 0;">
        <p style="font-size: 11px; color: #1d1d1f; margin: 0;">
            Einl&ouml;sbar unter <strong><?php echo esc_html( $site_url ); ?></strong> im Warenkorb.
            Teilweise einl&ouml;sbar. Keine Barauszahlung.
        </p>
    </div>

    <!-- FOOTER -->
    <div style="border-top: 1px solid #e0e0e0; margin-top: 3mm; padding-top: 3mm; text-align: center;">
        <p style="font-size: 9px; color: #86868b; margin: 0;"><?php echo $footer_text; ?></p>
    </div>

</body>
</html>
        <?php
        return ob_get_clean();
    }

    /**
     * Generiert das PDF aus HTML, speichert in wp_upload_dir()/gutscheine/
     */
    public static function generate( $html, $order_id ) {
        $dompdf_autoload_path = plugin_dir_path( __FILE__ ) . '../dompdf/autoload.inc.php';
        if ( ! file_exists( $dompdf_autoload_path ) ) {
            error_log( '[AB Gutschein PDF] DomPDF nicht gefunden: ' . $dompdf_autoload_path );
            return false;
        }
        require_once $dompdf_autoload_path;

        $options = new Options();
        $options->setIsRemoteEnabled( true );
        $dompdf = new Dompdf( $options );
        $dompdf->loadHtml( $html );
        $dompdf->setPaper( 'A4', 'landscape' );
        $dompdf->render();

        $upload_dir = wp_upload_dir();
        $base_path  = $upload_dir['basedir'] . '/gutscheine/';
        if ( ! file_exists( $base_path ) ) {
            wp_mkdir_p( $base_path );
            if ( ! file_exists( $base_path . '.htaccess' ) ) {
                file_put_contents( $base_path . '.htaccess', "deny from all" );
            }
        }

        $filename  = 'gutschein-' . $order_id . '-' . time() . '.pdf';
        $file_path = $base_path . $filename;
        file_put_contents( $file_path, $dompdf->output() );

        if ( file_exists( $file_path ) ) {
            error_log( '[AB Gutschein PDF] PDF gespeichert: ' . $file_path );
            return $file_path;
        }

        error_log( '[AB Gutschein PDF] Fehler beim Speichern des PDFs.' );
        return false;
    }
}
