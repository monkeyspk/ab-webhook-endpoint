<?php
if (!defined('ABSPATH')) {
    exit;
}

class AB_Custom_Statuses {

    /**
     * Gib eine Liste deiner Custom-Statuses zurück (Slug => Label).
     */
    public static function get_custom_statuses() {
        return [
            'wc-vertragverschickt' => 'Vertrag verschickt',
            'wc-keinerueckmeldung' => 'Keine Rückmeldung',
            'wc-probetraining'     => 'Probetraining',
            'wc-schuelerin'        => 'Schüler_in',
            'wc-abgelehnt'         => 'Abgelehnt',
            'wc-warteliste'        => 'Warteliste',
            'wc-gekuendigt'        => 'Gekündigt',
            'wc-kdginitiiert'      => 'Kündigung initiiert',
            'wc-nichterschienen'   => 'Nicht erschienen',
            'wc-trainingabgesagt'  => 'Training abgesagt',
            'wc-gutschein'         => 'Gutschein',
            'wc-workshop'          => 'Workshop',
            'wc-wsbesucht'         => 'Workshop besucht',
            'wc-wsnbesucht'        => 'Workshop nicht besucht',
            'wc-kurs'              => 'Kurs',
            'wc-kursbesucht'       => 'Kurs besucht',
            'wc-kursnbesucht'      => 'Kurs nicht besucht',
            'wc-bestandskunde'        => 'Bestandskunde',
            'wc-bkdvertrag'           => 'Bestandskunde Vertrag',
            'wc-bestandkundeakz'      => 'Bestandskunde akzeptiert',
        ];
    }

    /**
     * Registriert die oben aufgeführten Custom-Statuses.
     */
    public static function register_statuses() {
        foreach (self::get_custom_statuses() as $status_slug => $status_label) {
            if (!get_post_status_object($status_slug)) {
                register_post_status($status_slug, [
                    'label'                     => $status_label,
                    'public'                    => true,
                    'exclude_from_search'       => false,
                    'show_in_admin_all_list'    => true,
                    'show_in_admin_status_list' => true,
                    'label_count'               => _n_noop(
                        $status_label . ' <span class="count">(%s)</span>',
                        $status_label . ' <span class="count">(%s)</span>'
                    ),
                ]);
            }
        }
        // Filter: fügt Custom-Statuses zum Dropdown in WooCommerce hinzu
        add_filter('wc_order_statuses', [__CLASS__, 'add_to_wc_order_statuses']);
    }

    /**
     * Fügt die Custom Status dem Woo-Dropdown hinzu.
     */
    public static function add_to_wc_order_statuses($order_statuses) {
        foreach (self::get_custom_statuses() as $status_slug => $status_label) {
            $order_statuses[$status_slug] = $status_label;
        }
        return $order_statuses;
    }

    /**
     * Mapping-Funktion: ordnet einen AcademyBoard-Status dem WooCommerce-Slug zu.
     */
    public static function map_academyboard_status($academy_status) {
        $mapping = [
            'Schüler_in'         => 'wc-schuelerin',
            'Abgelehnt'          => 'wc-abgelehnt',
            'Vertrag verschickt' => 'wc-vertragverschickt',
            'Warteliste'         => 'wc-warteliste',
            'Keine Rückmeldung'  => 'wc-keinerueckmeldung',
            'Gekündigt'          => 'wc-gekuendigt',
            'Probeteilnehmer_in' => 'wc-probetraining',
            'Workshop'              => 'wc-workshop',
            'Workshop besucht'      => 'wc-wsbesucht',
            'Workshop nicht besucht' => 'wc-wsnbesucht',
            'Kurs'                  => 'wc-kurs',
            'Kurs besucht'          => 'wc-kursbesucht',
            'Kurs nicht besucht'    => 'wc-kursnbesucht',
            'Nicht erschienen'      => 'wc-nichterschienen',
            'Kündigung initiiert'   => 'wc-kdginitiiert',
            'Training abgesagt'     => 'wc-trainingabgesagt',
            'Bestandskunde'            => 'wc-bestandskunde',
            'Bestandskunde akzeptiert' => 'wc-bestandkundeakz',
        ];

        return isset($mapping[$academy_status]) ? $mapping[$academy_status] : null;
    }

    /**
     * Hook zum Speichern des vorherigen Status bei Statusänderungen
     */
    public static function init_status_tracking() {
        add_action('woocommerce_order_status_changed', [__CLASS__, 'track_previous_status'], 10, 4);

        // Schutz: Verhindert Status "schuelerin" ohne abgeschlossenen Vertrag (Admin-Backend)
        add_action('woocommerce_order_status_changed', [__CLASS__, 'prevent_schuelerin_without_contract'], 5, 4);

        // Workshop/Kurs-E-Mails abbrechen wenn Status wegwechselt
        add_action('woocommerce_order_status_changed', [__CLASS__, 'unschedule_workshop_on_status_change'], 10, 4);

        // Experience-E-Mails planen wenn erstmalig Schüler_in
        add_action('woocommerce_order_status_changed', [__CLASS__, 'schedule_experience_on_schuelerin'], 10, 4);

        // Admin-Notices anzeigen
        add_action('admin_notices', [__CLASS__, 'display_admin_notices']);
    }

    /**
     * Cancelt geplante Workshop-E-Mails wenn Status von workshop wegwechselt
     */
    public static function unschedule_workshop_on_status_change($order_id, $old_status, $new_status, $order) {
        if (class_exists('AB_Workshop_Scheduler')) {
            if ($old_status === 'workshop' && $new_status !== 'workshop') {
                AB_Workshop_Scheduler::unschedule_workshop_emails($order_id);
            }
            if ($old_status === 'kurs' && $new_status !== 'kurs') {
                AB_Workshop_Scheduler::unschedule_kurs_emails($order_id);
            }
        }
    }

    /**
     * Plant Experience-E-Mails wenn Status erstmalig auf "schuelerin" wechselt.
     * Cancelt geplante Experience-E-Mails wenn Status von "schuelerin" wegwechselt.
     */
    public static function schedule_experience_on_schuelerin($order_id, $old_status, $new_status, $order) {
        if (!class_exists('AB_Workshop_Scheduler')) {
            return;
        }

        if ($new_status === 'schuelerin' && $old_status !== 'schuelerin') {
            AB_Workshop_Scheduler::schedule_experience_emails($order_id);
        }

        if ($old_status === 'schuelerin' && $new_status !== 'schuelerin') {
            AB_Workshop_Scheduler::unschedule_experience_emails($order_id);
        }
    }

    /**
     * Speichert den vorherigen Status als Meta-Feld
     */
    public static function track_previous_status($order_id, $old_status, $new_status, $order) {
        // Vorherigen Status speichern
        update_post_meta($order_id, '_ab_previous_status', $old_status);

        // Logging für Debugging
        error_log('[AB Status Plugin] Status geändert für Order #' . $order_id . ': ' . $old_status . ' -> ' . $new_status);
    }

    /**
     * Verhindert Status-Änderung auf "schuelerin" wenn kein Vertrag abgeschlossen wurde.
     * Zeigt Admin-Notice wenn im Backend versucht wird.
     */
    public static function prevent_schuelerin_without_contract($order_id, $old_status, $new_status, $order) {
        // Nur bei Änderung auf "schuelerin" oder "bestandkundeakz" prüfen
        if ($new_status !== 'schuelerin' && $new_status !== 'bestandkundeakz') {
            return;
        }

        // Prüfen ob Vertrag abgeschlossen wurde
        $contract_status = get_post_meta($order_id, '_contract_status', true);

        if ($contract_status === 'completed') {
            // Alles OK - Vertrag liegt vor
            return;
        }

        // Status-Label für die Fehlermeldung bestimmen
        $status_labels = [
            'schuelerin' => 'Schüler_in',
            'bestandkundeakz' => 'Bestandskunde akzeptiert',
        ];
        $status_label = isset($status_labels[$new_status]) ? $status_labels[$new_status] : $new_status;

        // KEIN Vertrag vorhanden - Status zurücksetzen!
        error_log(sprintf(
            '[AB Status Plugin] ADMIN-BLOCK: Status-Änderung auf "%s" für Order #%d verhindert - kein abgeschlossener Vertrag.',
            $new_status,
            $order_id
        ));

        // Status zurücksetzen auf den vorherigen
        remove_action('woocommerce_order_status_changed', [__CLASS__, 'prevent_schuelerin_without_contract'], 5, 4);
        $order->set_status($old_status);
        $order->save();
        add_action('woocommerce_order_status_changed', [__CLASS__, 'prevent_schuelerin_without_contract'], 5, 4);

        // Admin-Notice für das Backend setzen
        set_transient('ab_admin_notice_' . get_current_user_id(), [
            'type' => 'error',
            'message' => sprintf(
                '<strong>Status-Änderung nicht möglich!</strong><br>' .
                'Die Bestellung #%d kann nicht auf "%s" gesetzt werden, da kein abgeschlossener Vertrag vorliegt.<br>' .
                'Der Kunde muss zuerst den Vertrag über den Vertrags-Wizard abschließen.',
                $order_id,
                $status_label
            )
        ], 30);
    }

    /**
     * Zeigt Admin-Notices im Backend an
     */
    public static function display_admin_notices() {
        $notice = get_transient('ab_admin_notice_' . get_current_user_id());

        if ($notice) {
            printf(
                '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
                esc_attr($notice['type']),
                wp_kses_post($notice['message'])
            );
            delete_transient('ab_admin_notice_' . get_current_user_id());
        }
    }
}


add_filter('woocommerce_order_is_paid_statuses', function($statuses) {
    // ACHTUNG: get_status() liefert ohne "wc-".
    // D.h. wenn dein Custom-Status "wc-probetraining" heißt,
    // dann musst du hier 'probetraining' eintragen.
    $statuses[] = 'probetraining';
    return $statuses;
});




/**
 * Prüft ob eine Bestellung eine Event-Buchung ist.
 * Erkennt sowohl Probetrainings (_event_participant_data) als auch
 * Workshops/Kurse (_event_is_workshop, _event_course_id).
 */
function ab_order_is_event_booking($order) {
    if (!$order instanceof WC_Order) {
        return false;
    }
    foreach ($order->get_items() as $item) {
        if (!empty($item->get_meta('_event_participant_data'))) {
            return true;
        }
        $is_workshop = $item->get_meta('_event_is_workshop');
        if ($is_workshop === '1' || $is_workshop === 1) {
            return true;
        }
        if (!empty($item->get_meta('_event_course_id'))) {
            return true;
        }
    }
    return false;
}

add_filter('woocommerce_email_enabled_customer_completed_order', function($enabled, $order) {
    if ( ! $order instanceof WC_Order ) {
        return $enabled;
    }

    // Event-Buchungen: "completed" E-Mail unterdrücken
    if (ab_order_is_event_booking($order)) {
        return false;
    }

    return $enabled;
}, 10, 2);


add_action('woocommerce_payment_complete', 'check_and_set_probetraining_status');
function check_and_set_probetraining_status($order_id) {
    $order = wc_get_order($order_id);
    if ( ! $order ) {
        return;
    }

    // Prüfen ob es eine Event-Buchung ist (Probetraining, Workshop oder Kurs)
    if (ab_order_is_event_booking($order)) {
        $event_type = ab_get_order_event_type($order);
        $target_status = ab_map_event_type_to_status($event_type);
        $order->update_status($target_status);
    }
}




/* ----------------------------------------   */


add_filter('woocommerce_payment_complete_order_status', function($status, $order_id, $order) {
    if (! $order instanceof WC_Order) {
        return $status;
    }

    // Event-Buchungen direkt auf den richtigen Status setzen
    if (ab_order_is_event_booking($order)) {
        $event_type = ab_get_order_event_type($order);
        return ab_map_event_type_to_status($event_type);
    }

    // Sonst normaler Ablauf (z.B. "completed" oder "processing")
    return $status;
}, 10, 3);



add_filter('woocommerce_order_is_paid_statuses', function($statuses) {
    $statuses[] = 'probetraining'; // ohne wc-
    return $statuses;
});



add_filter('woocommerce_valid_order_statuses_for_payment_complete', function($statuses) {
    $statuses[] = 'wc-probetraining';
    return $statuses;
});


add_filter('woocommerce_payment_complete_reduce_order_stock', function($reduce_stock, $order_id) {
    $order = wc_get_order($order_id);
    if ($order && $order->has_status('probetraining')) {
        $reduce_stock = true;
    }
    return $reduce_stock;
}, 10, 2);



add_action('woocommerce_order_status_probetraining', function($order_id) {
    AB_Email_Sender::send_status_email($order_id, 'wc-probetraining');
}, 10, 1);


// =============================================
// Workshop-Status: als bezahlt markieren + E-Mails
// =============================================

add_filter('woocommerce_order_is_paid_statuses', function($statuses) {
    $statuses[] = 'workshop'; // ohne wc-
    return $statuses;
});

add_filter('woocommerce_valid_order_statuses_for_payment_complete', function($statuses) {
    $statuses[] = 'wc-workshop';
    return $statuses;
});

add_action('woocommerce_order_status_workshop', function($order_id) {
    AB_Email_Sender::send_status_email($order_id, 'wc-workshop'); // Buchungsbestätigung
    AB_Workshop_Scheduler::schedule_workshop_emails($order_id);   // Reminder planen
}, 10, 1);


// =============================================
// Neue Workshop-Status: besucht / nicht besucht
// =============================================

add_action('woocommerce_order_status_wsbesucht', function($order_id) {
    AB_Email_Sender::send_status_email($order_id, 'wc-wsbesucht');
}, 10, 1);

add_action('woocommerce_order_status_wsnbesucht', function($order_id) {
    AB_Email_Sender::send_status_email($order_id, 'wc-wsnbesucht');
}, 10, 1);

// =============================================
// Kurs-Status: als bezahlt markieren + E-Mails
// =============================================

add_filter('woocommerce_order_is_paid_statuses', function($statuses) {
    $statuses[] = 'kurs'; // ohne wc-
    return $statuses;
});

add_filter('woocommerce_valid_order_statuses_for_payment_complete', function($statuses) {
    $statuses[] = 'wc-kurs';
    return $statuses;
});

add_action('woocommerce_order_status_kurs', function($order_id) {
    AB_Email_Sender::send_status_email($order_id, 'wc-kurs');   // Buchungsbestätigung
    AB_Workshop_Scheduler::schedule_kurs_emails($order_id);      // Reminder planen
}, 10, 1);

// =============================================
// Neue Kurs-Status: besucht / nicht besucht
// =============================================

add_action('woocommerce_order_status_kursbesucht', function($order_id) {
    AB_Email_Sender::send_status_email($order_id, 'wc-kursbesucht');
}, 10, 1);

add_action('woocommerce_order_status_kursnbesucht', function($order_id) {
    AB_Email_Sender::send_status_email($order_id, 'wc-kursnbesucht');
}, 10, 1);

// =============================================
// Bestandskunde-Status: E-Mail bei Vertrag
// =============================================

add_action('woocommerce_order_status_bkdvertrag', function($order_id) {
    AB_Email_Sender::send_status_email($order_id, 'wc-bkdvertrag');
}, 10, 1);

// =============================================
// Gutschein-Status: als bezahlt markieren
// =============================================

add_filter('woocommerce_order_is_paid_statuses', function($statuses) {
    $statuses[] = 'gutschein'; // ohne wc-
    return $statuses;
});

add_filter('woocommerce_valid_order_statuses_for_payment_complete', function($statuses) {
    $statuses[] = 'wc-gutschein';
    return $statuses;
});





// =============================================
// Fallback: Event-Bestellungen die auf "processing" oder "completed" landen
// werden automatisch auf den richtigen Event-Status umgeleitet.
// Dies fängt u.a. Gratis-Bestellungen (100% Coupon) ab, bei denen
// payment_complete() ggf. nicht den Custom-Status setzt.
// =============================================

function ab_redirect_order_to_event_status($order_id, $order) {
    if (!$order instanceof WC_Order) {
        $order = wc_get_order($order_id);
    }
    if (!$order) {
        return;
    }

    // Prüfen ob es eine Event-Buchung ist (Probetraining, Workshop oder Kurs)
    if (!ab_order_is_event_booking($order)) {
        return;
    }

    // Skip-Marker setzen damit keine doppelten E-Mails gesendet werden
    update_post_meta($order_id, '_ab_skip_probetraining_email', 'yes');

    // Konkreten Event-Typ ermitteln
    $event_type = ab_get_order_event_type($order);
    $target_wc_status = ab_map_event_type_to_status($event_type);
    $target_status = str_replace('wc-', '', $target_wc_status);

    error_log("[AB Status] Bestellung {$order_id} mit Teilnehmerdaten auf Standard-Status gelandet - setze auf '{$target_status}' (Event-Typ: {$event_type})");

    // Handler temporär entfernen um Endlosschleifen zu vermeiden
    remove_action('woocommerce_order_status_completed', 'ab_redirect_order_to_event_status', 999);
    remove_action('woocommerce_order_status_processing', 'ab_redirect_order_to_event_status', 999);

    // Status setzen
    $order->update_status($target_status, "Automatisch auf {$target_status} gesetzt (Teilnehmerdaten vorhanden)");

    // Handler wieder hinzufügen
    add_action('woocommerce_order_status_completed', 'ab_redirect_order_to_event_status', 999, 2);
    add_action('woocommerce_order_status_processing', 'ab_redirect_order_to_event_status', 999, 2);
}

add_action('woocommerce_order_status_completed', 'ab_redirect_order_to_event_status', 999, 2);
add_action('woocommerce_order_status_processing', 'ab_redirect_order_to_event_status', 999, 2);

// Verhindere das Senden von E-Mails, wenn der Skip-Marker gesetzt ist
add_filter('woocommerce_email_enabled_customer_on_hold_order', 'ab_check_skip_email_marker', 10, 2);
add_filter('woocommerce_email_enabled_customer_processing_order', 'ab_check_skip_email_marker', 10, 2);
add_filter('woocommerce_email_enabled_customer_completed_order', 'ab_check_skip_email_marker', 10, 2);
// Filter auch für Ihre Custom-E-Mails hinzufügen
add_filter('ab_email_enabled_probetraining', 'ab_check_skip_email_marker', 10, 2);

// Hilfsfunktion zur Überprüfung des Markers
function ab_check_skip_email_marker($enabled, $order) {
    if (!$order instanceof WC_Order) {
        return $enabled;
    }

    // Wenn der Skip-Marker gesetzt ist, E-Mail deaktivieren und Marker entfernen
    if (get_post_meta($order->get_id(), '_ab_skip_probetraining_email', true) === 'yes') {
        delete_post_meta($order->get_id(), '_ab_skip_probetraining_email');
        return false;
    }

    // Auch Silent-Update Marker prüfen
    if (get_post_meta($order->get_id(), '_ab_silent_update', true) === 'yes') {
        return false;
    }

    return $enabled;
}


/*--------------------------------


// (a) "Completed"-Mail unterdrücken, wenn Teilnehmerdaten
add_filter('woocommerce_email_enabled_customer_completed_order', function($enabled, $order) {
    if (!$order instanceof WC_Order) return $enabled;
    foreach ($order->get_items() as $item) {
        $participant_data = $item->get_meta('_event_participant_data');
        if (!empty($participant_data)) {
            return false; // Completed-Mail aus
        }
    }
    return $enabled;
}, 10, 2);

// (b) Zahlung komplett -> Status probetraining
add_action('woocommerce_payment_complete', function($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return;
    foreach ($order->get_items() as $item) {
        $participant_data = $item->get_meta('_event_participant_data');
        if (!empty($participant_data)) {
            $order->update_status('wc-probetraining');
            break;
        }
    }
});

// (c) Custom-Status als bezahlt
add_filter('woocommerce_order_is_paid_statuses', function($statuses) {
    $statuses[] = 'probetraining';
    return $statuses;
});

// (d) Mail verschicken bei Status "wc-probetraining"
add_action('woocommerce_order_status_probetraining', function($order_id) {
    AB_Email_Sender::send_status_email($order_id, 'wc-probetraining');
}, 10, 1);


*/
