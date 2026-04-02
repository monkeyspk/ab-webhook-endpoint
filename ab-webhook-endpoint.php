<?php
/**
 * Plugin Name: AB Webhook Endpoint + Multiple Custom Order Status
 * Description: Empfängt Status-Updates vom Academy Board und aktualisiert Bestellungen in WooCommerce inkl. mehrerer Custom-Status. E-Mail-Customizer und Shortcodes inklusive.
 * Version:     1.4
 * Author:      Pierre Biege
 * Text Domain: ab-webhook-endpoint
 */

if (!defined('ABSPATH')) {
    exit;
}

// 1) Wichtige Dateien einbinden
require_once plugin_dir_path(__FILE__) . 'includes/class-ab-custom-statuses.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-ab-rest-endpoint.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-ab-bulk-actions.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-ab-email-sender.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-ab-email-customizer.php';
require_once plugin_dir_path(__FILE__) . 'includes/ab-shortcodes.php'; // Shortcodes
require_once plugin_dir_path(__FILE__) . 'includes/helper-functions.php'; // Falls du Hilfsfunktionen brauchst
// require_once plugin_dir_path(__FILE__) . 'includes/class-ab-contract-page.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-ab-contract-types.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-ab-contract-wizard.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-ab-contract-ajax-handler.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-ab-contract-overview.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-ab-payment-settings.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-ab-payment-methods.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-ab-contract-admin-panel.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-ab-email-image-processor.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-ab-contract-address-settings.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-ab-customer-overview.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-ab-gutschein-settings.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-ab-gutschein.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-ab-gutschein-email.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-ab-gutschein-balance.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-ab-gutschein-pdf.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-ab-workshop-scheduler.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-ab-contract-type-export.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-ab-admin-menu-organizer.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-ab-combined-settings.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-ab-bestandskunden-import.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-ab-bestandskunde-reminder.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-ab-schueler-uebersicht.php';
require_once plugin_dir_path(__FILE__) . 'includes/github-updater.php';


// TWINT-Plugin lädt fehlerhaftes JS auf Admin-Order-Seite (chosen is not a function)
// Das blockiert das Speichern von Billing-Feldern inkl. E-Mail-Änderung.
add_action('admin_enqueue_scripts', function() {
    $screen = get_current_screen();
    if ($screen && $screen->id === 'shop_order') {
        // Alle TWINT-Scripts auf der Order-Edit-Seite entfernen
        global $wp_scripts;
        if (isset($wp_scripts->registered)) {
            foreach ($wp_scripts->registered as $handle => $script) {
                if (strpos($handle, 'mame_tw') !== false || (isset($script->src) && strpos($script->src, 'mame-twint') !== false)) {
                    wp_dequeue_script($handle);
                }
            }
        }
    }
}, 999);

add_action('wp_enqueue_scripts', function() {
    if (has_shortcode(get_post()->post_content, 'ab_contract_wizard')) {
        wp_enqueue_style(
            'ab-contract-wizard',
            plugins_url('assets/css/contract-wizard.css', __FILE__),
            [],
            '1.0.0'
        );
    }
});


// 2) Haupt-Initialisierung
function ab_we_init_plugin() {
    // Custom Statuses registrieren
    AB_Custom_Statuses::register_statuses();
    
    // Status-Tracking initialisieren
    AB_Custom_Statuses::init_status_tracking();

    // E-Mail-Customizer initialisieren
    AB_Email_Customizer::get_instance();

    // REST-Endpoint registrieren
    AB_Rest_Endpoint::init();

    // Bulk-Aktionen aktivieren
    AB_Bulk_Actions::init();

    //Vertragsabschluss-Seite/Shortcode initialisieren
  // AB_Contract_Page::init();

  // NEU: CPT & Wizard initialisieren
    AB_Contract_Types::init();
    AB_Contract_Wizard::init();
    AB_Payment_Settings::init();

// Customer Overview
    AB_Customer_Overview::init();

    // Gutschein-System
    AB_Gutschein_Settings::init();
    AB_Gutschein::init();
    AB_Gutschein_Balance::init();

    // Workshop Scheduler
    AB_Workshop_Scheduler::init();

    // Vertragstypen Export-Seite
    AB_Contract_Type_Export::init();

    // Kombinierte Einstellungen-Seite
    AB_Combined_Settings::init();

    // Bestandskunden-Import
    AB_Bestandskunden_Import::init();

    // Bestandskunde-Erinnerung (Cron)
    AB_Bestandskunde_Reminder::init();

    // Schüler-Übersicht
    AB_Schueler_Uebersicht::init();

    // Admin-Menü unter ParkourONE organisieren
    AB_Admin_Menu_Organizer::init();
}
add_action('plugins_loaded', 'ab_we_init_plugin', 11);

// 3) Aktivierungs-/Deaktivierungsroutinen (optional)
register_activation_hook(__FILE__, 'ab_we_on_activate');
function ab_we_on_activate() {
    AB_Custom_Statuses::register_statuses();
    flush_rewrite_rules();
}

register_deactivation_hook(__FILE__, 'ab_we_on_deactivate');
function ab_we_on_deactivate() {
    flush_rewrite_rules();
    AB_Bestandskunde_Reminder::deactivate();
}
