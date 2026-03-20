<?php
if (!defined('ABSPATH')) {
    exit;
}

class AB_Email_Customizer {
    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'add_menu_item']);
        add_action('admin_init', [$this, 'register_settings']);

        // TinyMCE: Dunklen Hintergrund fuer Gutschein-Karte im Editor anzeigen
        add_filter('tiny_mce_before_init', [$this, 'add_editor_styles']);
    }

    /**
     * Fuegt Custom-CSS in den TinyMCE-Editor ein, damit die Gutschein-Karte
     * (weisse Schrift auf dunklem Hintergrund) auch im Editor sichtbar ist.
     */
    public function add_editor_styles($mce_init) {
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'parkourone_page_ab-email-customizer') {
            return $mce_init;
        }

        $styles = 'div[style*="background-color: #1e3d59"] { background-color: #1e3d59 !important; } '
            . 'div[style*="background: rgba(255,255,255,0.15)"] { background: rgba(255,255,255,0.15) !important; }';

        if (isset($mce_init['content_style'])) {
            $mce_init['content_style'] .= ' ' . $styles;
        } else {
            $mce_init['content_style'] = $styles;
        }

        return $mce_init;
    }

    public function add_menu_item() {
        add_submenu_page(
            'parkourone',
            'E-Mail Customizer',
            'E-Mail Customizer',
            'manage_woocommerce',
            'ab-email-customizer',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings() {
        register_setting('ab_email_customizer', 'ab_email_settings');

        $custom_statuses = AB_Custom_Statuses::get_custom_statuses();
        // Gutschein hat eigene E-Mail-Sektionen weiter unten
        unset($custom_statuses['wc-gutschein']);
        foreach ($custom_statuses as $slug => $label) {
            $status_key = str_replace('wc-', '', $slug);

            add_settings_section(
                'ab_email_section_' . $status_key,
                $label,
                function() { echo '<hr>'; },
                'ab_email_customizer'
            );

            // CHECKBOX FIELD
            add_settings_field(
                'send_email_' . $status_key,
                'E-Mail senden aktivieren',
                [$this, 'render_checkbox_field'],
                'ab_email_customizer',
                'ab_email_section_' . $status_key,
                [
                    'key' => 'send_email_' . $status_key,
                ]
            );

            // subject-Feld
            add_settings_field(
                'subject_' . $status_key,
                'E-Mail Betreff',
                [$this, 'render_text_field'],
                'ab_email_customizer',
                'ab_email_section_' . $status_key,
                [
                    'key' => 'subject_' . $status_key,
                    'placeholder' => 'Deine Bestellung ist jetzt im Status "{status}"'
                ]
            );

            // header-Feld
            add_settings_field(
                'header_' . $status_key,
                'Überschrift',
                [$this, 'render_text_field'],
                'ab_email_customizer',
                'ab_email_section_' . $status_key,
                [
                    'key' => 'header_' . $status_key,
                    'placeholder' => $label
                ]
            );

            // content-Feld (WYSIWYG)
            add_settings_field(
                'content_' . $status_key,
                'E-Mail Inhalt',
                [$this, 'render_wysiwyg_field'],
                'ab_email_customizer',
                'ab_email_section_' . $status_key,
                [
                    'key'         => 'content_' . $status_key,
                    'placeholder' => "Hallo {first_name},\n\ndeine Bestellung (#{order_number}) ist jetzt im Status: {status}.\n\n[ab_event_date]\n[ab_participants]"
                ]
            );

            // Zusätzliches Content-Feld für "Vertrag verschickt" wenn von Warteliste kommend
            if ($status_key === 'vertragverschickt') {
                add_settings_field(
                    'content_' . $status_key . '_from_warteliste',
                    'E-Mail Inhalt (von Warteliste)',
                    [$this, 'render_wysiwyg_field'],
                    'ab_email_customizer',
                    'ab_email_section_' . $status_key,
                    [
                        'key'         => 'content_' . $status_key . '_from_warteliste',
                        'placeholder' => "Hallo {first_name},\n\ngute Neuigkeiten! Ein Platz ist frei geworden und du kannst nun am Training teilnehmen.\n\nAnbei findest du deinen Vertrag..."
                    ]
                );
            }


            // Am Ende der foreach-Schleife, direkt vor dem schließenden }:
if ($status_key === 'schuelerin') {
    add_settings_field(
        'admin_notification_' . $status_key,
        'Admin-Benachrichtigung bei neuem/r Schüler/in',
        [$this, 'render_text_field'],
        'ab_email_customizer',
        'ab_email_section_' . $status_key,
        [
            'key' => 'admin_notification_' . $status_key,
            'placeholder' => 'admin@domain.tld'
        ]
    );
}

if ($status_key === 'gekuendigt') {
    add_settings_field(
        'admin_notification_' . $status_key,
        'Admin-Benachrichtigung bei Kündigung',
        [$this, 'render_text_field'],
        'ab_email_customizer',
        'ab_email_section_' . $status_key,
        [
            'key' => 'admin_notification_' . $status_key,
            'placeholder' => 'admin@domain.tld'
        ]
    );
}

if ($status_key === 'kdginitiiert') {
    add_settings_field(
        'admin_notification_' . $status_key,
        'Admin-Benachrichtigung bei Kündigung initiiert',
        [$this, 'render_text_field'],
        'ab_email_customizer',
        'ab_email_section_' . $status_key,
        [
            'key' => 'admin_notification_' . $status_key,
            'placeholder' => 'admin@domain.tld'
        ]
    );
}

if ($status_key === 'bestandkundeakz') {
    add_settings_field(
        'admin_notification_' . $status_key,
        'Admin-Benachrichtigung bei Bestandskunde akzeptiert',
        [$this, 'render_text_field'],
        'ab_email_customizer',
        'ab_email_section_' . $status_key,
        [
            'key' => 'admin_notification_' . $status_key,
            'placeholder' => 'admin@domain.tld'
        ]
    );
}




        }

        // =============================================
        // Bestandskunde Vertrag Erinnerung
        // =============================================

        add_settings_section(
            'ab_email_section_bestandskunde_reminder',
            'Bestandskunde Vertrag — Erinnerung',
            function() { echo '<hr><p>Automatische Erinnerungsmail wenn ein Bestandskunde den Vertrag nach X Tagen noch nicht abgeschlossen hat.</p>'; },
            'ab_email_customizer'
        );

        add_settings_field(
            'send_email_bestandskunde_reminder',
            'Erinnerung aktivieren',
            [$this, 'render_checkbox_field'],
            'ab_email_customizer',
            'ab_email_section_bestandskunde_reminder',
            ['key' => 'send_email_bestandskunde_reminder']
        );

        add_settings_field(
            'bestandskunde_reminder_days',
            'Erinnerung nach X Tagen',
            [$this, 'render_number_field'],
            'ab_email_customizer',
            'ab_email_section_bestandskunde_reminder',
            ['key' => 'bestandskunde_reminder_days', 'min' => 1, 'max' => 90, 'default' => 7]
        );

        add_settings_field(
            'subject_bestandskunde_reminder',
            'E-Mail Betreff',
            [$this, 'render_text_field'],
            'ab_email_customizer',
            'ab_email_section_bestandskunde_reminder',
            ['key' => 'subject_bestandskunde_reminder', 'placeholder' => 'Erinnerung: Bitte Vertrag abschließen']
        );

        add_settings_field(
            'header_bestandskunde_reminder',
            'Überschrift',
            [$this, 'render_text_field'],
            'ab_email_customizer',
            'ab_email_section_bestandskunde_reminder',
            ['key' => 'header_bestandskunde_reminder', 'placeholder' => 'Vertrag noch offen']
        );

        add_settings_field(
            'content_bestandskunde_reminder',
            'E-Mail Inhalt',
            [$this, 'render_wysiwyg_field'],
            'ab_email_customizer',
            'ab_email_section_bestandskunde_reminder',
            [
                'key' => 'content_bestandskunde_reminder',
                'placeholder' => "Hallo {first_name},\n\nwir haben dir vor einigen Tagen einen Vertrag zugeschickt, den du noch nicht abgeschlossen hast.\n\nBitte nutze den Link in unserer vorherigen E-Mail um deinen Vertrag auszufüllen.\n\nBei Fragen melde dich gerne bei uns."
            ]
        );

        // =============================================
        // Bestandskunde Vertrag 2. Erinnerung
        // =============================================

        add_settings_section(
            'ab_email_section_bestandskunde_reminder_2',
            'Bestandskunde Vertrag — 2. Erinnerung',
            function() { echo '<hr><p>Zweite Erinnerungsmail, falls der Kunde nach der ersten Erinnerung den Vertrag immer noch nicht abgeschlossen hat.</p>'; },
            'ab_email_customizer'
        );

        add_settings_field(
            'send_email_bestandskunde_reminder_2',
            '2. Erinnerung aktivieren',
            [$this, 'render_checkbox_field'],
            'ab_email_customizer',
            'ab_email_section_bestandskunde_reminder_2',
            ['key' => 'send_email_bestandskunde_reminder_2']
        );

        add_settings_field(
            'bestandskunde_reminder_2_days',
            '2. Erinnerung nach X Tagen',
            [$this, 'render_number_field'],
            'ab_email_customizer',
            'ab_email_section_bestandskunde_reminder_2',
            ['key' => 'bestandskunde_reminder_2_days', 'min' => 1, 'max' => 90, 'default' => 14]
        );

        add_settings_field(
            'subject_bestandskunde_reminder_2',
            'E-Mail Betreff',
            [$this, 'render_text_field'],
            'ab_email_customizer',
            'ab_email_section_bestandskunde_reminder_2',
            ['key' => 'subject_bestandskunde_reminder_2', 'placeholder' => 'Letzte Erinnerung: Vertrag noch offen']
        );

        add_settings_field(
            'header_bestandskunde_reminder_2',
            'Überschrift',
            [$this, 'render_text_field'],
            'ab_email_customizer',
            'ab_email_section_bestandskunde_reminder_2',
            ['key' => 'header_bestandskunde_reminder_2', 'placeholder' => 'Vertrag noch immer offen']
        );

        add_settings_field(
            'content_bestandskunde_reminder_2',
            'E-Mail Inhalt',
            [$this, 'render_wysiwyg_field'],
            'ab_email_customizer',
            'ab_email_section_bestandskunde_reminder_2',
            [
                'key' => 'content_bestandskunde_reminder_2',
                'placeholder' => "Hallo {first_name},\n\nwir haben dir bereits mehrfach einen Vertrag zugeschickt. Bitte schliesse diesen zeitnah ab, damit du weiterhin am Training teilnehmen kannst.\n\nBei Fragen melde dich gerne bei uns."
            ]
        );

        // =============================================
        // Workshop E-Mail Sektionen
        // =============================================

        // Workshop Erinnerung
        add_settings_section(
            'ab_email_section_workshop_reminder',
            'Workshop Erinnerung',
            function() { echo '<hr>'; },
            'ab_email_customizer'
        );

        add_settings_field(
            'send_email_workshop_reminder',
            'E-Mail senden aktivieren',
            [$this, 'render_checkbox_field'],
            'ab_email_customizer',
            'ab_email_section_workshop_reminder',
            ['key' => 'send_email_workshop_reminder']
        );

        add_settings_field(
            'workshop_reminder_days',
            'Tage vor Workshop',
            [$this, 'render_number_field'],
            'ab_email_customizer',
            'ab_email_section_workshop_reminder',
            [
                'key' => 'workshop_reminder_days',
                'default' => 3,
                'min' => 1,
                'max' => 30,
                'description' => 'Wie viele Tage vor dem ersten Workshop-Termin soll die Erinnerung gesendet werden?'
            ]
        );

        add_settings_field(
            'subject_workshop_reminder',
            'E-Mail Betreff',
            [$this, 'render_text_field'],
            'ab_email_customizer',
            'ab_email_section_workshop_reminder',
            [
                'key' => 'subject_workshop_reminder',
                'placeholder' => 'Erinnerung an deinen Workshop'
            ]
        );

        add_settings_field(
            'header_workshop_reminder',
            'Überschrift',
            [$this, 'render_text_field'],
            'ab_email_customizer',
            'ab_email_section_workshop_reminder',
            [
                'key' => 'header_workshop_reminder',
                'placeholder' => 'Workshop Erinnerung'
            ]
        );

        add_settings_field(
            'content_workshop_reminder',
            'E-Mail Inhalt',
            [$this, 'render_wysiwyg_field'],
            'ab_email_customizer',
            'ab_email_section_workshop_reminder',
            [
                'key' => 'content_workshop_reminder',
                'placeholder' => "Hallo {first_name},\n\ndein Workshop steht bald an!\n\n[ab_workshop_all_dates]"
            ]
        );

        // Workshop besucht
        add_settings_section(
            'ab_email_section_wsbesucht',
            'Workshop besucht',
            function() { echo '<hr>'; },
            'ab_email_customizer'
        );

        add_settings_field('send_email_wsbesucht', 'E-Mail senden aktivieren', [$this, 'render_checkbox_field'], 'ab_email_customizer', 'ab_email_section_wsbesucht', ['key' => 'send_email_wsbesucht']);
        add_settings_field('subject_wsbesucht', 'E-Mail Betreff', [$this, 'render_text_field'], 'ab_email_customizer', 'ab_email_section_wsbesucht', ['key' => 'subject_wsbesucht', 'placeholder' => 'Danke für deine Teilnahme am Workshop']);
        add_settings_field('header_wsbesucht', 'Überschrift', [$this, 'render_text_field'], 'ab_email_customizer', 'ab_email_section_wsbesucht', ['key' => 'header_wsbesucht', 'placeholder' => 'Workshop besucht']);
        add_settings_field('content_wsbesucht', 'E-Mail Inhalt', [$this, 'render_wysiwyg_field'], 'ab_email_customizer', 'ab_email_section_wsbesucht', ['key' => 'content_wsbesucht', 'placeholder' => "Hallo {first_name},\n\nvielen Dank für deine Teilnahme am Workshop!"]);

        // Workshop nicht besucht
        add_settings_section(
            'ab_email_section_wsnbesucht',
            'Workshop nicht besucht',
            function() { echo '<hr>'; },
            'ab_email_customizer'
        );

        add_settings_field('send_email_wsnbesucht', 'E-Mail senden aktivieren', [$this, 'render_checkbox_field'], 'ab_email_customizer', 'ab_email_section_wsnbesucht', ['key' => 'send_email_wsnbesucht']);
        add_settings_field('subject_wsnbesucht', 'E-Mail Betreff', [$this, 'render_text_field'], 'ab_email_customizer', 'ab_email_section_wsnbesucht', ['key' => 'subject_wsnbesucht', 'placeholder' => 'Schade, dass du nicht dabei warst']);
        add_settings_field('header_wsnbesucht', 'Überschrift', [$this, 'render_text_field'], 'ab_email_customizer', 'ab_email_section_wsnbesucht', ['key' => 'header_wsnbesucht', 'placeholder' => 'Workshop nicht besucht']);
        add_settings_field('content_wsnbesucht', 'E-Mail Inhalt', [$this, 'render_wysiwyg_field'], 'ab_email_customizer', 'ab_email_section_wsnbesucht', ['key' => 'content_wsnbesucht', 'placeholder' => "Hallo {first_name},\n\nschade, dass du nicht am Workshop teilnehmen konntest."]);

        // Kurs Buchungsbestätigung
        add_settings_section(
            'ab_email_section_kurs',
            'Kurs Buchungsbestätigung',
            function() { echo '<hr>'; },
            'ab_email_customizer'
        );

        add_settings_field('send_email_kurs', 'E-Mail senden aktivieren', [$this, 'render_checkbox_field'], 'ab_email_customizer', 'ab_email_section_kurs', ['key' => 'send_email_kurs']);
        add_settings_field('subject_kurs', 'E-Mail Betreff', [$this, 'render_text_field'], 'ab_email_customizer', 'ab_email_section_kurs', ['key' => 'subject_kurs', 'placeholder' => 'Deine Kurs-Buchung']);
        add_settings_field('header_kurs', 'Überschrift', [$this, 'render_text_field'], 'ab_email_customizer', 'ab_email_section_kurs', ['key' => 'header_kurs', 'placeholder' => 'Kurs']);
        add_settings_field('content_kurs', 'E-Mail Inhalt', [$this, 'render_wysiwyg_field'], 'ab_email_customizer', 'ab_email_section_kurs', ['key' => 'content_kurs', 'placeholder' => "Hallo {first_name},\n\ndanke für deine Kurs-Buchung!\n\n[ab_workshop_all_dates]"]);

        // Kurs Erinnerung
        add_settings_section(
            'ab_email_section_kurs_reminder',
            'Kurs Erinnerung',
            function() { echo '<hr>'; },
            'ab_email_customizer'
        );

        add_settings_field('send_email_kurs_reminder', 'E-Mail senden aktivieren', [$this, 'render_checkbox_field'], 'ab_email_customizer', 'ab_email_section_kurs_reminder', ['key' => 'send_email_kurs_reminder']);
        add_settings_field('kurs_reminder_days', 'Tage vor Kurs', [$this, 'render_number_field'], 'ab_email_customizer', 'ab_email_section_kurs_reminder', ['key' => 'kurs_reminder_days', 'default' => 3, 'min' => 1, 'max' => 30, 'description' => 'Wie viele Tage vor dem ersten Kurs-Termin soll die Erinnerung gesendet werden?']);
        add_settings_field('subject_kurs_reminder', 'E-Mail Betreff', [$this, 'render_text_field'], 'ab_email_customizer', 'ab_email_section_kurs_reminder', ['key' => 'subject_kurs_reminder', 'placeholder' => 'Erinnerung an deinen Kurs']);
        add_settings_field('header_kurs_reminder', 'Überschrift', [$this, 'render_text_field'], 'ab_email_customizer', 'ab_email_section_kurs_reminder', ['key' => 'header_kurs_reminder', 'placeholder' => 'Kurs Erinnerung']);
        add_settings_field('content_kurs_reminder', 'E-Mail Inhalt', [$this, 'render_wysiwyg_field'], 'ab_email_customizer', 'ab_email_section_kurs_reminder', ['key' => 'content_kurs_reminder', 'placeholder' => "Hallo {first_name},\n\ndein Kurs steht bald an!\n\n[ab_workshop_all_dates]"]);

        // Kurs besucht
        add_settings_section(
            'ab_email_section_kursbesucht',
            'Kurs besucht',
            function() { echo '<hr>'; },
            'ab_email_customizer'
        );

        add_settings_field('send_email_kursbesucht', 'E-Mail senden aktivieren', [$this, 'render_checkbox_field'], 'ab_email_customizer', 'ab_email_section_kursbesucht', ['key' => 'send_email_kursbesucht']);
        add_settings_field('subject_kursbesucht', 'E-Mail Betreff', [$this, 'render_text_field'], 'ab_email_customizer', 'ab_email_section_kursbesucht', ['key' => 'subject_kursbesucht', 'placeholder' => 'Danke für deine Teilnahme am Kurs']);
        add_settings_field('header_kursbesucht', 'Überschrift', [$this, 'render_text_field'], 'ab_email_customizer', 'ab_email_section_kursbesucht', ['key' => 'header_kursbesucht', 'placeholder' => 'Kurs besucht']);
        add_settings_field('content_kursbesucht', 'E-Mail Inhalt', [$this, 'render_wysiwyg_field'], 'ab_email_customizer', 'ab_email_section_kursbesucht', ['key' => 'content_kursbesucht', 'placeholder' => "Hallo {first_name},\n\nvielen Dank für deine Teilnahme am Kurs!"]);

        // Kurs nicht besucht
        add_settings_section(
            'ab_email_section_kursnbesucht',
            'Kurs nicht besucht',
            function() { echo '<hr>'; },
            'ab_email_customizer'
        );

        add_settings_field('send_email_kursnbesucht', 'E-Mail senden aktivieren', [$this, 'render_checkbox_field'], 'ab_email_customizer', 'ab_email_section_kursnbesucht', ['key' => 'send_email_kursnbesucht']);
        add_settings_field('subject_kursnbesucht', 'E-Mail Betreff', [$this, 'render_text_field'], 'ab_email_customizer', 'ab_email_section_kursnbesucht', ['key' => 'subject_kursnbesucht', 'placeholder' => 'Schade, dass du nicht dabei warst']);
        add_settings_field('header_kursnbesucht', 'Überschrift', [$this, 'render_text_field'], 'ab_email_customizer', 'ab_email_section_kursnbesucht', ['key' => 'header_kursnbesucht', 'placeholder' => 'Kurs nicht besucht']);
        add_settings_field('content_kursnbesucht', 'E-Mail Inhalt', [$this, 'render_wysiwyg_field'], 'ab_email_customizer', 'ab_email_section_kursnbesucht', ['key' => 'content_kursnbesucht', 'placeholder' => "Hallo {first_name},\n\nschade, dass du nicht am Kurs teilnehmen konntest."]);

        // =============================================
        // Experience E-Mail Sektionen (Onboarding-Sequenz)
        // =============================================

        // Experience: Willkommen (1 Woche nach Einstieg)
        add_settings_section(
            'ab_email_section_experience_welcome',
            'Experience: Willkommen (1 Woche nach Einstieg)',
            function() { echo '<hr>'; },
            'ab_email_customizer'
        );

        add_settings_field('send_email_experience_welcome', 'E-Mail senden aktivieren', [$this, 'render_checkbox_field'], 'ab_email_customizer', 'ab_email_section_experience_welcome', ['key' => 'send_email_experience_welcome']);
        add_settings_field('subject_experience_welcome', 'E-Mail Betreff', [$this, 'render_text_field'], 'ab_email_customizer', 'ab_email_section_experience_welcome', ['key' => 'subject_experience_welcome', 'placeholder' => 'Willkommen bei Parkour ONE']);
        add_settings_field('header_experience_welcome', 'Überschrift', [$this, 'render_text_field'], 'ab_email_customizer', 'ab_email_section_experience_welcome', ['key' => 'header_experience_welcome', 'placeholder' => 'Willkommen']);
        add_settings_field('content_experience_welcome', 'E-Mail Inhalt', [$this, 'render_wysiwyg_field'], 'ab_email_customizer', 'ab_email_section_experience_welcome', ['key' => 'content_experience_welcome', 'placeholder' => "Hallo {first_name},\n\nwillkommen bei Parkour ONE!"]);

        // Experience: Ein Monat bei ONE (1 Monat nach Einstieg)
        add_settings_section(
            'ab_email_section_experience_one_month',
            'Experience: Ein Monat bei ONE (1 Monat nach Einstieg)',
            function() { echo '<hr>'; },
            'ab_email_customizer'
        );

        add_settings_field('send_email_experience_one_month', 'E-Mail senden aktivieren', [$this, 'render_checkbox_field'], 'ab_email_customizer', 'ab_email_section_experience_one_month', ['key' => 'send_email_experience_one_month']);
        add_settings_field('subject_experience_one_month', 'E-Mail Betreff', [$this, 'render_text_field'], 'ab_email_customizer', 'ab_email_section_experience_one_month', ['key' => 'subject_experience_one_month', 'placeholder' => 'Ein Monat bei ONE']);
        add_settings_field('header_experience_one_month', 'Überschrift', [$this, 'render_text_field'], 'ab_email_customizer', 'ab_email_section_experience_one_month', ['key' => 'header_experience_one_month', 'placeholder' => 'Ein Monat bei ONE']);
        add_settings_field('content_experience_one_month', 'E-Mail Inhalt', [$this, 'render_wysiwyg_field'], 'ab_email_customizer', 'ab_email_section_experience_one_month', ['key' => 'content_experience_one_month', 'placeholder' => "Hallo {first_name},\n\ndu bist jetzt seit einem Monat bei Parkour ONE!"]);

        // Experience: 2 Monate Parkour (2 Monate nach Einstieg)
        add_settings_section(
            'ab_email_section_experience_two_months',
            'Experience: 2 Monate Parkour (2 Monate nach Einstieg)',
            function() { echo '<hr>'; },
            'ab_email_customizer'
        );

        add_settings_field('send_email_experience_two_months', 'E-Mail senden aktivieren', [$this, 'render_checkbox_field'], 'ab_email_customizer', 'ab_email_section_experience_two_months', ['key' => 'send_email_experience_two_months']);
        add_settings_field('subject_experience_two_months', 'E-Mail Betreff', [$this, 'render_text_field'], 'ab_email_customizer', 'ab_email_section_experience_two_months', ['key' => 'subject_experience_two_months', 'placeholder' => '2 Monate Parkour liegen hinter dir']);
        add_settings_field('header_experience_two_months', 'Überschrift', [$this, 'render_text_field'], 'ab_email_customizer', 'ab_email_section_experience_two_months', ['key' => 'header_experience_two_months', 'placeholder' => '2 Monate Parkour']);
        add_settings_field('content_experience_two_months', 'E-Mail Inhalt', [$this, 'render_wysiwyg_field'], 'ab_email_customizer', 'ab_email_section_experience_two_months', ['key' => 'content_experience_two_months', 'placeholder' => "Hallo {first_name},\n\n2 Monate Parkour liegen hinter dir!"]);

        // =============================================
        // Gutschein E-Mail Sektionen
        // =============================================

        // Gutschein-Mail an Empfaenger
        add_settings_section(
            'ab_email_section_gutschein',
            'Gutschein (an Empfänger)',
            function() { echo '<hr>'; },
            'ab_email_customizer'
        );

        add_settings_field(
            'send_email_gutschein',
            'E-Mail senden aktivieren',
            [$this, 'render_checkbox_field'],
            'ab_email_customizer',
            'ab_email_section_gutschein',
            ['key' => 'send_email_gutschein']
        );

        add_settings_field(
            'subject_gutschein',
            'E-Mail Betreff',
            [$this, 'render_text_field'],
            'ab_email_customizer',
            'ab_email_section_gutschein',
            [
                'key' => 'subject_gutschein',
                'placeholder' => 'Dein Parkour ONE Gutschein',
                'default' => 'Dein Parkour ONE Gutschein'
            ]
        );

        add_settings_field(
            'content_gutschein',
            'E-Mail Inhalt',
            [$this, 'render_wysiwyg_field'],
            'ab_email_customizer',
            'ab_email_section_gutschein',
            [
                'key' => 'content_gutschein',
                'default' => AB_Gutschein_Email::get_default_content_gutschein(),
                'placeholder' => ''
            ]
        );

        // Gutschein Käufer-Bestätigung
        add_settings_section(
            'ab_email_section_gutschein_buyer',
            'Gutschein (Käufer-Bestätigung)',
            function() { echo '<hr>'; },
            'ab_email_customizer'
        );

        add_settings_field(
            'send_email_gutschein_buyer',
            'E-Mail senden aktivieren',
            [$this, 'render_checkbox_field'],
            'ab_email_customizer',
            'ab_email_section_gutschein_buyer',
            ['key' => 'send_email_gutschein_buyer']
        );

        add_settings_field(
            'subject_gutschein_buyer',
            'E-Mail Betreff',
            [$this, 'render_text_field'],
            'ab_email_customizer',
            'ab_email_section_gutschein_buyer',
            [
                'key' => 'subject_gutschein_buyer',
                'placeholder' => 'Deine Gutschein-Bestellung',
                'default' => 'Deine Gutschein-Bestellung'
            ]
        );

        add_settings_field(
            'content_gutschein_buyer',
            'E-Mail Inhalt',
            [$this, 'render_wysiwyg_field'],
            'ab_email_customizer',
            'ab_email_section_gutschein_buyer',
            [
                'key' => 'content_gutschein_buyer',
                'default' => AB_Gutschein_Email::get_default_content_gutschein_buyer(),
                'placeholder' => ''
            ]
        );

    }

    public function render_settings_page() {
            $options = get_option('ab_email_settings', []);
            $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'klassen';
            ?>
            <div class="wrap">
                <h1>AB E-Mail Templates für Bestellstatus</h1>

                <!-- FIX: Form muss VOR den globalen Einstellungen beginnen! -->
                <form method="post" action="options.php">
                    <?php
                    settings_fields('ab_email_customizer');
                    $custom_statuses = AB_Custom_Statuses::get_custom_statuses();
                    ?>

                    <!-- Globale E-Mail Einstellungen - jetzt INNERHALB des Forms -->
                    <div class="email-global-settings" style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin-bottom: 20px;">
                        <h2 style="margin-top: 0;">Globale E-Mail Einstellungen</h2>
                        <table class="form-table">
                            <tr>
                                <th scope="row">Absender E-Mail</th>
                                <td>
                                    <input type="text" class="regular-text" name="ab_email_settings[sender_email]"
                                           value="<?php echo esc_attr($options['sender_email'] ?? ''); ?>"
                                           placeholder="<?php echo esc_attr(get_option('admin_email')); ?>">
                                    <p class="description">Diese E-Mail wird als Absender und Reply-To für alle E-Mails verwendet. Wenn leer, wird die WordPress Admin-E-Mail verwendet.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Absender Name</th>
                                <td>
                                    <input type="text" class="regular-text" name="ab_email_settings[sender_name]"
                                           value="<?php echo esc_attr($options['sender_name'] ?? ''); ?>"
                                           placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>">
                                    <p class="description">Dieser Name wird als Absendername angezeigt. Wenn leer, wird der Seitenname verwendet.</p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- Akkordeon für Shortcodes -->
                    <div id="shortcode-accordion" class="accordion-container">
                        <button type="button" class="accordion-header">
                            <span class="dashicons dashicons-arrow-right-alt2"></span>
                            Verfügbare Shortcodes
                        </button>
                        <div class="accordion-content">
                            <?php
                            $shortcode_descriptions = function_exists('ab_get_shortcode_descriptions') ? ab_get_shortcode_descriptions() : array();
                            global $shortcode_tags;
                            $our_shortcodes = [];
                            foreach ($shortcode_tags as $tag => $callback) {
                                if (array_key_exists($tag, $shortcode_descriptions)) {
                                    $our_shortcodes[] = $tag;
                                }
                            }

                            if (!empty($our_shortcodes)) {
                                echo '<ul>';
                                foreach ($our_shortcodes as $sc) {
                                    $desc = isset($shortcode_descriptions[$sc]) ? $shortcode_descriptions[$sc] : 'Keine Beschreibung verfügbar.';
                                    echo '<li><code>[' . esc_html($sc) . ']</code>: ' . esc_html($desc) . '</li>';
                                }
                                echo '</ul>';
                            }
                            ?>
                        </div>
                    </div>

                    <!-- Tab-Navigation -->
                    <div class="ab-email-tabs">
                        <button type="button" class="ab-tab-btn active" data-tab="klassen">Klassen / Probetraining</button>
                        <button type="button" class="ab-tab-btn" data-tab="workshop">Workshop / Kurs</button>
                        <button type="button" class="ab-tab-btn" data-tab="experience">Experience</button>
                        <button type="button" class="ab-tab-btn" data-tab="gutschein">Gutschein</button>
                    </div>

                    <!-- =============================================
                         TAB 1: Klassen / Probetraining
                         ============================================= -->
                    <div class="ab-tab-panel active" data-tab="klassen">
                    <div class="email-templates-container">
                        <?php
                        $render_statuses = $custom_statuses;
                        unset($render_statuses['wc-gutschein']);
                        unset($render_statuses['wc-workshop']);
                        unset($render_statuses['wc-wsbesucht']);
                        unset($render_statuses['wc-wsnbesucht']);
                        unset($render_statuses['wc-kurs']);
                        unset($render_statuses['wc-kursbesucht']);
                        unset($render_statuses['wc-kursnbesucht']);
                        foreach ($render_statuses as $slug => $label):
                            $status_key = str_replace('wc-', '', $slug);
                            $options = get_option('ab_email_settings', []);
                            $is_active = !empty($options['send_email_' . $status_key]);
                        ?>
                            <div class="email-template-accordion accordion-container">
                              <div class="accordion-header <?php echo $is_active ? 'is-active-email' : ''; ?>">
                                <?php echo esc_html($label); ?>
                                <?php if ($is_active): ?>
                                    <span class="status-indicator">Aktiv</span>
                                <?php endif; ?>
                            </div>
                                <div class="accordion-content">
                                    <table class="form-table">
                                        <tr>
                                            <th scope="row">E-Mail aktivieren</th>
                                            <td>
                                                <?php $this->render_checkbox_field(['key' => 'send_email_' . $status_key]); ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row">E-Mail Betreff</th>
                                            <td>
                                                <?php $this->render_text_field([
                                                    'key' => 'subject_' . $status_key,
                                                    'placeholder' => 'Deine Bestellung ist jetzt im Status "{status}"'
                                                ]); ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row">Überschrift</th>
                                            <td>
                                                <?php $this->render_text_field([
                                                    'key' => 'header_' . $status_key,
                                                    'placeholder' => $label
                                                ]); ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row">E-Mail Inhalt</th>
                                            <td>
                                                <?php $this->render_wysiwyg_field([
                                                    'key' => 'content_' . $status_key,
                                                    'placeholder' => "Hallo {first_name},\n\ndeine Bestellung (#{order_number}) ist jetzt im Status: {status}."
                                                ]); ?>
                                            </td>
                                        </tr>

                                        <?php if ($status_key === 'vertragverschickt'): ?>
                                        <tr>
                                            <th scope="row">E-Mail Inhalt (von Warteliste)</th>
                                            <td>
                                                <?php $this->render_wysiwyg_field([
                                                    'key' => 'content_vertragverschickt_from_warteliste',
                                                    'placeholder' => '<div style="font-family: Arial, sans-serif; color: #333; padding: 20px; max-width: 600px; margin: 0 auto;">
<p><strong>Hallo [first_participant_first_name],</strong></p>
<p>wir haben gute Neuigkeiten für dich! 🎉</p>
<p>In unserer <strong>[ab_event_title_clean]</strong> Klasse ist nun ein Platz für dich frei geworden. Vielen Dank für deine Geduld – wir freuen uns riesig, dass es nun geklappt hat!</p>
<p>Wenn du dabei sein möchtest und mit deinem wöchentlichen Parkour Training starten willst, kannst du hier den Vertrag abschliessen:</p>
<div style="text-align: center; margin: 20px 0;">
<a href="[contract_link]">Jetzt Mitglied werden</a>
</div>
<p>Wir freuen uns sehr darauf, dich bald in unserer Klasse begrüssen zu dürfen!</p>
<p>ONE for All & All for ONE<br>Viele Grüsse</p>
</div>'
                                                ]); ?>
                                                <p class="description" style="margin-top: 10px; color: #666;">
                                                    <strong>Hinweis:</strong> Dieser Text wird verwendet, wenn der Status von "Warteliste" auf "Vertrag verschickt" wechselt.
                                                </p>
                                            </td>
                                        </tr>
                                        <?php endif; ?>

                                        <?php if ($status_key === 'schuelerin'): ?>
                                             <tr>
                                                 <th scope="row">Admin-Benachrichtigung bei neuem/r Schüler/in</th>
                                                 <td>
                                                     <?php $this->render_text_field([
                                                         'key' => 'admin_notification_schuelerin',
                                                         'placeholder' => 'admin@domain.tld'
                                                     ]); ?>
                                                 </td>
                                             </tr>
                                             <?php endif; ?>

                                             <?php if ($status_key === 'gekuendigt'): ?>
                                             <tr>
                                                 <th scope="row">Admin-Benachrichtigung bei Kündigung</th>
                                                 <td>
                                                     <?php $this->render_text_field([
                                                         'key' => 'admin_notification_gekuendigt',
                                                         'placeholder' => 'admin@domain.tld'
                                                     ]); ?>
                                                 </td>
                                             </tr>
                                             <?php endif; ?>

                                             <?php if ($status_key === 'kdginitiiert'): ?>
                                             <tr>
                                                 <th scope="row">Admin-Benachrichtigung bei Kündigung initiiert</th>
                                                 <td>
                                                     <?php $this->render_text_field([
                                                         'key' => 'admin_notification_kdginitiiert',
                                                         'placeholder' => 'admin@domain.tld'
                                                     ]); ?>
                                                 </td>
                                             </tr>
                                             <?php endif; ?>

                                             <?php if ($status_key === 'bestandkundeakz'): ?>
                                             <tr>
                                                 <th scope="row">Admin-Benachrichtigung bei Bestandskunde akzeptiert</th>
                                                 <td>
                                                     <?php $this->render_text_field([
                                                         'key' => 'admin_notification_bestandkundeakz',
                                                         'placeholder' => 'admin@domain.tld'
                                                     ]); ?>
                                                 </td>
                                             </tr>
                                             <?php endif; ?>


                                    </table>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <?php
                        // Bestandskunde Vertrag Erinnerung
                        $bk_reminder_key = 'bestandskunde_reminder';
                        $bk_reminder_label = 'Bestandskunde Vertrag — Erinnerung';
                        $is_active = !empty($options['send_email_' . $bk_reminder_key]);
                        ?>
                        <div class="email-template-accordion accordion-container">
                          <div class="accordion-header <?php echo $is_active ? 'is-active-email' : ''; ?>">
                            <?php echo esc_html($bk_reminder_label); ?>
                            <?php if ($is_active): ?>
                                <span class="status-indicator">Aktiv</span>
                            <?php endif; ?>
                          </div>
                            <div class="accordion-content">
                                <table class="form-table">
                                    <tr>
                                        <th scope="row">Erinnerung aktivieren</th>
                                        <td><?php $this->render_checkbox_field(['key' => 'send_email_bestandskunde_reminder']); ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Erinnerung nach X Tagen</th>
                                        <td><?php $this->render_number_field(['key' => 'bestandskunde_reminder_days', 'default' => 7, 'min' => 1, 'max' => 90, 'description' => 'Nach wie vielen Tagen im Status "Bestandskunde Vertrag" soll die Erinnerung gesendet werden?']); ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row">E-Mail Betreff</th>
                                        <td><?php $this->render_text_field(['key' => 'subject_bestandskunde_reminder', 'placeholder' => 'Erinnerung: Bitte Vertrag abschließen']); ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Überschrift</th>
                                        <td><?php $this->render_text_field(['key' => 'header_bestandskunde_reminder', 'placeholder' => 'Vertrag noch offen']); ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row">E-Mail Inhalt</th>
                                        <td><?php $this->render_wysiwyg_field(['key' => 'content_bestandskunde_reminder', 'placeholder' => "Hallo {first_name},\n\nwir haben dir vor einigen Tagen einen Vertrag zugeschickt, den du noch nicht abgeschlossen hast.\n\nBitte nutze den Link in unserer vorherigen E-Mail um deinen Vertrag auszufüllen.\n\nBei Fragen melde dich gerne bei uns."]); ?></td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        <?php
                        // Bestandskunde Vertrag 2. Erinnerung
                        $bk_reminder_2_key = 'bestandskunde_reminder_2';
                        $bk_reminder_2_label = 'Bestandskunde Vertrag — 2. Erinnerung';
                        $is_active_2 = !empty($options['send_email_' . $bk_reminder_2_key]);
                        ?>
                        <div class="email-template-accordion accordion-container">
                          <div class="accordion-header <?php echo $is_active_2 ? 'is-active-email' : ''; ?>">
                            <?php echo esc_html($bk_reminder_2_label); ?>
                            <?php if ($is_active_2): ?>
                                <span class="status-indicator">Aktiv</span>
                            <?php endif; ?>
                          </div>
                            <div class="accordion-content">
                                <table class="form-table">
                                    <tr>
                                        <th scope="row">2. Erinnerung aktivieren</th>
                                        <td><?php $this->render_checkbox_field(['key' => 'send_email_bestandskunde_reminder_2']); ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row">2. Erinnerung nach X Tagen</th>
                                        <td><?php $this->render_number_field(['key' => 'bestandskunde_reminder_2_days', 'default' => 14, 'min' => 1, 'max' => 90, 'description' => 'Nach wie vielen Tagen im Status "Bestandskunde Vertrag" soll die 2. Erinnerung gesendet werden? (muss grösser sein als die 1. Erinnerung)']); ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row">E-Mail Betreff</th>
                                        <td><?php $this->render_text_field(['key' => 'subject_bestandskunde_reminder_2', 'placeholder' => 'Letzte Erinnerung: Vertrag noch offen']); ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Überschrift</th>
                                        <td><?php $this->render_text_field(['key' => 'header_bestandskunde_reminder_2', 'placeholder' => 'Vertrag noch immer offen']); ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row">E-Mail Inhalt</th>
                                        <td><?php $this->render_wysiwyg_field(['key' => 'content_bestandskunde_reminder_2', 'placeholder' => "Hallo {first_name},\n\nwir haben dir bereits mehrfach einen Vertrag zugeschickt. Bitte schliesse diesen zeitnah ab, damit du weiterhin am Training teilnehmen kannst.\n\nBei Fragen melde dich gerne bei uns."]); ?></td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                    </div>
                    </div>

                    <!-- =============================================
                         TAB 2: Workshop / Kurs
                         ============================================= -->
                    <div class="ab-tab-panel" data-tab="workshop">
                    <div class="email-templates-container">
                        <?php
                        // Workshop Buchungsbestätigung (aus Status-Loop)
                        $ws_booking_key = 'workshop';
                        $ws_booking_label = 'Workshop Buchungsbestätigung';
                        $is_active = !empty($options['send_email_' . $ws_booking_key]);
                        ?>
                        <div class="email-template-accordion accordion-container">
                          <div class="accordion-header <?php echo $is_active ? 'is-active-email' : ''; ?>">
                            <?php echo esc_html($ws_booking_label); ?>
                            <?php if ($is_active): ?>
                                <span class="status-indicator">Aktiv</span>
                            <?php endif; ?>
                          </div>
                            <div class="accordion-content">
                                <table class="form-table">
                                    <tr>
                                        <th scope="row">E-Mail aktivieren</th>
                                        <td>
                                            <?php $this->render_checkbox_field(['key' => 'send_email_' . $ws_booking_key]); ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">E-Mail Betreff</th>
                                        <td>
                                            <?php $this->render_text_field([
                                                'key' => 'subject_' . $ws_booking_key,
                                                'placeholder' => 'Deine Workshop-Buchung',
                                                'default' => 'Deine Workshop-Buchung bei ParkourONE'
                                            ]); ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Überschrift</th>
                                        <td>
                                            <?php $this->render_text_field([
                                                'key' => 'header_' . $ws_booking_key,
                                                'placeholder' => 'Workshop',
                                                'default' => 'Workshop-Buchung bestätigt'
                                            ]); ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">E-Mail Inhalt</th>
                                        <td>
                                            <?php $this->render_wysiwyg_field([
                                                'key' => 'content_' . $ws_booking_key,
                                                'placeholder' => "Hallo {first_name},\n\ndanke für deine Workshop-Buchung!\n\n[ab_workshop_all_dates]",
                                                'default' => 'Hallo {first_name},

vielen Dank für deine Workshop-Buchung! Wir freuen uns, dich bald bei uns begrüssen zu dürfen.

<strong>Deine Workshop-Termine:</strong>
[ab_workshop_all_dates]

<strong>Ort:</strong> [ab_event_location]

Bitte bringe bequeme Sportkleidung und Hallenschuhe mit.

Bei Fragen melde dich gerne bei uns.

ONE for All &amp; All for ONE
Viele Grüsse'
                                            ]); ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Admin-Benachrichtigung</th>
                                        <td>
                                            <?php $this->render_text_field([
                                                'key' => 'admin_notification_workshop',
                                                'placeholder' => 'admin@domain.tld'
                                            ]); ?>
                                            <p class="description">E-Mail-Adresse für Benachrichtigungen bei neuen Workshop-Buchungen (leer = keine Benachrichtigung).</p>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        <?php
                        // Workshop Erinnerung
                        $ws_reminder_key = 'workshop_reminder';
                        $ws_reminder_label = 'Workshop Erinnerung';
                        $is_active = !empty($options['send_email_' . $ws_reminder_key]);
                        ?>
                        <div class="email-template-accordion accordion-container">
                          <div class="accordion-header <?php echo $is_active ? 'is-active-email' : ''; ?>">
                            <?php echo esc_html($ws_reminder_label); ?>
                            <?php if ($is_active): ?>
                                <span class="status-indicator">Aktiv</span>
                            <?php endif; ?>
                          </div>
                            <div class="accordion-content">
                                <table class="form-table">
                                    <tr>
                                        <th scope="row">E-Mail aktivieren</th>
                                        <td><?php $this->render_checkbox_field(['key' => 'send_email_workshop_reminder']); ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Tage vor Workshop</th>
                                        <td><?php $this->render_number_field(['key' => 'workshop_reminder_days', 'default' => 3, 'min' => 1, 'max' => 30, 'description' => 'Wie viele Tage vor dem ersten Workshop-Termin soll die Erinnerung gesendet werden?']); ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row">E-Mail Betreff</th>
                                        <td><?php $this->render_text_field(['key' => 'subject_workshop_reminder', 'placeholder' => 'Erinnerung an deinen Workshop', 'default' => 'Erinnerung: Dein Workshop steht bevor!']); ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Überschrift</th>
                                        <td><?php $this->render_text_field(['key' => 'header_workshop_reminder', 'placeholder' => 'Workshop Erinnerung', 'default' => 'Workshop-Erinnerung']); ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row">E-Mail Inhalt</th>
                                        <td><?php $this->render_wysiwyg_field(['key' => 'content_workshop_reminder', 'placeholder' => "Hallo {first_name},\n\ndein Workshop steht bald an!\n\n[ab_workshop_all_dates]", 'default' => 'Hallo {first_name},

dein Workshop steht bald an! Hier nochmals deine Termine:

[ab_workshop_all_dates]

<strong>Ort:</strong> [ab_event_location]

Bitte denke an bequeme Sportkleidung und Hallenschuhe.

Wir freuen uns auf dich!

ONE for All &amp; All for ONE
Viele Grüsse']); ?></td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        <?php
                        // Workshop Coach Erinnerung
                        $ws_coach_key = 'workshop_coach_reminder';
                        $ws_coach_label = 'Workshop Coach Erinnerung';
                        $is_active = !empty($options['send_email_' . $ws_coach_key]);
                        ?>
                        <div class="email-template-accordion accordion-container">
                          <div class="accordion-header <?php echo $is_active ? 'is-active-email' : ''; ?>">
                            <?php echo esc_html($ws_coach_label); ?>
                            <?php if ($is_active): ?>
                                <span class="status-indicator">Aktiv</span>
                            <?php endif; ?>
                          </div>
                            <div class="accordion-content">
                                <table class="form-table">
                                    <tr>
                                        <th scope="row">E-Mail aktivieren</th>
                                        <td><?php $this->render_checkbox_field(['key' => 'send_email_workshop_coach_reminder']); ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Tage vor Workshop</th>
                                        <td><?php $this->render_number_field(['key' => 'workshop_coach_reminder_days', 'default' => 3, 'min' => 1, 'max' => 30, 'description' => 'Wie viele Tage vor dem Workshop soll der Coach erinnert werden?']); ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row">E-Mail Betreff</th>
                                        <td><?php $this->render_text_field(['key' => 'subject_workshop_coach_reminder', 'placeholder' => 'Erinnerung: Dein Workshop steht bevor']); ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Überschrift</th>
                                        <td><?php $this->render_text_field(['key' => 'header_workshop_coach_reminder', 'placeholder' => 'Workshop-Erinnerung']); ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row">E-Mail Inhalt</th>
                                        <td><?php $this->render_wysiwyg_field(['key' => 'content_workshop_coach_reminder', 'placeholder' => 'Lieber Coach,...', 'default' => 'Lieber Coach,

dein Workshop steht bald an!

<strong>Workshop:</strong> [ab_event_title_clean]
<strong>Datum:</strong> [ab_event_weekday], [ab_event_date]
<strong>Uhrzeit:</strong> [ab_event_time]
<strong>Ort:</strong> [ab_event_location]

<strong>Alle Termine:</strong>
[ab_workshop_all_dates]

<a href="[ab_google_calendar_link]" style="display:inline-block;background:#0066cc;color:#fff;padding:10px 20px;text-decoration:none;border-radius:5px;">Zum Google Kalender hinzufügen</a>

<strong>Teilnehmer:</strong>
[ab_event_participants]

ONE for All &amp; All for ONE
Viele Grüsse']); ?></td>
                                    </tr>
                                </table>
                                <p class="description" style="padding: 0 12px 12px;">Verfügbare Platzhalter: [ab_event_title_clean], [ab_event_date], [ab_event_weekday], [ab_event_time], [ab_event_location], [ab_workshop_all_dates], [ab_google_calendar_link], [ab_event_participants], [ab_event_coach]</p>
                            </div>
                        </div>

                        <?php
                        // Workshop besucht
                        $is_active = !empty($options['send_email_wsbesucht']);
                        ?>
                            <div class="email-template-accordion accordion-container">
                              <div class="accordion-header <?php echo $is_active ? 'is-active-email' : ''; ?>">
                                Workshop besucht
                                <?php if ($is_active): ?>
                                    <span class="status-indicator">Aktiv</span>
                                <?php endif; ?>
                              </div>
                                <div class="accordion-content">
                                    <table class="form-table">
                                        <tr>
                                            <th scope="row">E-Mail aktivieren</th>
                                            <td><?php $this->render_checkbox_field(['key' => 'send_email_wsbesucht']); ?></td>
                                        </tr>
                                        <tr>
                                            <th scope="row">E-Mail Betreff</th>
                                            <td><?php $this->render_text_field(['key' => 'subject_wsbesucht', 'placeholder' => 'Workshop besucht', 'default' => 'Danke für deine Teilnahme am Workshop!']); ?></td>
                                        </tr>
                                        <tr>
                                            <th scope="row">Überschrift</th>
                                            <td><?php $this->render_text_field(['key' => 'header_wsbesucht', 'placeholder' => 'Workshop besucht', 'default' => 'Workshop besucht']); ?></td>
                                        </tr>
                                        <tr>
                                            <th scope="row">E-Mail Inhalt</th>
                                            <td><?php $this->render_wysiwyg_field(['key' => 'content_wsbesucht', 'placeholder' => 'Hallo {first_name},...', 'default' => 'Hallo {first_name},

vielen Dank für deine Teilnahme an unserem Workshop! Wir hoffen, es hat dir gefallen und du konntest viel mitnehmen.

Wenn du Lust hast, regelmässig Parkour zu trainieren, schau dir gerne unsere Klassen an — wir freuen uns, dich wiederzusehen!

ONE for All &amp; All for ONE
Viele Grüsse']); ?></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>

                        <?php
                        // Workshop nicht besucht
                        $is_active = !empty($options['send_email_wsnbesucht']);
                        ?>
                            <div class="email-template-accordion accordion-container">
                              <div class="accordion-header <?php echo $is_active ? 'is-active-email' : ''; ?>">
                                Workshop nicht besucht
                                <?php if ($is_active): ?>
                                    <span class="status-indicator">Aktiv</span>
                                <?php endif; ?>
                              </div>
                                <div class="accordion-content">
                                    <table class="form-table">
                                        <tr>
                                            <th scope="row">E-Mail aktivieren</th>
                                            <td><?php $this->render_checkbox_field(['key' => 'send_email_wsnbesucht']); ?></td>
                                        </tr>
                                        <tr>
                                            <th scope="row">E-Mail Betreff</th>
                                            <td><?php $this->render_text_field(['key' => 'subject_wsnbesucht', 'placeholder' => 'Workshop nicht besucht', 'default' => 'Schade, wir haben dich beim Workshop vermisst!']); ?></td>
                                        </tr>
                                        <tr>
                                            <th scope="row">Überschrift</th>
                                            <td><?php $this->render_text_field(['key' => 'header_wsnbesucht', 'placeholder' => 'Workshop nicht besucht', 'default' => 'Workshop nicht besucht']); ?></td>
                                        </tr>
                                        <tr>
                                            <th scope="row">E-Mail Inhalt</th>
                                            <td><?php $this->render_wysiwyg_field(['key' => 'content_wsnbesucht', 'placeholder' => 'Hallo {first_name},...', 'default' => 'Hallo {first_name},

schade, dass du nicht an unserem Workshop teilnehmen konntest. Wir hoffen, es geht dir gut!

Falls du den Workshop nachholen möchtest, melde dich gerne bei uns — wir finden sicher eine Lösung.

ONE for All &amp; All for ONE
Viele Grüsse']); ?></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>

                        <h3 style="margin-top: 30px; padding-top: 20px; border-top: 2px solid #ccd0d4;">Kurs</h3>

                        <?php
                        // Kurs Buchungsbestätigung
                        $kurs_booking_key = 'kurs';
                        $kurs_booking_label = 'Kurs Buchungsbestätigung';
                        $is_active = !empty($options['send_email_' . $kurs_booking_key]);
                        ?>
                        <div class="email-template-accordion accordion-container">
                          <div class="accordion-header <?php echo $is_active ? 'is-active-email' : ''; ?>">
                            <?php echo esc_html($kurs_booking_label); ?>
                            <?php if ($is_active): ?>
                                <span class="status-indicator">Aktiv</span>
                            <?php endif; ?>
                          </div>
                            <div class="accordion-content">
                                <table class="form-table">
                                    <tr>
                                        <th scope="row">E-Mail aktivieren</th>
                                        <td><?php $this->render_checkbox_field(['key' => 'send_email_kurs']); ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row">E-Mail Betreff</th>
                                        <td><?php $this->render_text_field(['key' => 'subject_kurs', 'placeholder' => 'Deine Kurs-Buchung', 'default' => 'Deine Kurs-Buchung bei ParkourONE']); ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Überschrift</th>
                                        <td><?php $this->render_text_field(['key' => 'header_kurs', 'placeholder' => 'Kurs', 'default' => 'Kurs-Buchung bestätigt']); ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row">E-Mail Inhalt</th>
                                        <td><?php $this->render_wysiwyg_field(['key' => 'content_kurs', 'placeholder' => "Hallo {first_name},\n\ndanke für deine Kurs-Buchung!\n\n[ab_workshop_all_dates]", 'default' => 'Hallo {first_name},

vielen Dank für deine Kurs-Buchung! Wir freuen uns, dich bald bei uns begrüssen zu dürfen.

<strong>Deine Kurs-Termine:</strong>
[ab_workshop_all_dates]

<strong>Ort:</strong> [ab_event_location]

Bitte bringe bequeme Sportkleidung und Hallenschuhe mit.

Bei Fragen melde dich gerne bei uns.

ONE for All &amp; All for ONE
Viele Grüsse']); ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Admin-Benachrichtigung</th>
                                        <td>
                                            <?php $this->render_text_field([
                                                'key' => 'admin_notification_kurs',
                                                'placeholder' => 'admin@domain.tld'
                                            ]); ?>
                                            <p class="description">E-Mail-Adresse für Benachrichtigungen bei neuen Kurs-Buchungen (leer = keine Benachrichtigung).</p>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        <?php
                        // Kurs Erinnerung
                        $kurs_reminder_key = 'kurs_reminder';
                        $kurs_reminder_label = 'Kurs Erinnerung';
                        $is_active = !empty($options['send_email_' . $kurs_reminder_key]);
                        ?>
                        <div class="email-template-accordion accordion-container">
                          <div class="accordion-header <?php echo $is_active ? 'is-active-email' : ''; ?>">
                            <?php echo esc_html($kurs_reminder_label); ?>
                            <?php if ($is_active): ?>
                                <span class="status-indicator">Aktiv</span>
                            <?php endif; ?>
                          </div>
                            <div class="accordion-content">
                                <table class="form-table">
                                    <tr>
                                        <th scope="row">E-Mail aktivieren</th>
                                        <td><?php $this->render_checkbox_field(['key' => 'send_email_kurs_reminder']); ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Tage vor Kurs</th>
                                        <td><?php $this->render_number_field(['key' => 'kurs_reminder_days', 'default' => 3, 'min' => 1, 'max' => 30, 'description' => 'Wie viele Tage vor dem ersten Kurs-Termin soll die Erinnerung gesendet werden?']); ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row">E-Mail Betreff</th>
                                        <td><?php $this->render_text_field(['key' => 'subject_kurs_reminder', 'placeholder' => 'Erinnerung an deinen Kurs', 'default' => 'Erinnerung: Dein Kurs startet bald!']); ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Überschrift</th>
                                        <td><?php $this->render_text_field(['key' => 'header_kurs_reminder', 'placeholder' => 'Kurs Erinnerung', 'default' => 'Kurs-Erinnerung']); ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row">E-Mail Inhalt</th>
                                        <td><?php $this->render_wysiwyg_field(['key' => 'content_kurs_reminder', 'placeholder' => "Hallo {first_name},\n\ndein Kurs steht bald an!\n\n[ab_workshop_all_dates]", 'default' => 'Hallo {first_name},

dein Kurs startet bald! Hier nochmals deine Termine:

[ab_workshop_all_dates]

<strong>Ort:</strong> [ab_event_location]

Bitte denke an bequeme Sportkleidung und Hallenschuhe.

Wir freuen uns auf dich!

ONE for All &amp; All for ONE
Viele Grüsse']); ?></td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        <?php
                        // Kurs Coach Erinnerung
                        $kurs_coach_key = 'kurs_coach_reminder';
                        $kurs_coach_label = 'Kurs Coach Erinnerung';
                        $is_active = !empty($options['send_email_' . $kurs_coach_key]);
                        ?>
                        <div class="email-template-accordion accordion-container">
                          <div class="accordion-header <?php echo $is_active ? 'is-active-email' : ''; ?>">
                            <?php echo esc_html($kurs_coach_label); ?>
                            <?php if ($is_active): ?>
                                <span class="status-indicator">Aktiv</span>
                            <?php endif; ?>
                          </div>
                            <div class="accordion-content">
                                <table class="form-table">
                                    <tr>
                                        <th scope="row">E-Mail aktivieren</th>
                                        <td><?php $this->render_checkbox_field(['key' => 'send_email_kurs_coach_reminder']); ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Tage vor Kurs</th>
                                        <td><?php $this->render_number_field(['key' => 'kurs_coach_reminder_days', 'default' => 3, 'min' => 1, 'max' => 30, 'description' => 'Wie viele Tage vor dem Kurs soll der Coach erinnert werden?']); ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row">E-Mail Betreff</th>
                                        <td><?php $this->render_text_field(['key' => 'subject_kurs_coach_reminder', 'placeholder' => 'Erinnerung: Dein Kurs steht bevor']); ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Überschrift</th>
                                        <td><?php $this->render_text_field(['key' => 'header_kurs_coach_reminder', 'placeholder' => 'Kurs-Erinnerung']); ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row">E-Mail Inhalt</th>
                                        <td><?php $this->render_wysiwyg_field(['key' => 'content_kurs_coach_reminder', 'placeholder' => 'Lieber Coach,...', 'default' => 'Lieber Coach,

dein Kurs steht bald an!

<strong>Kurs:</strong> [ab_event_title_clean]
<strong>Datum:</strong> [ab_event_weekday], [ab_event_date]
<strong>Uhrzeit:</strong> [ab_event_time]
<strong>Ort:</strong> [ab_event_location]

<strong>Alle Termine:</strong>
[ab_workshop_all_dates]

<a href="[ab_google_calendar_link]" style="display:inline-block;background:#0066cc;color:#fff;padding:10px 20px;text-decoration:none;border-radius:5px;">Zum Google Kalender hinzufügen</a>

<strong>Teilnehmer:</strong>
[ab_event_participants]

ONE for All &amp; All for ONE
Viele Grüsse']); ?></td>
                                    </tr>
                                </table>
                                <p class="description" style="padding: 0 12px 12px;">Verfügbare Platzhalter: [ab_event_title_clean], [ab_event_date], [ab_event_weekday], [ab_event_time], [ab_event_location], [ab_workshop_all_dates], [ab_google_calendar_link], [ab_event_participants], [ab_event_coach]</p>
                            </div>
                        </div>

                        <?php
                        // Kurs besucht
                        $is_active = !empty($options['send_email_kursbesucht']);
                        ?>
                            <div class="email-template-accordion accordion-container">
                              <div class="accordion-header <?php echo $is_active ? 'is-active-email' : ''; ?>">
                                Kurs besucht
                                <?php if ($is_active): ?>
                                    <span class="status-indicator">Aktiv</span>
                                <?php endif; ?>
                              </div>
                                <div class="accordion-content">
                                    <table class="form-table">
                                        <tr>
                                            <th scope="row">E-Mail aktivieren</th>
                                            <td><?php $this->render_checkbox_field(['key' => 'send_email_kursbesucht']); ?></td>
                                        </tr>
                                        <tr>
                                            <th scope="row">E-Mail Betreff</th>
                                            <td><?php $this->render_text_field(['key' => 'subject_kursbesucht', 'placeholder' => 'Kurs besucht', 'default' => 'Danke für deine Teilnahme am Kurs!']); ?></td>
                                        </tr>
                                        <tr>
                                            <th scope="row">Überschrift</th>
                                            <td><?php $this->render_text_field(['key' => 'header_kursbesucht', 'placeholder' => 'Kurs besucht', 'default' => 'Kurs besucht']); ?></td>
                                        </tr>
                                        <tr>
                                            <th scope="row">E-Mail Inhalt</th>
                                            <td><?php $this->render_wysiwyg_field(['key' => 'content_kursbesucht', 'placeholder' => 'Hallo {first_name},...', 'default' => 'Hallo {first_name},

vielen Dank für deine Teilnahme an unserem Kurs! Wir hoffen, es hat dir gefallen und du konntest viel mitnehmen.

Wenn du Lust hast, regelmässig Parkour zu trainieren, schau dir gerne unsere Klassen an — wir freuen uns, dich wiederzusehen!

ONE for All &amp; All for ONE
Viele Grüsse']); ?></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>

                        <?php
                        // Kurs nicht besucht
                        $is_active = !empty($options['send_email_kursnbesucht']);
                        ?>
                            <div class="email-template-accordion accordion-container">
                              <div class="accordion-header <?php echo $is_active ? 'is-active-email' : ''; ?>">
                                Kurs nicht besucht
                                <?php if ($is_active): ?>
                                    <span class="status-indicator">Aktiv</span>
                                <?php endif; ?>
                              </div>
                                <div class="accordion-content">
                                    <table class="form-table">
                                        <tr>
                                            <th scope="row">E-Mail aktivieren</th>
                                            <td><?php $this->render_checkbox_field(['key' => 'send_email_kursnbesucht']); ?></td>
                                        </tr>
                                        <tr>
                                            <th scope="row">E-Mail Betreff</th>
                                            <td><?php $this->render_text_field(['key' => 'subject_kursnbesucht', 'placeholder' => 'Kurs nicht besucht', 'default' => 'Schade, wir haben dich beim Kurs vermisst!']); ?></td>
                                        </tr>
                                        <tr>
                                            <th scope="row">Überschrift</th>
                                            <td><?php $this->render_text_field(['key' => 'header_kursnbesucht', 'placeholder' => 'Kurs nicht besucht', 'default' => 'Kurs nicht besucht']); ?></td>
                                        </tr>
                                        <tr>
                                            <th scope="row">E-Mail Inhalt</th>
                                            <td><?php $this->render_wysiwyg_field(['key' => 'content_kursnbesucht', 'placeholder' => 'Hallo {first_name},...', 'default' => 'Hallo {first_name},

schade, dass du nicht an unserem Kurs teilnehmen konntest. Wir hoffen, es geht dir gut!

Falls du den Kurs nachholen möchtest, melde dich gerne bei uns — wir finden sicher eine Lösung.

ONE for All &amp; All for ONE
Viele Grüsse']); ?></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                    </div>
                    </div>

                    <!-- =============================================
                         TAB 3: Experience
                         ============================================= -->
                    <div class="ab-tab-panel" data-tab="experience">
                    <div class="email-templates-container">
                        <p class="description" style="margin-bottom: 15px;">
                            Diese E-Mails werden automatisch geplant, sobald ein/e Schüler/in den Status "Schüler_in" erhält.
                            Die Zeitpunkte beziehen sich auf den Moment des Status-Wechsels.
                        </p>
                        <?php
                        $experience_sections = [
                            'experience_welcome'     => 'Willkommen (1 Woche nach Einstieg)',
                            'experience_one_month'   => 'Ein Monat bei ONE (1 Monat nach Einstieg)',
                            'experience_two_months'  => '2 Monate Parkour (2 Monate nach Einstieg)',
                        ];
                        foreach ($experience_sections as $exp_key => $exp_label):
                            $options = get_option('ab_email_settings', []);
                            $is_active = !empty($options['send_email_' . $exp_key]);
                        ?>
                            <div class="email-template-accordion accordion-container">
                              <div class="accordion-header <?php echo $is_active ? 'is-active-email' : ''; ?>">
                                <?php echo esc_html($exp_label); ?>
                                <?php if ($is_active): ?>
                                    <span class="status-indicator">Aktiv</span>
                                <?php endif; ?>
                              </div>
                                <div class="accordion-content">
                                    <table class="form-table">
                                        <tr>
                                            <th scope="row">E-Mail aktivieren</th>
                                            <td><?php $this->render_checkbox_field(['key' => 'send_email_' . $exp_key]); ?></td>
                                        </tr>
                                        <tr>
                                            <th scope="row">E-Mail Betreff</th>
                                            <td><?php $this->render_text_field(['key' => 'subject_' . $exp_key, 'placeholder' => $exp_label]); ?></td>
                                        </tr>
                                        <tr>
                                            <th scope="row">Überschrift</th>
                                            <td><?php $this->render_text_field(['key' => 'header_' . $exp_key, 'placeholder' => $exp_label]); ?></td>
                                        </tr>
                                        <tr>
                                            <th scope="row">E-Mail Inhalt</th>
                                            <td><?php $this->render_wysiwyg_field(['key' => 'content_' . $exp_key, 'placeholder' => "Hallo {first_name},\n\n..."]); ?></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    </div>

                    <!-- =============================================
                         TAB 4: Gutschein
                         ============================================= -->
                    <div class="ab-tab-panel" data-tab="gutschein">
                    <div class="email-templates-container">
                        <?php
                        $gutschein_sections = [
                            'gutschein' => 'Gutschein (an Empfänger)',
                            'gutschein_buyer' => 'Gutschein (Käufer-Bestätigung)',
                        ];
                        foreach ($gutschein_sections as $gs_key => $gs_label):
                            $options = get_option('ab_email_settings', []);
                            $is_active = !empty($options['send_email_' . $gs_key]);
                        ?>
                            <div class="email-template-accordion accordion-container">
                              <div class="accordion-header <?php echo $is_active ? 'is-active-email' : ''; ?>">
                                <?php echo esc_html($gs_label); ?>
                                <?php if ($is_active): ?>
                                    <span class="status-indicator">Aktiv</span>
                                <?php endif; ?>
                            </div>
                                <div class="accordion-content">
                                    <table class="form-table">
                                        <tr>
                                            <th scope="row">E-Mail aktivieren</th>
                                            <td>
                                                <?php $this->render_checkbox_field(['key' => 'send_email_' . $gs_key]); ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row">E-Mail Betreff</th>
                                            <td>
                                                <?php $this->render_text_field([
                                                    'key' => 'subject_' . $gs_key,
                                                    'placeholder' => $gs_key === 'gutschein' ? 'Dein Parkour ONE Gutschein' : 'Deine Gutschein-Bestellung',
                                                    'default' => $gs_key === 'gutschein' ? 'Dein Parkour ONE Gutschein' : 'Deine Gutschein-Bestellung'
                                                ]); ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row">E-Mail Inhalt</th>
                                            <td>
                                                <?php $this->render_wysiwyg_field([
                                                    'key' => 'content_' . $gs_key,
                                                    'default' => $gs_key === 'gutschein'
                                                        ? AB_Gutschein_Email::get_default_content_gutschein()
                                                        : AB_Gutschein_Email::get_default_content_gutschein_buyer(),
                                                    'placeholder' => ''
                                                ]); ?>
                                                <p class="description" style="margin-top: 10px; color: #666;">
                                                    <?php if ($gs_key === 'gutschein'): ?>
                                                        <strong>Hinweis:</strong> Dies ist der komplette E-Mail-Inhalt. Der Platzhalter <code>{gutschein_nachricht_block}</code> wird durch die persoenliche Nachricht des Absenders ersetzt (falls vorhanden). Logo und Footer werden automatisch ergaenzt.<br>
                                                        Verfuegbare Shortcodes: <code>[ab_gutschein_code]</code>, <code>[ab_gutschein_wert]</code>, <code>[ab_gutschein_ablauf]</code>
                                                    <?php else: ?>
                                                        <strong>Hinweis:</strong> Diese E-Mail wird nur gesendet, wenn der Gutschein an einen Dritten verschickt wird. Der K&auml;ufer erh&auml;lt dann diese Best&auml;tigung. Logo und Footer werden automatisch erg&auml;nzt.<br>
                                                        Verfuegbare Shortcodes: <code>[ab_gutschein_code]</code>, <code>[ab_gutschein_wert]</code>, <code>[ab_gutschein_ablauf]</code>, <code>[ab_gutschein_empfaenger]</code>
                                                    <?php endif; ?>
                                                </p>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    </div>

                    <?php submit_button('Einstellungen speichern'); ?>






                </form>

                <style>
                /* Tab Navigation */
                .ab-email-tabs {
                    display: flex;
                    gap: 0;
                    margin-bottom: 0;
                    border-bottom: 2px solid #0073aa;
                }
                .ab-tab-btn {
                    padding: 12px 24px;
                    background: #f0f0f1;
                    border: 1px solid #ccd0d4;
                    border-bottom: none;
                    cursor: pointer;
                    font-size: 14px;
                    font-weight: 600;
                    color: #50575e;
                    border-radius: 4px 4px 0 0;
                    margin-right: 4px;
                    position: relative;
                    top: 2px;
                }
                .ab-tab-btn:hover {
                    background: #fff;
                    color: #0073aa;
                }
                .ab-tab-btn.active {
                    background: #fff;
                    color: #0073aa;
                    border-color: #0073aa #0073aa #fff #0073aa;
                    border-width: 2px 2px 2px 2px;
                }
                .ab-tab-panel {
                    display: none;
                    border: 1px solid #ccd0d4;
                    border-top: none;
                    padding: 20px;
                    background: #fff;
                    margin-bottom: 20px;
                }
                .ab-tab-panel.active {
                    display: block;
                }

                /* Accordion */
                .accordion-container {
                    border: 1px solid #ccd0d4;
                    margin-bottom: 10px;
                    background: #fff;
                }
                .accordion-header {
                    width: 100%;
                    padding: 15px;
                    background: #f8f9fa;
                    border: none;
                    text-align: left;
                    cursor: pointer;
                    font-weight: 600;
                }
                .accordion-content {
                    padding: 20px;
                    border-top: 1px solid #ccd0d4;
                    display: none;
                }
                .accordion-content.is-visible {
                    display: block;
                }
                .is-active-email {
                    background: #edfaef;
                    border-left: 4px solid #00a32a;
                }
                .status-indicator {
                    float: right;
                    background: #00a32a;
                    color: white;
                    padding: 2px 8px;
                    border-radius: 3px;
                }
                </style>

                <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Tab Switching
        document.querySelectorAll('.ab-tab-btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();

                // Alle Tabs deaktivieren
                document.querySelectorAll('.ab-tab-btn').forEach(b => b.classList.remove('active'));
                document.querySelectorAll('.ab-tab-panel').forEach(p => p.classList.remove('active'));

                // Angeklickten Tab aktivieren
                this.classList.add('active');
                const targetTab = this.getAttribute('data-tab');
                document.querySelector('.ab-tab-panel[data-tab="' + targetTab + '"]').classList.add('active');

                return false;
            });
        });

        // Accordion Toggle
        document.querySelectorAll('.accordion-header').forEach(header => {
            header.addEventListener('click', function(e) {
                // Verhindere Formular-Submit und Button-Default
                e.preventDefault();
                e.stopPropagation();

                const content = this.nextElementSibling;
                content.classList.toggle('is-visible');

                return false; // Extra Sicherheit gegen Submit
            });
        });

        // Checkbox Handler
        document.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const header = this.closest('.accordion-content')
                                  .previousElementSibling;

                if (this.checked) {
                    header.classList.add('is-active-email');
                    if (!header.querySelector('.status-indicator')) {
                        const indicator = document.createElement('span');
                        indicator.className = 'status-indicator';
                        indicator.textContent = 'Aktiv';
                        header.appendChild(indicator);
                    }
                } else {
                    header.classList.remove('is-active-email');
                    const indicator = header.querySelector('.status-indicator');
                    if (indicator) indicator.remove();
                }
            });
        });
    });
    </script>
            </div>
            <?php
        }



    public function render_text_field($args) {
        $options = get_option('ab_email_settings', []);
        $value   = isset($options[$args['key']]) ? $options[$args['key']] : '';

        // Default-Wert anzeigen wenn Feld leer und Default vorhanden
        if (empty($value) && !empty($args['default'])) {
            $value = $args['default'];
        }

        printf(
            '<input type="text" class="regular-text" name="ab_email_settings[%s]" value="%s" placeholder="%s">',
            esc_attr($args['key']),
            esc_attr($value),
            esc_attr($args['placeholder'])
        );
        echo '<p class="description">Verfügbare Platzhalter: {first_name}, {last_name}, {order_number}, {status} + beliebige Shortcodes wie [ab_event_date]</p>';
    }

    public function render_wysiwyg_field($args) {
        $options = get_option('ab_email_settings', []);
        $value   = isset($options[$args['key']]) ? $options[$args['key']] : '';

        // Default-Wert anzeigen wenn Feld leer und Default vorhanden
        if (empty($value) && !empty($args['default'])) {
            $value = $args['default'];
        }

        wp_editor(
            $value,
            'ab_email_' . $args['key'],
            [
                'textarea_name' => 'ab_email_settings[' . $args['key'] . ']',
                'textarea_rows' => 10,
                'media_buttons' => false,
                'teeny'         => true,
                'quicktags'     => true
            ]
        );
        echo '<p class="description">Verfügbare Platzhalter: {first_name}, {last_name}, {order_number}, {status} + Shortcodes ([ab_event_date], [ab_participants], etc.)</p>';
    }


    public function render_checkbox_field($args) {
    $options = get_option('ab_email_settings', []);
    $value = isset($options[$args['key']]) ? $options[$args['key']] : 0;
    printf(
        '<input type="checkbox" name="ab_email_settings[%s]" value="1" %s>',
        esc_attr($args['key']),
        checked(1, $value, false)
    );
}

    public function render_number_field($args) {
        $options = get_option('ab_email_settings', []);
        $value = isset($options[$args['key']]) ? intval($options[$args['key']]) : (isset($args['default']) ? $args['default'] : 3);
        $min = isset($args['min']) ? $args['min'] : 1;
        $max = isset($args['max']) ? $args['max'] : 30;

        printf(
            '<input type="number" class="small-text" name="ab_email_settings[%s]" value="%d" min="%d" max="%d">',
            esc_attr($args['key']),
            $value,
            $min,
            $max
        );
        if (!empty($args['description'])) {
            printf('<p class="description">%s</p>', esc_html($args['description']));
        }
    }


    /**
     * Ersetzt die Platzhalter ({first_name}, {status}, usw.) im Text durch echte Werte.
     */
    public static function replace_variables($content, \WC_Order $order, $status_label) {
        $replacements = [
            '{first_name}'   => $order->get_billing_first_name(),
            '{last_name}'    => $order->get_billing_last_name(),
            '{order_number}' => $order->get_order_number(),
            '{status}'       => $status_label,
        ];






        return str_replace(array_keys($replacements), array_values($replacements), $content);
    }
}
