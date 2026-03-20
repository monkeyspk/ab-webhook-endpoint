<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Wir setzen in deinem E-Mail-Versand-Code eine globale Variable:
 *
 *   global $ab_current_order;
 *   $ab_current_order = $order;
 *
 * So können diese Shortcode-Funktionen auf die passende Bestellung zugreifen.
 */




/**
 * 1) Gemeinsame Hilfsfunktion:
 *    Hole das erste Bestellposten-Item mit Event-Metadaten.
 */



function ab_we_get_first_event_item(\WC_Order $order) {
    foreach ($order->get_items() as $item) {
        if ($item->get_meta('_event_title') || $item->get_meta('_event_date')) {
            return $item;
        }
    }
    return null;
}

/**
 * 2) Shortcode: [ab_event_title]
 */
function ab_sc_event_title() {
    global $ab_current_order;
    if (!$ab_current_order) return '';

    $item = ab_we_get_first_event_item($ab_current_order);
    if (!$item) return '';

    return esc_html($item->get_meta('_event_title'));
}
add_shortcode('ab_event_title', 'ab_sc_event_title');


/**
* 2.1 Shortcode: [ab_event_title_clean]
*/
function ab_sc_event_title_clean() {
   global $ab_current_order;
   if (!$ab_current_order) return '';

   $clean_title = $ab_current_order->get_meta('_event_title_clean');
   if (!empty($clean_title)) {
       return esc_html($clean_title);
   }

   $item = ab_we_get_first_event_item($ab_current_order);
   if (!$item) return '';

   return esc_html($item->get_meta('_event_title_clean'));
}
add_shortcode('ab_event_title_clean', 'ab_sc_event_title_clean');



/**
 * 3) Shortcode: [ab_event_date]
 */
function ab_sc_event_date() {
    global $ab_current_order;
    if (!$ab_current_order) return '';

    $item = ab_we_get_first_event_item($ab_current_order);
    if (!$item) return '';

    return esc_html($item->get_meta('_event_date'));
}
add_shortcode('ab_event_date', 'ab_sc_event_date');

/**
 * 4) Shortcode: [ab_event_time]
 */
function ab_sc_event_time() {
    global $ab_current_order;
    if (!$ab_current_order) return '';

    $item = ab_we_get_first_event_item($ab_current_order);
    if (!$item) return '';

    return esc_html($item->get_meta('_event_time'));
}
add_shortcode('ab_event_time', 'ab_sc_event_time');

/**
 * 5) Shortcode: [ab_event_location]
 */
 function ab_sc_event_location() {
     global $ab_current_order;
     if (!$ab_current_order) return '';

     // Location holen
     $venue = '';
     $lat = $ab_current_order->get_meta('_event_venue_lat');
     $lng = $ab_current_order->get_meta('_event_venue_lng');

     $item = ab_we_get_first_event_item($ab_current_order);
     if ($item) {
         $venue = $item->get_meta('_event_venue');  // Geändert von _event_location zu _event_venue

         // Falls keine Koordinaten in Order, von Item holen
         if (empty($lat)) $lat = $item->get_meta('_event_venue_lat');
         if (empty($lng)) $lng = $item->get_meta('_event_venue_lng');
     }

     if (!empty($venue) && !empty($lat) && !empty($lng)) {
         $maps_url = sprintf('https://www.google.com/maps?q=%s,%s', $lat, $lng);
         return sprintf('<a href="%s" target="_blank">%s</a>', esc_url($maps_url), esc_html($venue));
     }

     return esc_html($venue);
 }

add_shortcode('ab_event_location', 'ab_sc_event_location');

/**
 * 6) Shortcode: [ab_event_coach]
 */
function ab_sc_event_coach() {
    global $ab_current_order;
    if (!$ab_current_order) return '';

    $item = ab_we_get_first_event_item($ab_current_order);
    if (!$item) return '';

    return esc_html($item->get_meta('_event_coach'));
}
add_shortcode('ab_event_coach', 'ab_sc_event_coach');

/**
 * 7) Shortcode: [ab_event_coach_image]
 */
 function ab_sc_event_coach_image() {
     global $ab_current_order;
     if (!$ab_current_order) return '';

     $item = ab_we_get_first_event_item($ab_current_order);
     if (!$item) return '';

     $image_url = $item->get_meta('_event_coach_image');
     if (!$image_url) return '';

     // Bild-Daten herunterladen
     $image_data = file_get_contents($image_url);
     if (!$image_data) return $image_url;

     // Base64-Kodierung
     $base64 = base64_encode($image_data);
     $mime_type = 'image/jpeg'; // Oder dynamisch ermitteln

     return sprintf('<img src="data:%s;base64,%s" alt="Coach" style="max-width:200px;">',
         $mime_type,
         $base64
     );
 }
add_shortcode('ab_event_coach_image', 'ab_sc_event_coach_image');

/**
 * 8) Shortcode: [ab_event_coach_phone]
 */
function ab_sc_event_coach_phone() {
    global $ab_current_order;
    if (!$ab_current_order) return '';

    $item = ab_we_get_first_event_item($ab_current_order);
    if (!$item) return '';

    return esc_html($item->get_meta('_event_coach_phone'));
}
add_shortcode('ab_event_coach_phone', 'ab_sc_event_coach_phone');


/**
 * 11) Shortcode: [ab_event_product_id]
 */
function ab_sc_event_product_id() {
    global $ab_current_order;
    if (!$ab_current_order) return '';

    $item = ab_we_get_first_event_item($ab_current_order);
    if (!$item) return '';

    return esc_html($item->get_meta('_event_product_id'));
}
add_shortcode('ab_event_product_id', 'ab_sc_event_product_id');

/**
 * 12) Shortcode: [ab_event_price]
 */
function ab_sc_event_price() {
    global $ab_current_order;
    if (!$ab_current_order) return '';

    $item = ab_we_get_first_event_item($ab_current_order);
    if (!$item) return '';

    return esc_html($item->get_meta('_event_price'));
}
add_shortcode('ab_event_price', 'ab_sc_event_price');

/**
 * 13) Shortcode: [ab_event_availability]
 */
function ab_sc_event_availability() {
    global $ab_current_order;
    if (!$ab_current_order) return '';

    $item = ab_we_get_first_event_item($ab_current_order);
    if (!$item) return '';

    return esc_html($item->get_meta('_event_availability'));
}
add_shortcode('ab_event_availability', 'ab_sc_event_availability');

/**
 * 14) Shortcode: [ab_event_participants]
 */
function ab_sc_event_participants() {
    global $ab_current_order, $ab_coach_email_all_orders;

    // Coach-Email Kontext: Teilnehmer aus ALLEN Bestellungen aggregieren
    if (!empty($ab_coach_email_all_orders) && is_array($ab_coach_email_all_orders)) {
        $all_participants = [];
        foreach ($ab_coach_email_all_orders as $order) {
            foreach ($order->get_items() as $item) {
                $participants = $item->get_meta('_event_participant_data');
                if (!empty($participants) && is_array($participants)) {
                    foreach ($participants as $p) {
                        $all_participants[] = $p;
                    }
                }
            }
        }

        if (empty($all_participants)) {
            return 'Keine Teilnehmerdaten vorhanden.';
        }

        $output = "";
        foreach ($all_participants as $index => $p) {
            $output .= sprintf(
                "%d) %s %s (%s)\n",
                $index + 1,
                $p['vorname'] ?? '',
                $p['name'] ?? '',
                $p['geburtsdatum'] ?? ''
            );
        }
        return '<pre>' . esc_html($output) . '</pre>';
    }

    // Standard: Teilnehmer aus einzelner Bestellung
    if (!$ab_current_order) return '';

    $item = ab_we_get_first_event_item($ab_current_order);
    if (!$item) return '';

    $participants = $item->get_meta('_event_participant_data');
    if (empty($participants) || !is_array($participants)) {
        return 'Keine Teilnehmerdaten vorhanden.';
    }

    $output = "";
    foreach ($participants as $index => $p) {
        $output .= sprintf(
            "%d) %s %s (%s)\n",
            $index + 1,
            isset($p['vorname']) ? $p['vorname'] : '',
            isset($p['name']) ? $p['name'] : '',
            isset($p['geburtsdatum']) ? $p['geburtsdatum'] : ''
        );
    }

    return '<pre>' . esc_html($output) . '</pre>';
}
add_shortcode('ab_event_participants', 'ab_sc_event_participants');

/**
 * Shortcode: [first_participant_first_name]
 */
function ab_first_participant_first_name() {
    global $ab_current_order;
    if (!$ab_current_order) return '';

    foreach ($ab_current_order->get_items() as $item) {
        $participants = $item->get_meta('_event_participant_data');
        if (!empty($participants) && is_array($participants)) {
            $first_participant = reset($participants);
            return esc_html($first_participant['vorname'] ?? '');
        }
    }
    return '';
}
add_shortcode('first_participant_first_name', 'ab_first_participant_first_name');

/**
 * Shortcode: [first_participant_last_name]
 */
function ab_first_participant_last_name() {
    global $ab_current_order;
    if (!$ab_current_order) return '';

    foreach ($ab_current_order->get_items() as $item) {
        $participants = $item->get_meta('_event_participant_data');
        if (!empty($participants) && is_array($participants)) {
            $first_participant = reset($participants);
            return esc_html($first_participant['name'] ?? '');
        }
    }
    return '';
}
add_shortcode('first_participant_last_name', 'ab_first_participant_last_name');

/**
 * Shortcode: [first_participant_dob]
 */
function ab_first_participant_dob() {
    global $ab_current_order;
    if (!$ab_current_order) return '';

    foreach ($ab_current_order->get_items() as $item) {
        $participants = $item->get_meta('_event_participant_data');
        if (!empty($participants) && is_array($participants)) {
            $first_participant = reset($participants);
            return esc_html($first_participant['geburtsdatum'] ?? '');
        }
    }
    return '';
}
add_shortcode('first_participant_dob', 'ab_first_participant_dob');

/**
 * 15) Shortcode: [ab_event_dates]
 */
function ab_sc_event_dates() {
    global $ab_current_order;
    if (!$ab_current_order) return '';

    $item = ab_we_get_first_event_item($ab_current_order);
    if (!$item) return '';

    $dates = $item->get_meta('_event_dates');
    if (empty($dates) || !is_array($dates)) {
        return 'Keine weiteren Termine vorhanden.';
    }

    $output = "Termine:\n";
    foreach ($dates as $d) {
        $output .= sprintf("- %s (%s - %s)\n", $d['date'], $d['start_time'], $d['end_time']);
    }

    return '<pre>' . esc_html($output) . '</pre>';
}
add_shortcode('ab_event_dates', 'ab_sc_event_dates');

/**
 * 16) Shortcode: [event_list]
 */
function ab_sc_event_list($atts = [], $content = null) {
    return 'Hier kommt die Event-Liste hin (Frontend-Funktion, Skeleton-Loader usw.)';
}
add_shortcode('event_list', 'ab_sc_event_list');

/**
 * Shortcode: [ab_today]
 */
function ab_sc_today() {
    return date('d-m-Y');
}
add_shortcode('ab_today', 'ab_sc_today');

/**
 * Shortcode: [ab_today_plus_7]
 */
function ab_sc_today_plus_7() {
    return date('d-m-Y', strtotime('+7 days'));
}
add_shortcode('ab_today_plus_7', 'ab_sc_today_plus_7');

/**
 * Shortcode: [ab_event_whatsapp_link]
 */
 /**
  * Shortcode: [ab_event_whatsapp_link]
  */
 function ab_sc_event_whatsapp_link() {
     global $ab_current_order;
     if (!$ab_current_order) {
         error_log('WhatsApp Link: Keine aktuelle Bestellung gefunden');
         return '';
     }

     $item = ab_we_get_first_event_item($ab_current_order);
     if (!$item) {
         error_log('WhatsApp Link: Kein Event-Item gefunden');
         return '';
     }

     $link = $item->get_meta('_event_whatsapp_link');

     if (!empty($link)) {
         error_log('WhatsApp Link gefunden: ' . $link);
         return esc_url($link);
     }

     error_log('WhatsApp Link: Kein Link gefunden');
     return '';
 }
 add_shortcode('ab_event_whatsapp_link', 'ab_sc_event_whatsapp_link');


/**
 * Shortcode: [ab_event_weekday]
 */
function ab_sc_event_weekday() {
    global $ab_current_order;
    if (!$ab_current_order) return '';

    $item = ab_we_get_first_event_item($ab_current_order);
    if (!$item) return '';

    $date = $item->get_meta('_event_date');
    if (!$date) return '';

    $weekdays = array('Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag');
    return $weekdays[date('w', strtotime($date))];
}
add_shortcode('ab_event_weekday', 'ab_sc_event_weekday');


// Latitude aus Bestellung
function ab_sc_event_venue_lat() {
    global $ab_current_order;
    if (!$ab_current_order) return '';

    $lat = $ab_current_order->get_meta('_event_venue_lat');
    if (!empty($lat)) {
        return esc_html($lat);
    }

    $item = ab_we_get_first_event_item($ab_current_order);
    if (!$item) return '';
    return esc_html($item->get_meta('_event_venue_lat'));
}
add_shortcode('ab_event_venue_lat', 'ab_sc_event_venue_lat');



// Longitude aus Bestellung
function ab_sc_event_venue_lng() {
    global $ab_current_order;
    if (!$ab_current_order) return '';

    $lng = $ab_current_order->get_meta('_event_venue_lng');
    if (!empty($lng)) {
        return esc_html($lng);
    }

    $item = ab_we_get_first_event_item($ab_current_order);
    if (!$item) return '';
    return esc_html($item->get_meta('_event_venue_lng'));
}
add_shortcode('ab_event_venue_lng', 'ab_sc_event_venue_lng');

/**
 * Shortcode: [contract_link]
 */
function ab_sc_contract_link() {
    global $ab_current_order;
    if (!$ab_current_order) return '';

    $wizard_page_url = home_url('/vertrag/');
    $token = wp_generate_password(32, false);
    update_post_meta($ab_current_order->get_id(), '_ab_contract_token', $token);

    return add_query_arg([
        'order_id' => $ab_current_order->get_id(),
        'step'     => 1,
        'token'    => $token
    ], $wizard_page_url);
}
add_shortcode('contract_link', 'ab_sc_contract_link');



/**
 * Shortcode: [ab_academy_username]
 */
function ab_sc_academy_username() {
    global $ab_current_order;
    if (!$ab_current_order) return '';

    $order_id = $ab_current_order->get_id();

    $first_name = ab_first_participant_first_name();
    $last_name = ab_first_participant_last_name();

    if (empty($first_name) || empty($last_name)) return '';

    return strtolower($first_name . $last_name . '#' . $order_id);
}
add_shortcode('ab_academy_username', 'ab_sc_academy_username');

/**
 * Shortcode: [ab_academy_password]
 */
function ab_sc_academy_password() {
    global $ab_current_order;
    if (!$ab_current_order) return '';

    $first_name = ab_first_participant_first_name();
    $last_name = ab_first_participant_last_name();
    $event_date = ab_sc_event_date();

    if (empty($first_name) || empty($last_name) || empty($event_date)) return '';

    return strtolower($first_name . $last_name . '#' . $event_date);
}
add_shortcode('ab_academy_password', 'ab_sc_academy_password');

/**
 * Shortcode: [ab_rebooking_link]
 * Gibt den Self-Service-Umbuchungslink aus (standardmäßig nur im Status Probetraining).
 */
function ab_sc_rebooking_link($atts, $content = null) {
    global $ab_current_order;
    if (!$ab_current_order) {
        return '';
    }

    $atts = shortcode_atts(array(
        'status' => 'probetraining',
        'label'  => __('Zur Umbuchungsseite', 'ab-webhook-endpoint'),
        'class'  => 'ab-rebooking-link-button',
    ), $atts, 'ab_rebooking_link');

    if (!empty($atts['status']) && !$ab_current_order->has_status($atts['status'])) {
        return '';
    }

    if (!function_exists('custom_events_get_rebooking_url')) {
        return '';
    }

    $url = custom_events_get_rebooking_url($ab_current_order->get_id());
    if (empty($url)) {
        return '';
    }

    $label = $content ? $content : $atts['label'];

    return sprintf(
        '<a class="%s" href="%s">%s</a>',
        esc_attr($atts['class']),
        esc_url($url),
        esc_html($label)
    );
}
add_shortcode('ab_rebooking_link', 'ab_sc_rebooking_link');

// =============================================
// Gutschein-Shortcodes
// =============================================

/**
 * Shortcode: [ab_gutschein_code]
 * Gibt den generierten Gutschein-Code zurueck.
 */
function ab_sc_gutschein_code() {
    global $ab_current_order;
    if (!$ab_current_order) return '';

    $code = $ab_current_order->get_meta('_ab_gutschein_coupon_code');
    return esc_html($code);
}
add_shortcode('ab_gutschein_code', 'ab_sc_gutschein_code');

/**
 * Shortcode: [ab_gutschein_wert]
 * Gibt den Gutschein-Wert formatiert zurueck.
 */
function ab_sc_gutschein_wert() {
    global $ab_current_order;
    if (!$ab_current_order) return '';

    $amount = $ab_current_order->get_meta('_ab_gutschein_coupon_amount');
    if (empty($amount)) return '';

    return number_format(floatval($amount), 2, ',', '.') . ' &euro;';
}
add_shortcode('ab_gutschein_wert', 'ab_sc_gutschein_wert');

/**
 * Shortcode: [ab_gutschein_ablauf]
 * Gibt das Ablaufdatum des Gutscheins zurueck.
 */
function ab_sc_gutschein_ablauf() {
    global $ab_current_order;
    if (!$ab_current_order) return '';

    $expiry = $ab_current_order->get_meta('_ab_gutschein_coupon_expiry');
    return esc_html($expiry);
}
add_shortcode('ab_gutschein_ablauf', 'ab_sc_gutschein_ablauf');

/**
 * Shortcode: [ab_gutschein_nachricht]
 * Gibt die persoenliche Nachricht des Gutscheins zurueck.
 */
function ab_sc_gutschein_nachricht() {
    global $ab_current_order;
    if (!$ab_current_order) return '';

    foreach ($ab_current_order->get_items() as $item) {
        $message = $item->get_meta('_ab_gutschein_message');
        if (!empty($message)) {
            return esc_html($message);
        }
    }
    return '';
}
add_shortcode('ab_gutschein_nachricht', 'ab_sc_gutschein_nachricht');

/**
 * Shortcode: [ab_gutschein_empfaenger]
 * Gibt die E-Mail des Gutschein-Empfaengers zurueck.
 */
function ab_sc_gutschein_empfaenger() {
    global $ab_current_order;
    if (!$ab_current_order) return '';

    foreach ($ab_current_order->get_items() as $item) {
        $recipient = $item->get_meta('_ab_gutschein_recipient_email');
        if (!empty($recipient)) {
            return esc_html($recipient);
        }
    }
    return '';
}
add_shortcode('ab_gutschein_empfaenger', 'ab_sc_gutschein_empfaenger');


/**
 * Shortcode: [ab_workshop_all_dates]
 * Zeigt alle Workshop-Termine formatiert als Liste an (Wochentag, Datum, Uhrzeit).
 */
function ab_sc_workshop_all_dates() {
    global $ab_current_order;
    if (!$ab_current_order) return '';

    $item = ab_we_get_first_event_item($ab_current_order);
    if (!$item) return '';

    $product_id = $item->get_meta('_event_product_id');
    if (!$product_id) {
        $product_id = $item->get_product_id();
    }
    if (!$product_id) return '';

    $event_id = get_post_meta($product_id, '_event_id', true);
    if (!$event_id) return '';

    $event_dates = get_post_meta($event_id, '_event_dates', true);
    if (empty($event_dates) || !is_array($event_dates)) {
        return '';
    }

    $weekdays = array('Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag');

    // Termine nach Datum sortieren
    usort($event_dates, function($a, $b) {
        $ts_a = DateTime::createFromFormat('d-m-Y', trim($a['date']));
        $ts_b = DateTime::createFromFormat('d-m-Y', trim($b['date']));
        if (!$ts_a || !$ts_b) return 0;
        return $ts_a <=> $ts_b;
    });

    $output = '<ul style="list-style: none; padding: 0; margin: 0;">';
    foreach ($event_dates as $d) {
        if (empty($d['date'])) continue;
        $date_obj = DateTime::createFromFormat('d-m-Y', trim($d['date']));
        if (!$date_obj) continue;

        $weekday = $weekdays[(int)$date_obj->format('w')];
        $formatted_date = $date_obj->format('d.m.Y');

        $time_str = '';
        if (!empty($d['start_time']) && !empty($d['end_time'])) {
            $time_str = $d['start_time'] . ' - ' . $d['end_time'];
        } elseif (!empty($d['start_time'])) {
            $time_str = $d['start_time'];
        }

        $output .= '<li style="padding: 4px 0;">';
        $output .= esc_html($weekday . ', ' . $formatted_date);
        if ($time_str) {
            $output .= ' | ' . esc_html($time_str);
        }
        $output .= '</li>';
    }
    $output .= '</ul>';

    return $output;
}
add_shortcode('ab_workshop_all_dates', 'ab_sc_workshop_all_dates');


/**
 * Shortcode: [ab_google_calendar_link]
 * Generiert einen Google Calendar "Add Event" Link aus den Event-Daten der Bestellung.
 */
function ab_sc_google_calendar_link() {
    global $ab_current_order;
    if (!$ab_current_order) return '';

    $item = ab_we_get_first_event_item($ab_current_order);
    if (!$item) return '';

    $title = $item->get_meta('_event_title_clean') ?: $item->get_meta('_event_title');
    $venue = $item->get_meta('_event_venue');
    $date_str = $item->get_meta('_event_date');
    $time_str = $item->get_meta('_event_time');

    if (empty($title) || empty($date_str)) return '';

    $date_obj = DateTime::createFromFormat('d-m-Y', trim($date_str));
    if (!$date_obj) {
        $date_obj = DateTime::createFromFormat('d.m.Y', trim($date_str));
    }
    if (!$date_obj) return '';

    $start_time = '09:00';
    $end_time = '10:00';
    if (!empty($time_str)) {
        $parts = preg_split('/\s*[-–]\s*/', $time_str);
        if (count($parts) >= 2) {
            $start_time = trim($parts[0]);
            $end_time = trim($parts[1]);
        } elseif (count($parts) === 1) {
            $start_time = trim($parts[0]);
            $end_dt = DateTime::createFromFormat('H:i', $start_time);
            if ($end_dt) {
                $end_dt->modify('+1 hour');
                $end_time = $end_dt->format('H:i');
            }
        }
    }

    $start_dt = clone $date_obj;
    $start_parts = explode(':', $start_time);
    $start_dt->setTime((int)($start_parts[0] ?? 9), (int)($start_parts[1] ?? 0));

    $end_dt = clone $date_obj;
    $end_parts = explode(':', $end_time);
    $end_dt->setTime((int)($end_parts[0] ?? 10), (int)($end_parts[1] ?? 0));

    $params = [
        'action'   => 'TEMPLATE',
        'text'     => $title,
        'dates'    => $start_dt->format('Ymd\THis') . '/' . $end_dt->format('Ymd\THis'),
        'ctz'      => 'Europe/Zurich',
    ];
    if (!empty($venue)) {
        $params['location'] = $venue;
    }

    return 'https://calendar.google.com/calendar/render?' . http_build_query($params);
}
add_shortcode('ab_google_calendar_link', 'ab_sc_google_calendar_link');

/**
 * Shortcode-Beschreibungen
 */
function ab_get_shortcode_descriptions() {
    return array(
        'ab_event_title'       => 'Gibt den Titel des Events zurück.',
        'ab_event_title_clean' => 'Gibt den bereinigten Titel des Events zurück.',
        'ab_event_date'        => 'Gibt das Datum des Events zurück.',
        'ab_event_time'        => 'Gibt die Uhrzeit des Events zurück.',
        'ab_event_location'    => 'Gibt den Veranstaltungsort des Events zurück.',
        'ab_event_coach'       => 'Gibt den Namen des Coaches des Events zurück.',
        'ab_event_coach_image' => 'Gibt ein Bild des Coaches zurück.',
        'ab_event_coach_phone' => 'Gibt die Telefonnummer des Coaches zurück.',
        'ab_event_venue_lat'   => 'Gibt die Breitengrad-Koordinate des Veranstaltungsorts zurück.',
        'ab_event_venue_lng'   => 'Gibt die Längengrad-Koordinate des Veranstaltungsorts zurück.',
        'ab_event_product_id'  => 'Gibt die Produkt-ID des Event-Artikels zurück.',
        'ab_event_price'       => 'Gibt den Preis des Events zurück.',
        'ab_event_availability'=> 'Gibt die Verfügbarkeit des Events zurück.',
        'ab_event_participants'=> 'Listet alle Teilnehmerdaten des Events auf.',
        'ab_event_dates'       => 'Listet alle Termine des Events auf.',
        'event_list'           => 'Rendert eine Event-Liste für das Frontend.',
        'first_participant_first_name' => 'Gibt den Vornamen des ersten Teilnehmers zurück.',
        'first_participant_last_name'  => 'Gibt den Nachnamen des ersten Teilnehmers zurück.',
        'first_participant_dob'        => 'Gibt das Geburtsdatum des ersten Teilnehmers zurück.',
        'ab_today'            => 'Gibt das heutige Datum zurück.',
        'ab_today_plus_7'     => 'Gibt das Datum in 7 Tagen zurück.',
        'ab_event_whatsapp_link' => 'Gibt den WhatsApp Link des Events zurück.',
        'ab_event_weekday'    => 'Gibt den Wochentag des Events zurück.',
        'ab_event_description' => 'Gibt die Vertrags-Beschreibung des Events zurück.',
        'contract_link' => 'Gibt den Link zum Vertragsabschluss zurück.',
        'ab_academy_username' => 'Generiert den Benutzernamen für das Academy-Login (vornamename#bestellnummer).',
        'ab_academy_password' => 'Generiert das Passwort für das Academy-Login (vornamename#event-datum).',
        'ab_rebooking_link'   => 'Zeigt den Self-Service-Umbuchungslink an (nur für Status Probetraining).',
        'ab_gutschein_code'       => 'Gibt den generierten Gutschein-Code zurück.',
        'ab_gutschein_wert'       => 'Gibt den Gutschein-Wert zurück (formatiert mit EUR).',
        'ab_gutschein_ablauf'     => 'Gibt das Ablaufdatum des Gutscheins zurück.',
        'ab_gutschein_nachricht'  => 'Gibt die persönliche Nachricht des Gutscheins zurück.',
        'ab_gutschein_empfaenger' => 'Gibt die E-Mail des Gutschein-Empfängers zurück.',
        'ab_workshop_all_dates'   => 'Zeigt alle Workshop-Termine formatiert als Liste an (Wochentag, Datum, Uhrzeit).',
        'ab_google_calendar_link' => 'Generiert einen Google Calendar Link zum Hinzufügen des Events.',

    );
}
