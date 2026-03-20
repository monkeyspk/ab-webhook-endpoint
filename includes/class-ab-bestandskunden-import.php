<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin-Seite für den Bestandskunden-Import per CSV-Upload.
 * Erstellt WooCommerce-Bestellungen mit Status wc-bestandskunde (kein E-Mail-Versand).
 * Schüler mit 2 Klassen werden zu EINER Bestellung zusammengeführt.
 * Verarbeitung in Batches per AJAX gegen Timeout.
 *
 * CSV-Format (Semikolon-getrennt, AcademyBoard-Export):
 * ab_id;vorname;nachname;geburtsdatum;email;klasse: status
 *
 * "Klasse: Status"-Spalte kann mehrere Paare enthalten:
 * "Adults Prenzlauer Berg (ab 30 J): Schüler_in, Originals Tiergarten: Gekündigt"
 * → Nur Einträge mit Status "Schüler_in" werden importiert.
 *
 * Matching: CSV-Klassen → Events (nach Titel), dann Vertragstyp über _event_course_id.
 */
class AB_Bestandskunden_Import {

    const BATCH_SIZE = 10;

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_menu_page']);
        add_action('wp_ajax_ab_import_batch', [__CLASS__, 'ajax_import_batch']);
        add_action('wp_ajax_ab_delete_batch', [__CLASS__, 'ajax_delete_batch']);
        add_action('wp_ajax_ab_preview_metadata', [__CLASS__, 'ajax_preview_metadata']);
    }

    public static function add_menu_page() {
        add_submenu_page(
            'parkourone',
            'Bestandskunden Import',
            'Bestandskunden Import',
            'manage_woocommerce',
            'ab-bestandskunden-import',
            [__CLASS__, 'render_page']
        );
    }

    // =========================================================================
    // CSV-Parsing
    // =========================================================================

    /**
     * CSV parsen und Zeilen zurückgeben.
     * Akzeptiert sowohl "klasse" als auch "klasse: status" als Spaltenname.
     */
    private static function parse_csv($file_path) {
        $rows = [];
        $handle = fopen($file_path, 'r');
        if (!$handle) {
            return $rows;
        }

        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $header = fgetcsv($handle, 0, ';');
        if (!$header) {
            fclose($handle);
            return $rows;
        }

        $header = array_map('trim', $header);
        $header = array_map('mb_strtolower', $header);

        // "klasse: status" → normalisieren zu "klasse"
        $klasse_col_index = null;
        $has_status_format = false;
        foreach ($header as $i => $col) {
            if ($col === 'klasse: status' || $col === 'klasse:status') {
                $header[$i] = 'klasse';
                $klasse_col_index = $i;
                $has_status_format = true;
                break;
            }
            if ($col === 'klasse') {
                $klasse_col_index = $i;
                break;
            }
        }

        $required = ['ab_id', 'vorname', 'nachname', 'geburtsdatum', 'email', 'klasse'];
        $missing = array_diff($required, $header);
        if (!empty($missing)) {
            fclose($handle);
            return new WP_Error('invalid_header', 'Fehlende Spalten: ' . implode(', ', $missing));
        }

        while (($line = fgetcsv($handle, 0, ';')) !== false) {
            if (count($line) < count($header)) continue;
            $row = array_combine($header, $line);
            if (empty(trim($row['email']))) continue;
            $row = array_map('trim', $row);

            // Markiere ob das "Klasse: Status" Format vorliegt
            $row['_has_status_format'] = $has_status_format;

            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }

    /**
     * CSV-Zeilen nach AB-ID gruppieren.
     * Parst "Klasse: Status" Format → nur Schüler_in behalten.
     * Schüler mit 2 Klassen (gleiche AB-ID oder komma-getrennt) werden zusammengeführt.
     */
    private static function group_by_student($rows) {
        $grouped = [];

        foreach ($rows as $row) {
            $ab_id = $row['ab_id'];
            $raw_klasse = $row['klasse'];
            $has_status = !empty($row['_has_status_format']);

            // Klassen aus dem Feld parsen
            $active_classes = self::parse_klasse_status($raw_klasse, $has_status);

            if (empty($active_classes)) {
                continue; // Kein aktiver Status → Student überspringen
            }

            if (!isset($grouped[$ab_id])) {
                $grouped[$ab_id] = [
                    'ab_id'        => $ab_id,
                    'vorname'      => $row['vorname'],
                    'nachname'     => $row['nachname'],
                    'geburtsdatum' => $row['geburtsdatum'],
                    'email'        => $row['email'],
                    'klassen'      => $active_classes,
                ];
            } else {
                // Weitere Klassen hinzufügen (Duplikate vermeiden)
                foreach ($active_classes as $cls) {
                    if (!in_array($cls, $grouped[$ab_id]['klassen'])) {
                        $grouped[$ab_id]['klassen'][] = $cls;
                    }
                }
            }
        }

        return array_values($grouped);
    }

    /**
     * "Klasse: Status" String parsen.
     * Input: "Adults PB (ab 30 J): Schüler_in, Originals PB Dienstag: Gekündigt"
     * Output: ["Adults PB (ab 30 J)"] (nur Schüler_in)
     *
     * Wenn kein Status-Format: gibt den Klassennamen direkt zurück.
     */
    private static function parse_klasse_status($raw, $has_status_format) {
        if (!$has_status_format) {
            $raw = trim($raw);
            return !empty($raw) ? [$raw] : [];
        }

        $active = [];

        // Komma-getrennte Paare splitten
        // Vorsicht: Klassenname kann Klammern enthalten, z.B. "Adults PB (ab 30 J): Schüler_in"
        // Split an ", " gefolgt von einem Wort und ": " (neues Paar)
        // Strategie: Split an Komma, dann prüfen ob das Teil ": Status" enthält
        $parts = explode(',', $raw);
        $pairs = [];
        $buffer = '';

        foreach ($parts as $part) {
            $buffer .= ($buffer !== '' ? ',' : '') . $part;
            // Prüfe ob der Buffer ein gültiges "Klasse: Status" Paar enthält
            // Status steht immer am Ende nach dem letzten ": "
            if (preg_match('/:\s+(Schüler_in|Gekündigt|Abgelehnt|Interessent_in|Pausiert|Ehemalig(?:e|er)?)\s*$/u', $buffer)) {
                $pairs[] = trim($buffer);
                $buffer = '';
            }
        }
        // Rest (falls kein Match)
        if ($buffer !== '') {
            $pairs[] = trim($buffer);
        }

        foreach ($pairs as $pair) {
            // Am letzten ": " splitten → Klassenname + Status
            $last_colon = mb_strrpos($pair, ': ');
            if ($last_colon === false) {
                // Kein Status gefunden → als Klassename behandeln (Fallback)
                $name = trim($pair);
                if (!empty($name)) {
                    $active[] = $name;
                }
                continue;
            }

            $class_name = trim(mb_substr($pair, 0, $last_colon));
            $status = trim(mb_substr($pair, $last_colon + 2));

            // Nur Schüler_in behalten
            if (mb_strtolower($status) === 'schüler_in' && !empty($class_name)) {
                $active[] = $class_name;
            }
        }

        return $active;
    }

    // =========================================================================
    // Event-basiertes Matching
    // =========================================================================

    /**
     * Event-Post anhand des Klassennamens finden.
     * 1. Exakter Titel-Match (publish)
     * 2. Exakter Titel-Match (any status)
     * 3. Partial-Match: Titel enthält Klassenname oder umgekehrt (publish)
     * 4. Partial-Match (any status)
     */
    private static function find_event_by_class_name($class_name) {
        $class_name_clean = trim($class_name);
        if (empty($class_name_clean)) {
            return null;
        }

        // 1. Exakter Titel-Match (publish)
        $events = get_posts([
            'post_type'      => 'event',
            'posts_per_page' => 1,
            'post_status'    => 'publish',
            'title'          => $class_name_clean,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);
        if (!empty($events)) {
            return $events[0];
        }

        // 2. Exakter Titel-Match (any status)
        $events = get_posts([
            'post_type'      => 'event',
            'posts_per_page' => 1,
            'post_status'    => 'any',
            'title'          => $class_name_clean,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);
        if (!empty($events)) {
            return $events[0];
        }

        // 3. Partial Match (publish): Event-Titel enthält Klassennamen oder umgekehrt
        $all_events = get_posts([
            'post_type'      => 'event',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);

        $class_lower = mb_strtolower($class_name_clean);
        foreach ($all_events as $ev) {
            $title_lower = mb_strtolower($ev->post_title);
            if (mb_strpos($title_lower, $class_lower) !== false || mb_strpos($class_lower, $title_lower) !== false) {
                return $ev;
            }
        }

        // 4. Partial Match (any status)
        $all_events = get_posts([
            'post_type'      => 'event',
            'posts_per_page' => -1,
            'post_status'    => 'any',
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);

        foreach ($all_events as $ev) {
            $title_lower = mb_strtolower($ev->post_title);
            if (mb_strpos($title_lower, $class_lower) !== false || mb_strpos($class_lower, $title_lower) !== false) {
                return $ev;
            }
        }

        return null;
    }

    /**
     * Alle Events laden (für Dropdown-Auswahl)
     */
    private static function get_all_events() {
        $events = get_posts([
            'post_type'      => 'event',
            'posts_per_page' => -1,
            'post_status'    => ['publish', 'draft', 'private'],
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);

        $result = [];
        foreach ($events as $ev) {
            $coach = get_post_meta($ev->ID, '_event_headcoach', true);
            $course_id = get_post_meta($ev->ID, '_event_course_id', true);
            $result[$ev->ID] = [
                'title'     => $ev->post_title,
                'status'    => $ev->post_status,
                'coach'     => $coach ?: '',
                'course_id' => $course_id ?: '',
            ];
        }
        return $result;
    }

    /**
     * Vertragstyp über die course_id eines Events finden.
     * 1. _event_course_id vom Event → _ab_course_id auf Contract Type
     * 2. Fallback: _event_description → _ab_event_description
     */
    private static function find_contract_type_for_event($event_id) {
        // 1. Primär: über course_id
        $course_id = get_post_meta($event_id, '_event_course_id', true);
        if (!empty($course_id)) {
            $contracts = get_posts([
                'post_type'      => 'ab_contract_type',
                'posts_per_page' => 1,
                'post_status'    => 'publish',
                'meta_key'       => '_ab_course_id',
                'meta_value'     => $course_id,
            ]);
            if (!empty($contracts)) {
                return $contracts[0];
            }
        }

        // 2. Fallback: über event_description
        $event_desc = get_post_meta($event_id, '_event_description', true);
        if (!empty($event_desc)) {
            $contracts = get_posts([
                'post_type'      => 'ab_contract_type',
                'posts_per_page' => 1,
                'post_status'    => 'publish',
                'meta_key'       => '_ab_event_description',
                'meta_value'     => $event_desc,
            ]);
            if (!empty($contracts)) {
                return $contracts[0];
            }
        }

        return null;
    }

    /**
     * Neuestes WooCommerce-Produkt für ein Event finden
     */
    private static function find_product_for_event($event_id) {
        $products = get_posts([
            'post_type'      => 'product',
            'posts_per_page' => 1,
            'post_status'    => 'publish',
            'meta_key'       => '_event_id',
            'meta_value'     => $event_id,
            'orderby'        => 'ID',
            'order'          => 'DESC',
        ]);

        return !empty($products) ? $products[0] : null;
    }

    // =========================================================================
    // Event-Daten auflösen (für Preview + Import)
    // =========================================================================

    /**
     * Alle Meta-Felder eines Events auflösen (identisch zu add_full_event_info_to_order).
     */
    private static function resolve_event_data($event_id) {
        $event_title = get_the_title($event_id);
        $event_title_clean = wp_strip_all_tags($event_title);
        $event_title_clean = preg_replace('/\s+/', ' ', trim($event_title_clean));

        $event_start = get_post_meta($event_id, '_event_start_time', true);
        $event_end   = get_post_meta($event_id, '_event_end_time', true);
        $event_time  = ($event_start && $event_end) ? $event_start . ' - ' . $event_end : '';

        // Venue-Daten aus _event_dates
        $event_venue = '';
        $event_venue_lat = '';
        $event_venue_lng = '';
        $event_date = '';
        $event_dates = get_post_meta($event_id, '_event_dates', true);

        if (!empty($event_dates) && is_array($event_dates)) {
            // Nächstes bevorstehendes Datum oder erstes verfügbares
            $now = time();
            $best = null;
            foreach ($event_dates as $d) {
                if (empty($d['date'])) continue;
                $ts = DateTime::createFromFormat('d-m-Y', trim($d['date']));
                if ($ts && $ts->getTimestamp() >= $now && (!$best || $ts < $best['ts'])) {
                    $best = ['date' => $d, 'ts' => $ts];
                }
            }
            $matched_date = $best ? $best['date'] : reset($event_dates);

            if ($matched_date) {
                $event_date      = isset($matched_date['date']) ? $matched_date['date'] : '';
                $event_venue     = isset($matched_date['venue']) ? $matched_date['venue'] : '';
                $event_venue_lat = isset($matched_date['venue_lat']) ? $matched_date['venue_lat'] : '';
                $event_venue_lng = isset($matched_date['venue_lng']) ? $matched_date['venue_lng'] : '';
            }
        }

        // Produkt-Datum überschreibt falls vorhanden
        $product_post = self::find_product_for_event($event_id);
        $product_id = 0;
        if ($product_post) {
            $product_id = $product_post->ID;
            $product_date = get_post_meta($product_post->ID, '_event_date', true);
            if ($product_date) {
                $event_date = $product_date;
            }
        }

        return [
            '_event_title'          => $event_title,
            '_event_title_clean'    => $event_title_clean,
            '_event_date'           => $event_date,
            '_event_time'           => $event_time,
            '_event_venue'          => $event_venue,
            '_event_venue_lat'      => $event_venue_lat,
            '_event_venue_lng'      => $event_venue_lng,
            '_event_coach'          => get_post_meta($event_id, '_event_headcoach', true),
            '_event_coach_image'    => get_post_meta($event_id, '_event_headcoach_image_url', true),
            '_event_coach_phone'    => get_post_meta($event_id, '_event_headcoach_phone', true),
            '_event_coach_email'    => get_post_meta($event_id, '_event_headcoach_email', true),
            '_event_description'    => get_post_meta($event_id, '_event_description', true),
            '_event_course_id'      => get_post_meta($event_id, '_event_course_id', true),
            '_event_whatsapp_link'  => get_post_meta($event_id, '_event_whatsapp_link', true),
            '_event_is_workshop'    => '0',
            '_event_product_id'     => $product_id ?: '',
        ];
    }

    // =========================================================================
    // Order Creation (Event-first)
    // =========================================================================

    /**
     * Produkt-Line-Item mit Event-Daten zur Bestellung hinzufügen.
     * Event-ID als primärer Input (nicht Vertragstyp).
     */
    private static function add_event_line_item($order, $event_id, $contract_type_id, $vorname, $nachname, $geburtsdatum) {
        $meta_fields = self::resolve_event_data($event_id);

        $event_title = $meta_fields['_event_title'];
        $event_date = $meta_fields['_event_date'];
        $product_id = $meta_fields['_event_product_id'];

        // Preis vom Vertragstyp
        $price = 0;
        if ($contract_type_id) {
            $price = get_post_meta($contract_type_id, '_ab_vertrag_preis', true);
        }

        // WooCommerce Produkt
        $product = null;
        if ($product_id) {
            $product = wc_get_product($product_id);
        }

        // Item-Name: Event-Titel + Datum
        $item_name = $event_title ?: 'Bestandskunde';

        $item = new WC_Order_Item_Product();
        $item->set_name($item_name . ($event_date ? ' - ' . $event_date : ''));
        $item->set_quantity(1);
        $item->set_total($price ?: 0);
        $item->set_subtotal($price ?: 0);

        if ($product) {
            $item->set_product($product);
        }

        // Teilnehmerdaten
        $item->add_meta_data('_event_participant_data', [[
            'vorname'      => $vorname,
            'name'         => $nachname,
            'geburtsdatum' => $geburtsdatum,
        ]]);

        // Alle Event-Meta-Felder
        foreach ($meta_fields as $key => $value) {
            if (!empty($value)) {
                $item->add_meta_data($key, $value);
            }
        }

        $order->add_item($item);
    }

    // =========================================================================
    // AJAX: Metadaten-Vorschau
    // =========================================================================

    /**
     * AJAX: Metadaten-Vorschau — nimmt class_event_mapping (Klasse → Event-ID)
     * und zeigt pro Event alle Daten + gefundenen Vertragstyp.
     */
    public static function ajax_preview_metadata() {
        check_ajax_referer('ab_bestandskunden_import', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Keine Berechtigung.');
        }

        $class_event_mapping = isset($_POST['class_event_mapping']) ? json_decode(stripslashes($_POST['class_event_mapping']), true) : [];

        if (empty($class_event_mapping)) {
            wp_send_json_error('Kein Mapping.');
        }

        $result = [];
        $resolved_event_ids = [];

        foreach ($class_event_mapping as $klasse => $event_id) {
            $event_id = intval($event_id);
            if ($event_id <= 0) continue;

            if (in_array($event_id, $resolved_event_ids)) {
                // Klasse zum bestehenden Eintrag hinzufügen
                foreach ($result as &$entry) {
                    if ($entry['event_id'] === $event_id) {
                        $entry['klassen'][] = $klasse;
                        break;
                    }
                }
                unset($entry);
                continue;
            }
            $resolved_event_ids[] = $event_id;

            $event_post = get_post($event_id);
            $meta_fields = self::resolve_event_data($event_id);

            // Vertragstyp finden
            $contract_type = self::find_contract_type_for_event($event_id);
            $ct_id = $contract_type ? $contract_type->ID : 0;
            $ct_title = $contract_type ? $contract_type->post_title : '';
            $ct_price = $contract_type ? get_post_meta($contract_type->ID, '_ab_vertrag_preis', true) : '';

            $data = [
                'event_id'        => $event_id,
                'event_title'     => $event_post ? $event_post->post_title : 'NICHT GEFUNDEN',
                'event_status'    => $event_post ? $event_post->post_status : '',
                'klassen'         => [$klasse],
                'ct_found'        => !empty($ct_id),
                'ct_id'           => $ct_id,
                'ct_title'        => $ct_title,
                'ct_price'        => $ct_price,
                'course_id'       => $meta_fields['_event_course_id'] ?: '',
                'event_coach'     => $meta_fields['_event_coach'] ?: '',
                'event_coach_email' => $meta_fields['_event_coach_email'] ?: '',
                'event_coach_phone' => $meta_fields['_event_coach_phone'] ?: '',
                'event_time'      => $meta_fields['_event_time'] ?: '',
                'event_venue'     => $meta_fields['_event_venue'] ?: '',
                'event_date'      => $meta_fields['_event_date'] ?: '',
                'event_description' => $meta_fields['_event_description'] ?: '',
                'event_whatsapp'  => $meta_fields['_event_whatsapp_link'] ?: '',
                'product_id'      => $meta_fields['_event_product_id'] ?: '',
                'meta_fields'     => $meta_fields,
            ];

            $result[] = $data;
        }

        wp_send_json_success($result);
    }

    // =========================================================================
    // AJAX: Import Batch
    // =========================================================================

    /**
     * AJAX: Einen Batch importieren (Event-first)
     */
    public static function ajax_import_batch() {
        check_ajax_referer('ab_bestandskunden_import', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Keine Berechtigung.');
        }

        $students = isset($_POST['students']) ? json_decode(stripslashes($_POST['students']), true) : [];
        $class_event_mapping = isset($_POST['class_event_mapping']) ? json_decode(stripslashes($_POST['class_event_mapping']), true) : [];

        if (empty($students) || !is_array($students)) {
            wp_send_json_error('Keine Daten.');
        }

        $created = 0;
        $skipped = 0;
        $errors  = 0;
        $messages = [];

        // Alle WooCommerce-Emails während des Imports komplett blockieren
        add_filter('woocommerce_email_enabled_customer_on_hold_order', '__return_false');
        add_filter('woocommerce_email_enabled_customer_processing_order', '__return_false');
        add_filter('woocommerce_email_enabled_customer_completed_order', '__return_false');
        add_filter('woocommerce_email_enabled_customer_note', '__return_false');
        add_filter('woocommerce_email_enabled_new_order', '__return_false');

        foreach ($students as $student) {
            $ab_id        = $student['ab_id'];
            $vorname      = $student['vorname'];
            $nachname     = $student['nachname'];
            $geburtsdatum = $student['geburtsdatum'];
            $email        = $student['email'];
            $klassen      = $student['klassen'];

            // Event-IDs und Vertragstyp-IDs aus dem Mapping holen
            $event_ids = [];
            $contract_type_ids = [];
            $klassen_names = [];

            foreach ($klassen as $klasse) {
                $ev_id = isset($class_event_mapping[$klasse]) ? intval($class_event_mapping[$klasse]) : 0;
                if ($ev_id > 0) {
                    $event_ids[] = $ev_id;
                    $klassen_names[] = $klasse;

                    // Vertragstyp über Event finden
                    $ct = self::find_contract_type_for_event($ev_id);
                    $contract_type_ids[] = $ct ? $ct->ID : 0;
                }
            }

            if (empty($event_ids)) {
                $skipped++;
                continue;
            }

            // Duplikat-Check: Gibt es bereits eine Bestellung mit dieser AB-ID?
            $existing = get_posts([
                'post_type'   => 'shop_order',
                'meta_key'    => '_ab_bestandskunde_id',
                'meta_value'  => $ab_id,
                'post_status' => 'any',
                'numberposts' => 1,
            ]);

            if (!empty($existing)) {
                $skipped++;
                continue;
            }

            try {
                $order = wc_create_order(['status' => 'bestandskunde']);

                if (is_wp_error($order)) {
                    $errors++;
                    $messages[] = "$vorname $nachname: " . $order->get_error_message();
                    continue;
                }

                $order->set_billing_first_name($vorname);
                $order->set_billing_last_name($nachname);
                $order->set_billing_email($email);

                // Line Items mit Event-Daten für jede Klasse
                foreach ($event_ids as $idx => $ev_id) {
                    $ct_id = isset($contract_type_ids[$idx]) ? $contract_type_ids[$idx] : 0;
                    self::add_event_line_item($order, $ev_id, $ct_id, $vorname, $nachname, $geburtsdatum);
                }

                // Primärer Vertragstyp (für den Wizard)
                $primary_ct = array_filter($contract_type_ids);
                if (!empty($primary_ct)) {
                    $ct_values = array_values($primary_ct);
                    $order->update_meta_data('_ab_contract_type_id', $ct_values[0]);

                    if (isset($ct_values[1])) {
                        $order->update_meta_data('_ab_contract_type_id_2', $ct_values[1]);
                    }
                }

                $order->update_meta_data('_ab_bestandskunde_id', $ab_id);
                $order->update_meta_data('_ab_bestandskunde_klassen', implode(', ', $klassen_names));

                // Individueller Preis (für Doppelklassen-Schüler)
                if (!empty($student['custom_price'])) {
                    $order->update_meta_data('_ab_custom_price', sanitize_text_field($student['custom_price']));
                }

                $user = get_user_by('email', $email);
                if ($user) {
                    $order->set_customer_id($user->ID);
                }

                $order->save();
                $created++;

            } catch (Exception $e) {
                $errors++;
                $messages[] = "$vorname $nachname: " . $e->getMessage();
            }
        }

        // Email-Filter wieder entfernen
        remove_filter('woocommerce_email_enabled_customer_on_hold_order', '__return_false');
        remove_filter('woocommerce_email_enabled_customer_processing_order', '__return_false');
        remove_filter('woocommerce_email_enabled_customer_completed_order', '__return_false');
        remove_filter('woocommerce_email_enabled_customer_note', '__return_false');
        remove_filter('woocommerce_email_enabled_new_order', '__return_false');

        wp_send_json_success([
            'created'  => $created,
            'skipped'  => $skipped,
            'errors'   => $errors,
            'messages' => $messages,
        ]);
    }

    // =========================================================================
    // AJAX: Delete Batch
    // =========================================================================

    /**
     * AJAX: Batch von Bestandskunden-Bestellungen löschen
     */
    public static function ajax_delete_batch() {
        check_ajax_referer('ab_bestandskunden_import', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Keine Berechtigung.');
        }

        $order_ids = isset($_POST['order_ids']) ? json_decode(stripslashes($_POST['order_ids']), true) : [];

        if (empty($order_ids)) {
            wp_send_json_error('Keine Order-IDs.');
        }

        $deleted = 0;
        foreach ($order_ids as $order_id) {
            if (wp_delete_post(intval($order_id), true)) {
                $deleted++;
            }
        }

        wp_send_json_success(['deleted' => $deleted]);
    }

    // =========================================================================
    // Admin-Seite
    // =========================================================================

    /**
     * Admin-Seite rendern
     */
    public static function render_page() {
        // Schritt 2: Vorschau nach CSV-Upload
        if (isset($_POST['ab_bestandskunden_import_action']) && $_POST['ab_bestandskunden_import_action'] === 'preview') {
            check_admin_referer('ab_bestandskunden_import');
            self::render_preview();
            return;
        }

        // Schritt 1: Upload-Formular
        self::render_upload_form();
    }

    /**
     * Schritt 1: Upload-Formular
     */
    private static function render_upload_form() {
        ?>
        <div class="wrap">
            <h1>Bestandskunden Import</h1>

            <div class="card" style="max-width: 700px; padding: 20px;">
                <h2>CSV-Datei hochladen</h2>
                <p>Importiere Bestandskunden als WooCommerce-Bestellungen mit Status <code>Bestandskunde</code>.<br>
                <strong>Es wird kein E-Mail-Versand ausgelöst.</strong></p>
                <p>Schüler mit mehreren Klassen werden automatisch zu <strong>einer Bestellung</strong> zusammengeführt.</p>

                <h3>CSV-Format (AcademyBoard-Export)</h3>
                <p>Die CSV-Datei muss <strong>Semikolon-getrennt</strong> sein und folgende Spalten enthalten:</p>
                <table class="widefat" style="max-width: 600px;">
                    <thead>
                        <tr>
                            <th>Spalte</th>
                            <th>Beschreibung</th>
                            <th>Beispiel</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td><code>ab_id</code></td><td>AcademyBoard-ID</td><td>237</td></tr>
                        <tr><td><code>vorname</code></td><td>Vorname</td><td>Max</td></tr>
                        <tr><td><code>nachname</code></td><td>Nachname</td><td>Mustermann</td></tr>
                        <tr><td><code>geburtsdatum</code></td><td>Geburtsdatum</td><td>15.03.2005</td></tr>
                        <tr><td><code>email</code></td><td>E-Mail-Adresse</td><td>max@example.com</td></tr>
                        <tr><td><code>klasse: status</code></td><td>Klasse(n) mit Status</td><td>Adults PB: Schüler_in</td></tr>
                    </tbody>
                </table>
                <p style="color: #666; margin-top: 10px;">
                    Die Spalte <code>Klasse: Status</code> kann mehrere Einträge enthalten:<br>
                    <code>Adults PB: Schüler_in, Originals Tiergarten: Gekündigt</code><br>
                    Nur Einträge mit Status <strong>Schüler_in</strong> werden importiert.
                    Auch die alte Spalte <code>klasse</code> (ohne Status) wird unterstützt.
                </p>

                <form method="post" enctype="multipart/form-data" style="margin-top: 20px;">
                    <?php wp_nonce_field('ab_bestandskunden_import'); ?>
                    <input type="hidden" name="ab_bestandskunden_import_action" value="preview">

                    <p>
                        <input type="file" name="ab_csv_file" accept=".csv" required>
                    </p>
                    <p class="submit">
                        <input type="submit" class="button button-primary" value="CSV hochladen & Vorschau">
                    </p>
                </form>
            </div>

            <?php
            // Bestandskunden-Bestellungen suchen (alle relevanten Status)
            $bestandskunden_orders = get_posts([
                'post_type'   => 'shop_order',
                'post_status' => ['wc-bestandskunde', 'wc-bkdvertrag', 'wc-bestandkundeakz'],
                'meta_key'    => '_ab_bestandskunde_id',
                'numberposts' => -1,
                'fields'      => 'ids',
            ]);

            if (!empty($bestandskunden_orders)):
                $delete_nonce = wp_create_nonce('ab_bestandskunden_import');
            ?>

            <!-- Lösch-Tool -->
            <div class="card" style="max-width: 700px; padding: 20px; margin-top: 20px; border-left: 4px solid #d63638;">
                <h2>Bestehende Bestellungen löschen & neu importieren</h2>
                <p><strong><?php echo count($bestandskunden_orders); ?></strong> Bestandskunden-Bestellungen vorhanden.
                Lösche alle und importiere die CSV dann erneut.</p>

                <div id="ab-delete-progress" style="display: none; margin-top: 15px;">
                    <div style="background: #f0f0f1; border-radius: 4px; overflow: hidden; height: 24px;">
                        <div id="ab-delete-bar" style="background: #d63638; height: 100%; width: 0%; transition: width 0.3s; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 12px;">0%</div>
                    </div>
                    <p id="ab-delete-text" style="margin-top: 5px; color: #666; font-size: 12px;">Lösche…</p>
                </div>

                <div id="ab-delete-result" style="display: none; margin-top: 15px;"></div>

                <p class="submit" id="ab-delete-buttons">
                    <button type="button" id="ab-start-delete" class="button button-secondary" style="color: #d63638; border-color: #d63638;">
                        Alle <?php echo count($bestandskunden_orders); ?> Bestandskunden-Bestellungen löschen
                    </button>
                </p>
            </div>

            <script>
            (function() {
                var orderIds = <?php echo wp_json_encode(array_map('intval', $bestandskunden_orders)); ?>;
                var nonce = <?php echo wp_json_encode($delete_nonce); ?>;
                var batchSize = <?php echo self::BATCH_SIZE; ?>;
                var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;

                document.getElementById('ab-start-delete').addEventListener('click', function() {
                    if (!confirm('ACHTUNG: Alle ' + orderIds.length + ' Bestandskunden-Bestellungen werden unwiderruflich gelöscht. Fortfahren?')) return;

                    document.getElementById('ab-delete-buttons').style.display = 'none';
                    document.getElementById('ab-delete-progress').style.display = 'block';

                    var batches = [];
                    for (var i = 0; i < orderIds.length; i += batchSize) {
                        batches.push(orderIds.slice(i, i + batchSize));
                    }

                    var totalDeleted = 0;
                    var batchIndex = 0;

                    function nextBatch() {
                        if (batchIndex >= batches.length) { showDone(); return; }

                        var pct = Math.round((batchIndex / batches.length) * 100);
                        document.getElementById('ab-delete-bar').style.width = pct + '%';
                        document.getElementById('ab-delete-bar').textContent = pct + '%';
                        document.getElementById('ab-delete-text').textContent = totalDeleted + ' von ' + orderIds.length + ' gelöscht…';

                        var fd = new FormData();
                        fd.append('action', 'ab_delete_batch');
                        fd.append('nonce', nonce);
                        fd.append('order_ids', JSON.stringify(batches[batchIndex]));

                        fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                            .then(function(r) { return r.json(); })
                            .then(function(data) {
                                if (data.success) totalDeleted += data.data.deleted;
                                batchIndex++;
                                nextBatch();
                            })
                            .catch(function() { batchIndex++; nextBatch(); });
                    }

                    function showDone() {
                        document.getElementById('ab-delete-bar').style.width = '100%';
                        document.getElementById('ab-delete-bar').textContent = '100%';
                        document.getElementById('ab-delete-bar').style.background = '#00a32a';
                        document.getElementById('ab-delete-text').textContent = 'Fertig!';
                        document.getElementById('ab-delete-result').innerHTML = '<p style="color: #00a32a;"><strong>' + totalDeleted + ' Bestellungen gelöscht.</strong> Du kannst jetzt die CSV neu hochladen.</p>';
                        document.getElementById('ab-delete-result').style.display = 'block';
                    }

                    nextBatch();
                });
            })();
            </script>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Schritt 2: Vorschau mit Event-Mapping + Import-Button
     */
    private static function render_preview() {
        if (!isset($_FILES['ab_csv_file']) || $_FILES['ab_csv_file']['error'] !== UPLOAD_ERR_OK) {
            echo '<div class="notice notice-error"><p>Fehler beim Hochladen der CSV-Datei.</p></div>';
            self::render_upload_form();
            return;
        }

        $rows = self::parse_csv($_FILES['ab_csv_file']['tmp_name']);

        if (is_wp_error($rows)) {
            echo '<div class="notice notice-error"><p>' . esc_html($rows->get_error_message()) . '</p></div>';
            self::render_upload_form();
            return;
        }

        if (empty($rows)) {
            echo '<div class="notice notice-error"><p>Keine gültigen Zeilen in der CSV gefunden.</p></div>';
            self::render_upload_form();
            return;
        }

        // Zusammenführen: gleiche AB-ID → 1 Student mit mehreren Klassen
        $students = self::group_by_student($rows);

        if (empty($students)) {
            echo '<div class="notice notice-error"><p>Keine Schüler mit Status "Schüler_in" gefunden. Nur aktive Schüler werden importiert.</p></div>';
            self::render_upload_form();
            return;
        }

        $multi_class_count = count(array_filter($students, function($s) { return count($s['klassen']) > 1; }));

        // Alle einzigartigen Klassen sammeln
        $unique_classes = [];
        foreach ($students as $s) {
            foreach ($s['klassen'] as $k) {
                if (!in_array($k, $unique_classes)) {
                    $unique_classes[] = $k;
                }
            }
        }
        sort($unique_classes);

        // Auto-Match: Klassen → Events
        $class_event_mapping = [];
        foreach ($unique_classes as $klasse) {
            $event = self::find_event_by_class_name($klasse);
            $class_event_mapping[$klasse] = $event ? $event->ID : 0;
        }

        $matched_count = count(array_filter($class_event_mapping));
        $unmatched_count = count($class_event_mapping) - $matched_count;

        // Alle Events für Dropdown laden
        $all_events = self::get_all_events();

        $nonce = wp_create_nonce('ab_bestandskunden_import');

        // Gefilterte Zeilen zählen (für Info)
        $total_csv_rows = count($rows);
        $filtered_info = '';
        if (!empty($rows[0]['_has_status_format'])) {
            $filtered_info = ' (nur Schüler_in, andere Status gefiltert)';
        }

        ?>
        <div class="wrap">
            <h1>Bestandskunden Import — Vorschau</h1>

            <div class="notice notice-info">
                <p>
                    <strong><?php echo $total_csv_rows; ?> CSV-Zeilen</strong> gelesen →
                    <strong><?php echo count($students); ?> aktive Schüler</strong><?php echo $filtered_info; ?>
                    <?php if ($multi_class_count > 0): ?>
                        (davon <strong><?php echo $multi_class_count; ?> mit 2+ Klassen</strong>)
                    <?php endif; ?>
                    — <strong><?php echo count($unique_classes); ?> verschiedene Klassen</strong>.
                    <?php if ($unmatched_count > 0): ?>
                        <br><span style="color: #d63638;"><?php echo $unmatched_count; ?> Klasse(n) ohne automatisches Event-Match — bitte unten manuell zuweisen.</span>
                    <?php else: ?>
                        <br><span style="color: #00a32a;">Alle Klassen automatisch einem Event zugeordnet.</span>
                    <?php endif; ?>
                </p>
            </div>

            <!-- Klassen → Event Mapping -->
            <h2>Klassen → Event Zuordnung</h2>
            <p>Prüfe die automatische Zuordnung und korrigiere bei Bedarf. Vertragstyp wird automatisch über die Course-ID ermittelt.</p>

            <table class="wp-list-table widefat fixed striped" style="max-width: 1000px;">
                <thead>
                    <tr>
                        <th style="width: 50px;">Status</th>
                        <th>Klasse (CSV)</th>
                        <th style="width: 70px;">Anzahl</th>
                        <th>Event</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($unique_classes as $klasse):
                        $count = 0;
                        foreach ($students as $s) {
                            if (in_array($klasse, $s['klassen'])) $count++;
                        }
                        $matched_event_id = $class_event_mapping[$klasse];
                        $is_matched = $matched_event_id > 0;
                    ?>
                        <tr>
                            <td style="text-align: center; font-size: 18px;">
                                <?php echo $is_matched ? '<span style="color: #00a32a;">&#10003;</span>' : '<span style="color: #d63638;">&#10007;</span>'; ?>
                            </td>
                            <td><strong><?php echo esc_html($klasse); ?></strong></td>
                            <td><?php echo $count; ?></td>
                            <td>
                                <select class="ab-event-select" data-klasse="<?php echo esc_attr($klasse); ?>" style="width: 100%;">
                                    <option value="0" style="color: #d63638;">— Nicht importieren —</option>
                                    <?php foreach ($all_events as $ev_id => $ev): ?>
                                        <option value="<?php echo $ev_id; ?>"
                                            <?php selected($matched_event_id, $ev_id); ?>>
                                            <?php echo esc_html($ev['title']); ?>
                                            <?php echo $ev['coach'] ? ' [' . esc_html($ev['coach']) . ']' : ''; ?>
                                            <?php echo $ev['course_id'] ? ' (CID: ' . esc_html($ev['course_id']) . ')' : ''; ?>
                                            <?php echo $ev['status'] !== 'publish' ? ' [' . $ev['status'] . ']' : ''; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Schritt 2: Metadaten prüfen -->
            <p class="submit" id="ab-preview-buttons">
                <button type="button" id="ab-check-metadata" class="button button-primary button-hero">
                    Metadaten prüfen →
                </button>
                <span id="ab-preview-spinner" class="spinner" style="float: none; margin-top: 6px;"></span>
            </p>

            <!-- Metadaten-Vorschau (versteckt bis Prüfung) -->
            <div id="ab-metadata-preview" style="display: none; margin-top: 20px;">
                <h2>Metadaten-Vorschau — diese Daten werden pro Klasse geschrieben</h2>
                <div id="ab-metadata-content"></div>
            </div>

            <!-- Daten-Vorschau -->
            <div id="ab-student-preview" style="display: none; margin-top: 30px;">
                <h2>Schüler-Vorschau (erste 20)</h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 60px;">AB-ID</th>
                            <th>Vorname</th>
                            <th>Nachname</th>
                            <th style="width: 110px;">Geburtsdatum</th>
                            <th>E-Mail</th>
                            <th>Klasse(n)</th>
                            <th style="width: 110px;">Ind. Preis</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($students, 0, 20) as $s): ?>
                            <tr>
                                <td><?php echo esc_html($s['ab_id']); ?></td>
                                <td><?php echo esc_html($s['vorname']); ?></td>
                                <td><?php echo esc_html($s['nachname']); ?></td>
                                <td><?php echo esc_html($s['geburtsdatum']); ?></td>
                                <td><?php echo esc_html($s['email']); ?></td>
                                <td>
                                    <?php foreach ($s['klassen'] as $k): ?>
                                        <span style="display: inline-block; background: #f0f0f1; padding: 2px 8px; border-radius: 3px; margin: 1px 0; font-size: 12px;"><?php echo esc_html($k); ?></span><br>
                                    <?php endforeach; ?>
                                </td>
                                <td>
                                    <?php if (count($s['klassen']) > 1): ?>
                                        <input type="text" class="ab-custom-price" data-ab-id="<?php echo esc_attr($s['ab_id']); ?>" placeholder="z.B. 128" style="width: 90px; background: #fff8e1; border-color: #ffe082;" />
                                    <?php else: ?>
                                        <span style="color: #999;">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (count($students) > 20): ?>
                            <tr><td colspan="7" style="text-align: center; color: #666;">… und <?php echo count($students) - 20; ?> weitere Schüler</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <?php
                // Alle Doppelklassen-Schüler sammeln
                $multi_class_students = array_filter($students, function($s) { return count($s['klassen']) > 1; });
                if (!empty($multi_class_students)):
                ?>
                <div style="margin-top: 30px; padding: 20px; background: #fff8e1; border: 1px solid #ffe082; border-radius: 4px;">
                    <h3 style="margin-top: 0;">Individuelle Preise — Doppelklassen (<?php echo count($multi_class_students); ?> Schüler)</h3>
                    <p style="color: #666; margin-bottom: 15px;">Diese Schüler besuchen 2+ Klassen. Trage hier den individuellen Monatspreis ein. Leer = Standard-Vertragspreis.</p>
                    <table class="wp-list-table widefat fixed striped" style="max-width: 800px;">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Klassen</th>
                                <th style="width: 120px;">Ind. Preis</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($multi_class_students as $s): ?>
                                <tr>
                                    <td><strong><?php echo esc_html($s['vorname'] . ' ' . $s['nachname']); ?></strong></td>
                                    <td>
                                        <?php foreach ($s['klassen'] as $k): ?>
                                            <span style="display: inline-block; background: #f0f0f1; padding: 2px 8px; border-radius: 3px; margin: 1px 2px; font-size: 12px;"><?php echo esc_html($k); ?></span>
                                        <?php endforeach; ?>
                                    </td>
                                    <td>
                                        <input type="text" class="ab-custom-price" data-ab-id="<?php echo esc_attr($s['ab_id']); ?>" placeholder="z.B. 128" style="width: 100px; background: #fff; border-color: #ffe082;" />
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <div style="margin-top: 20px; padding: 15px; background: #fff3cd; border-left: 4px solid #ffc107;">
                    <strong>Hinweis:</strong> Es werden <strong><?php echo count($students); ?> Bestellungen</strong> erstellt
                    (<?php echo $multi_class_count; ?> davon mit 2+ Klassen).
                    Status: <code>Bestandskunde</code>. <strong>Keine E-Mail.</strong>
                    Duplikate (gleiche AB-ID) werden übersprungen.
                </div>
            </div>

            <!-- Fortschrittsbalken (versteckt bis Import startet) -->
            <div id="ab-import-progress" style="display: none; margin-top: 20px; max-width: 600px;">
                <h3>Import läuft…</h3>
                <div style="background: #f0f0f1; border-radius: 4px; overflow: hidden; height: 30px;">
                    <div id="ab-progress-bar" style="background: #2271b1; height: 100%; width: 0%; transition: width 0.3s; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 13px;">
                        0%
                    </div>
                </div>
                <p id="ab-progress-text" style="margin-top: 8px; color: #666;">Starte…</p>
            </div>

            <!-- Ergebnis (versteckt bis Import fertig) -->
            <div id="ab-import-result" style="display: none; margin-top: 20px;"></div>

            <!-- Import-Button (versteckt bis Metadaten geprüft) -->
            <p class="submit" id="ab-import-buttons" style="display: none;">
                <a href="<?php echo esc_url(admin_url('admin.php?page=ab-bestandskunden-import')); ?>" class="button">Abbrechen</a>
                <button type="button" id="ab-start-import" class="button button-primary button-hero">
                    Import starten (<?php echo count($students); ?> Schüler)
                </button>
            </p>
        </div>

        <script>
        (function() {
            var students = <?php echo wp_json_encode($students); ?>;
            var nonce = <?php echo wp_json_encode($nonce); ?>;
            var batchSize = <?php echo self::BATCH_SIZE; ?>;
            var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var ordersUrl = <?php echo wp_json_encode(admin_url('edit.php?post_type=shop_order&post_status=wc-bestandskunde')); ?>;
            var importUrl = <?php echo wp_json_encode(admin_url('admin.php?page=ab-bestandskunden-import')); ?>;

            // Hilfsfunktion: aktuelles Event-Mapping aus Dropdowns lesen
            function getClassEventMapping() {
                var mapping = {};
                document.querySelectorAll('.ab-event-select').forEach(function(select) {
                    mapping[select.getAttribute('data-klasse')] = parseInt(select.value) || 0;
                });
                return mapping;
            }

            // Status-Icons bei Dropdown-Änderung aktualisieren
            document.querySelectorAll('.ab-event-select').forEach(function(select) {
                select.addEventListener('change', function() {
                    var statusCell = this.closest('tr').querySelector('td:first-child');
                    if (parseInt(this.value) > 0) {
                        statusCell.innerHTML = '<span style="color: #00a32a;">&#10003;</span>';
                    } else {
                        statusCell.innerHTML = '<span style="color: #d63638;">&#10007;</span>';
                    }
                });
            });

            // ========================================
            // SCHRITT 2: Metadaten prüfen
            // ========================================
            document.getElementById('ab-check-metadata').addEventListener('click', function() {
                var btn = this;
                var spinner = document.getElementById('ab-preview-spinner');
                var classEventMapping = getClassEventMapping();

                // Prüfe ob mindestens ein Event zugeordnet ist
                var hasMapping = Object.values(classEventMapping).some(function(v) { return v > 0; });
                if (!hasMapping) {
                    alert('Bitte mindestens eine Klasse einem Event zuordnen.');
                    return;
                }

                btn.disabled = true;
                spinner.classList.add('is-active');

                var fd = new FormData();
                fd.append('action', 'ab_preview_metadata');
                fd.append('nonce', nonce);
                fd.append('class_event_mapping', JSON.stringify(classEventMapping));

                fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        btn.disabled = false;
                        spinner.classList.remove('is-active');

                        if (!data.success) {
                            alert('Fehler: ' + (data.data || 'Unbekannt'));
                            return;
                        }

                        renderMetadataPreview(data.data);
                        document.getElementById('ab-metadata-preview').style.display = 'block';
                        document.getElementById('ab-student-preview').style.display = 'block';
                        document.getElementById('ab-import-buttons').style.display = 'block';

                        // Zum Ergebnis scrollen
                        document.getElementById('ab-metadata-preview').scrollIntoView({ behavior: 'smooth' });
                    })
                    .catch(function(err) {
                        btn.disabled = false;
                        spinner.classList.remove('is-active');
                        alert('Netzwerkfehler: ' + err.message);
                    });
            });

            function renderMetadataPreview(entries) {
                var container = document.getElementById('ab-metadata-content');
                var html = '';
                var allOk = true;

                entries.forEach(function(entry) {
                    var hasCt = entry.ct_found;
                    var statusColor = hasCt ? '#00a32a' : '#ff8c00';
                    var statusText = hasCt ? 'Vertragstyp gefunden' : 'KEIN VERTRAGSTYP';
                    if (!hasCt) allOk = false;

                    html += '<div class="card" style="max-width: 900px; padding: 15px; margin-bottom: 15px; border-left: 4px solid ' + statusColor + ';">';
                    html += '<h3 style="margin-top: 0;">Event #' + entry.event_id + ': ' + escHtml(entry.event_title) + '</h3>';
                    html += '<p style="margin: 5px 0;"><strong>CSV-Klassen:</strong> ' + entry.klassen.map(function(k) {
                        return '<span style="background: #f0f0f1; padding: 2px 8px; border-radius: 3px; font-size: 12px;">' + escHtml(k) + '</span>';
                    }).join(' ') + '</p>';

                    // Event-Details
                    html += '<table class="widefat" style="max-width: 500px; margin: 10px 0;">';
                    html += '<tr><td><strong>Coach</strong></td><td>' + escHtml(entry.event_coach || '—') + '</td></tr>';
                    html += '<tr><td><strong>Venue</strong></td><td>' + escHtml(entry.event_venue || '—') + '</td></tr>';
                    html += '<tr><td><strong>Zeit</strong></td><td>' + escHtml(entry.event_time || '—') + '</td></tr>';
                    html += '<tr><td><strong>Datum</strong></td><td>' + escHtml(entry.event_date || '—') + '</td></tr>';
                    html += '<tr><td><strong>Course-ID</strong></td><td>' + (entry.course_id || '<em style="color:#d63638;">nicht gesetzt</em>') + '</td></tr>';
                    html += '</table>';

                    // Vertragstyp-Info
                    html += '<p style="margin: 5px 0; color: ' + statusColor + ';"><strong>' + statusText + '</strong>';
                    if (hasCt) {
                        html += ' — #' + entry.ct_id + ': ' + escHtml(entry.ct_title);
                        if (entry.ct_price) html += ' (' + entry.ct_price + ' €)';
                    }
                    html += '</p>';

                    if (entry.product_id) {
                        html += '<p style="margin: 5px 0;"><strong>WC-Produkt:</strong> #' + entry.product_id + '</p>';
                    }

                    // Meta-Felder Tabelle
                    html += '<details style="margin-top: 10px;"><summary style="cursor: pointer; color: #2271b1;">Meta-Felder anzeigen (' + Object.keys(entry.meta_fields || {}).length + ' Felder)</summary>';
                    html += '<table class="widefat striped" style="margin-top: 5px;">';
                    html += '<thead><tr><th style="width: 200px;">Meta-Key</th><th>Wert</th></tr></thead>';
                    html += '<tbody>';

                    var fields = entry.meta_fields || {};
                    var fieldLabels = {
                        '_event_title': 'Event-Titel',
                        '_event_title_clean': 'Event-Titel (clean)',
                        '_event_date': 'Datum',
                        '_event_time': 'Uhrzeit',
                        '_event_venue': 'Venue / Ort',
                        '_event_venue_lat': 'Venue Lat',
                        '_event_venue_lng': 'Venue Lng',
                        '_event_coach': 'Coach',
                        '_event_coach_image': 'Coach Bild',
                        '_event_coach_email': 'Coach E-Mail',
                        '_event_coach_phone': 'Coach Telefon',
                        '_event_description': 'Beschreibung',
                        '_event_course_id': 'Course-ID',
                        '_event_whatsapp_link': 'WhatsApp-Link',
                        '_event_is_workshop': 'Workshop?',
                        '_event_product_id': 'Produkt-ID',
                    };

                    Object.keys(fieldLabels).forEach(function(key) {
                        var val = fields[key] || '';
                        var label = fieldLabels[key];
                        var isEmpty = !val || val === '';
                        html += '<tr>';
                        html += '<td><code>' + key + '</code><br><small style="color: #666;">' + label + '</small></td>';
                        if (isEmpty) {
                            html += '<td style="color: #999;"><em>— leer —</em></td>';
                        } else {
                            html += '<td>' + escHtml(String(val)) + '</td>';
                        }
                        html += '</tr>';
                    });

                    html += '<tr><td><code>_event_participant_data</code><br><small style="color: #666;">Teilnehmerdaten</small></td>';
                    html += '<td style="color: #2271b1;"><em>Wird aus CSV generiert (Vorname, Nachname, Geburtsdatum)</em></td></tr>';

                    html += '</tbody></table></details>';
                    html += '</div>';
                });

                if (!allOk) {
                    html = '<div class="notice notice-warning" style="max-width: 900px;"><p><strong>Achtung:</strong> Für einige Events wurde kein Vertragstyp gefunden. Der Import funktioniert trotzdem, aber der Wizard-Link wird für diese Bestellungen nicht korrekt funktionieren.</p></div>' + html;
                } else {
                    html = '<div class="notice notice-success" style="max-width: 900px;"><p><strong>Alles OK:</strong> Für alle Events wurden Vertragstypen gefunden.</p></div>' + html;
                }

                container.innerHTML = html;
            }

            function escHtml(str) {
                var div = document.createElement('div');
                div.textContent = str;
                return div.innerHTML;
            }

            // ========================================
            // SCHRITT 3: Import starten
            // ========================================
            var importBtn = document.getElementById('ab-start-import');
            var progressWrap = document.getElementById('ab-import-progress');
            var progressBar = document.getElementById('ab-progress-bar');
            var progressText = document.getElementById('ab-progress-text');
            var resultWrap = document.getElementById('ab-import-result');
            var buttonsWrap = document.getElementById('ab-import-buttons');

            importBtn.addEventListener('click', function() {
                if (!confirm('Bist du sicher? Es werden bis zu ' + students.length + ' Bestellungen erstellt.')) {
                    return;
                }

                var classEventMapping = getClassEventMapping();

                // Custom Prices aus den Eingabefeldern lesen
                var customPrices = {};
                document.querySelectorAll('.ab-custom-price').forEach(function(input) {
                    var abId = input.getAttribute('data-ab-id');
                    var val = input.value.trim().replace(',', '.');
                    if (val) customPrices[abId] = val;
                });
                students.forEach(function(s) {
                    if (customPrices[s.ab_id]) {
                        s.custom_price = customPrices[s.ab_id];
                    }
                });

                // UI umschalten
                buttonsWrap.style.display = 'none';
                progressWrap.style.display = 'block';

                // In Batches aufteilen
                var batches = [];
                for (var i = 0; i < students.length; i += batchSize) {
                    batches.push(students.slice(i, i + batchSize));
                }

                var totalCreated = 0;
                var totalSkipped = 0;
                var totalErrors = 0;
                var allMessages = [];
                var batchIndex = 0;

                function processBatch() {
                    if (batchIndex >= batches.length) {
                        showResult();
                        return;
                    }

                    var progress = Math.round(((batchIndex) / batches.length) * 100);
                    progressBar.style.width = progress + '%';
                    progressBar.textContent = progress + '%';
                    progressText.textContent = 'Batch ' + (batchIndex + 1) + ' von ' + batches.length + '…';

                    var formData = new FormData();
                    formData.append('action', 'ab_import_batch');
                    formData.append('nonce', nonce);
                    formData.append('students', JSON.stringify(batches[batchIndex]));
                    formData.append('class_event_mapping', JSON.stringify(classEventMapping));

                    fetch(ajaxUrl, {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin'
                    })
                    .then(function(response) { return response.json(); })
                    .then(function(data) {
                        if (data.success) {
                            totalCreated += data.data.created;
                            totalSkipped += data.data.skipped;
                            totalErrors += data.data.errors;
                            allMessages = allMessages.concat(data.data.messages || []);
                        } else {
                            totalErrors += batches[batchIndex].length;
                            allMessages.push('Batch ' + (batchIndex + 1) + ' fehlgeschlagen: ' + (data.data || 'Unbekannter Fehler'));
                        }
                        batchIndex++;
                        processBatch();
                    })
                    .catch(function(err) {
                        totalErrors += batches[batchIndex].length;
                        allMessages.push('Batch ' + (batchIndex + 1) + ' Netzwerkfehler: ' + err.message);
                        batchIndex++;
                        processBatch();
                    });
                }

                function showResult() {
                    progressBar.style.width = '100%';
                    progressBar.textContent = '100%';
                    progressBar.style.background = '#00a32a';
                    progressText.textContent = 'Fertig!';

                    var html = '<div class="card" style="max-width: 500px; padding: 20px;">';
                    html += '<h2>Import abgeschlossen</h2>';
                    html += '<table class="widefat" style="max-width: 300px;">';
                    html += '<tr><td><strong>Erstellt</strong></td><td style="color: #00a32a; font-size: 18px;"><strong>' + totalCreated + '</strong></td></tr>';
                    html += '<tr><td><strong>Übersprungen</strong></td><td>' + totalSkipped + ' <small>(Duplikate / kein Event)</small></td></tr>';
                    html += '<tr><td><strong>Fehler</strong></td><td' + (totalErrors > 0 ? ' style="color: #d63638;"' : '') + '>' + totalErrors + '</td></tr>';
                    html += '<tr><td><strong>Gesamt</strong></td><td>' + students.length + ' Schüler</td></tr>';
                    html += '</table>';

                    if (allMessages.length > 0) {
                        html += '<h3 style="margin-top: 15px;">Meldungen</h3><ul style="color: #d63638;">';
                        allMessages.forEach(function(msg) {
                            html += '<li>' + msg + '</li>';
                        });
                        html += '</ul>';
                    }

                    html += '<p style="margin-top: 20px;">';
                    html += '<a href="' + ordersUrl + '" class="button button-primary">Bestandskunden-Bestellungen ansehen</a> ';
                    html += '<a href="' + importUrl + '" class="button">Weiteren Import</a>';
                    html += '</p></div>';

                    resultWrap.innerHTML = html;
                    resultWrap.style.display = 'block';
                }

                processBatch();
            });
        })();
        </script>
        <?php
    }
}
