<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('ab_map_event_description_to_contract')) {
    function ab_map_event_description_to_contract($order_id, $event_description) {
        // Suche in ab_contract_type, wo *ab*event_description = $event_description
        $args = [
            'post_type'      => 'ab_contract_type',
            'meta_key'       => '_ab_event_description',
            'meta_value'     => $event_description,
            'posts_per_page' => 1
        ];
        $contracts = get_posts($args);
        if (!empty($contracts)) {
            $contract_id = $contracts[0]->ID;
            update_post_meta($order_id, '_ab_contract_type_id', $contract_id);
        } else {
            error_log("Kein Vertragstyp gefunden für event_description=".$event_description);
        }
    }
}

// Einfachere Funktion zum Generieren der PDF-URL
function ab_get_contract_pdf_url($order_id) {
    $token = md5('contract_' . $order_id . wp_salt());

    return add_query_arg(
        array(
            'ab_action' => 'view_contract',
            'order_id' => $order_id,
            'token' => $token
        ),
        home_url()
    );
}

// Funktion zum Ausliefern des PDFs über Query-Parameter
function ab_handle_pdf_request() {
    if (isset($_GET['ab_action']) && $_GET['ab_action'] === 'view_contract' &&
        isset($_GET['order_id']) && isset($_GET['token'])) {

        $order_id = intval($_GET['order_id']);
        $token = sanitize_text_field($_GET['token']);

        // Sicherheitsüberprüfung
        $expected_token = md5('contract_' . $order_id . wp_salt());

        if ($token === $expected_token) {
            $pdf_path = get_post_meta($order_id, '_ab_contract_pdf', true);

            if ($pdf_path && file_exists($pdf_path)) {
                // Leere den Ausgabepuffer, falls vorhanden
                if (ob_get_level()) {
                    ob_end_clean();
                }

                // Setze Header und liefere das PDF aus
                header('Content-Type: application/pdf');
                header('Content-Disposition: inline; filename="vertrag-' . $order_id . '.pdf"');
                header('Cache-Control: public, must-revalidate, max-age=0');
                header('Pragma: public');
                header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');
                header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');

                readfile($pdf_path);
                exit;
            }

            wp_die('PDF nicht gefunden.');
        } else {
            wp_die('Ungültiger Token.');
        }
    }
}
add_action('template_redirect', 'ab_handle_pdf_request', 5);


/**
 * Ermittelt den konkreten Event-Typ anhand der Angebots-Kategorie.
 * Gibt den Kategorie-Slug zurück (z.B. 'probetraining', 'workshop', 'kurs', 'ferienkurs')
 * oder 'probetraining' als Fallback.
 */
function ab_get_order_event_type($order) {
    if (is_numeric($order)) {
        $order = wc_get_order($order);
    }
    if (!$order instanceof WC_Order) {
        return 'probetraining';
    }

    // Zuerst: Kategorie-Slugs direkt vom Order-Item prüfen
    foreach ($order->get_items() as $item) {
        $event_type = $item->get_meta('_event_type');
        if (!empty($event_type)) {
            return $event_type;
        }
    }

    // Zweitens: _event_is_workshop Meta auf Order-Item prüfen (Workshop-Buchungen)
    foreach ($order->get_items() as $item) {
        $is_workshop = $item->get_meta('_event_is_workshop');
        if ($is_workshop === '1' || $is_workshop === 1) {
            return 'workshop';
        }
    }

    // Fallback: Event CPT Taxonomy prüfen
    foreach ($order->get_items() as $item) {
        $product_id = $item->get_meta('_event_product_id');
        if (!$product_id) {
            $product_id = $item->get_product_id();
        }
        if (!$product_id) {
            continue;
        }

        $event_id = get_post_meta($product_id, '_event_id', true);
        if (!$event_id) {
            continue;
        }

        $categories = wp_get_object_terms($event_id, 'event_category', array('fields' => 'all'));
        if (is_wp_error($categories) || empty($categories)) {
            continue;
        }

        $angebot_term = get_term_by('slug', 'angebot', 'event_category');
        if (!$angebot_term) {
            continue;
        }

        foreach ($categories as $cat) {
            if ((int) $cat->parent === (int) $angebot_term->term_id) {
                return $cat->slug; // z.B. 'probetraining', 'workshop', 'kurs', 'ferienkurs'
            }
        }
    }

    return 'probetraining';
}

/**
 * Mappt einen Event-Typ (Kategorie-Slug) auf den WooCommerce-Status.
 * Alles was nicht 'probetraining' ist, wird anhand der Kategorie gemappt.
 */
function ab_map_event_type_to_status($event_type) {
    $mapping = [
        'probetraining' => 'wc-probetraining',
        'workshop'      => 'wc-workshop',
        'kurs'          => 'wc-kurs',
        'ferienkurs'    => 'wc-kurs',
    ];

    return isset($mapping[$event_type]) ? $mapping[$event_type] : 'wc-workshop';
}

/**
 * Prüft ob eine Bestellung ein Workshop/Kurs ist (kein Probetraining).
 * Workshop = Event mit einer event_category unter Parent "angebot" die NICHT "probetraining" ist.
 */
function ab_order_is_workshop($order) {
    if (is_numeric($order)) {
        $order = wc_get_order($order);
    }
    if (!$order instanceof WC_Order) {
        return false;
    }

    // Zuerst prüfen, ob _event_is_workshop bereits auf dem Order Item gespeichert ist
    foreach ($order->get_items() as $item) {
        $is_workshop = $item->get_meta('_event_is_workshop');
        if ($is_workshop !== '') {
            return (bool) $is_workshop;
        }
    }

    // Fallback: Event CPT direkt prüfen
    foreach ($order->get_items() as $item) {
        $product_id = $item->get_meta('_event_product_id');
        if (!$product_id) {
            $product_id = $item->get_product_id();
        }
        if (!$product_id) {
            continue;
        }

        $event_id = get_post_meta($product_id, '_event_id', true);
        if (!$event_id) {
            continue;
        }

        // event_category Taxonomie prüfen
        $categories = wp_get_object_terms($event_id, 'event_category', array('fields' => 'all'));
        if (is_wp_error($categories) || empty($categories)) {
            continue;
        }

        // Parent-Kategorie "angebot" finden
        $angebot_term = get_term_by('slug', 'angebot', 'event_category');
        if (!$angebot_term) {
            continue;
        }

        foreach ($categories as $cat) {
            // Prüfe ob diese Kategorie ein Kind von "angebot" ist
            if ((int) $cat->parent === (int) $angebot_term->term_id) {
                // Kind von "angebot" gefunden - wenn es NICHT "probetraining" ist → Workshop
                if ($cat->slug !== 'probetraining') {
                    return true;
                }
            }
        }
    }

    return false;
}

/**
 * Holt das früheste Datum aus _event_dates des Events (für Reminder-Berechnung).
 * Gibt ein Datum im Format 'd-m-Y' zurück oder false.
 */
function ab_get_workshop_first_date($order) {
    if (is_numeric($order)) {
        $order = wc_get_order($order);
    }
    if (!$order instanceof WC_Order) {
        return false;
    }

    foreach ($order->get_items() as $item) {
        $product_id = $item->get_meta('_event_product_id');
        if (!$product_id) {
            $product_id = $item->get_product_id();
        }
        if (!$product_id) {
            continue;
        }

        $event_id = get_post_meta($product_id, '_event_id', true);
        if (!$event_id) {
            continue;
        }

        $event_dates = get_post_meta($event_id, '_event_dates', true);
        if (empty($event_dates) || !is_array($event_dates)) {
            // Fallback: einzelnes Datum vom Produkt
            $single_date = get_post_meta($product_id, '_event_date', true);
            return $single_date ?: false;
        }

        $earliest = null;
        foreach ($event_dates as $d) {
            if (empty($d['date'])) continue;
            $ts = DateTime::createFromFormat('d-m-Y', trim($d['date']));
            if ($ts && ($earliest === null || $ts < $earliest)) {
                $earliest = $ts;
            }
        }

        return $earliest ? $earliest->format('d-m-Y') : false;
    }

    return false;
}

/**
 * Holt das späteste Datum aus _event_dates des Events (für Follow-Up-Berechnung).
 * Bei mehrtägigen Workshops zählt immer das letzte Datum.
 * Gibt ein Datum im Format 'd-m-Y' zurück oder false.
 */
function ab_get_workshop_last_date($order) {
    if (is_numeric($order)) {
        $order = wc_get_order($order);
    }
    if (!$order instanceof WC_Order) {
        return false;
    }

    foreach ($order->get_items() as $item) {
        $product_id = $item->get_meta('_event_product_id');
        if (!$product_id) {
            $product_id = $item->get_product_id();
        }
        if (!$product_id) {
            continue;
        }

        $event_id = get_post_meta($product_id, '_event_id', true);
        if (!$event_id) {
            continue;
        }

        $event_dates = get_post_meta($event_id, '_event_dates', true);
        if (empty($event_dates) || !is_array($event_dates)) {
            // Fallback: einzelnes Datum vom Produkt
            $single_date = get_post_meta($product_id, '_event_date', true);
            return $single_date ?: false;
        }

        $latest = null;
        foreach ($event_dates as $d) {
            if (empty($d['date'])) continue;
            $ts = DateTime::createFromFormat('d-m-Y', trim($d['date']));
            if ($ts && ($latest === null || $ts > $latest)) {
                $latest = $ts;
            }
        }

        return $latest ? $latest->format('d-m-Y') : false;
    }

    return false;
}
