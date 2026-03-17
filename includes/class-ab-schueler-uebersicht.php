<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Schüler-Übersicht: Admin-Seite mit Klassen, PDF-Status, CSV & ZIP-Export
 * Zeigt alle aktiven Schüler (Status: schuelerin, bestandkundeakz) gruppiert nach Klassen.
 */
class AB_Schueler_Uebersicht {

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_menu_page']);
        add_action('admin_init', [__CLASS__, 'handle_csv_download']);
        add_action('admin_init', [__CLASS__, 'handle_zip_download']);
        add_action('admin_init', [__CLASS__, 'backfill_contract_dates']);
    }

    /**
     * Einmalig: Für bestehende Bestellungen mit abgeschlossenem Vertrag das Datum nachträglich setzen.
     * Ermittelt das Datum aus den Order-Notes (Statusänderung auf schuelerin/bestandkundeakz).
     */
    public static function backfill_contract_dates() {
        if (get_option('ab_contract_dates_backfilled')) {
            return;
        }

        $orders = wc_get_orders([
            'status' => ['schuelerin', 'bestandkundeakz'],
            'limit'  => -1,
            'return' => 'ids',
        ]);

        foreach ($orders as $order_id) {
            $existing = get_post_meta($order_id, '_ab_contract_completion_date', true);
            if (!empty($existing)) {
                continue;
            }

            // Versuche Datum aus Order-Notes zu ermitteln
            $notes = wc_get_order_notes(['order_id' => $order_id, 'type' => 'internal']);
            $completion_date = '';

            foreach ($notes as $note) {
                // Suche nach Statusänderung auf schuelerin oder bestandkundeakz
                if (preg_match('/(schuelerin|bestandkundeakz|Bestandskunde akzeptiert|Bestandskunde-Vertrag abgeschlossen)/i', $note->content)) {
                    $completion_date = $note->date_created->date('Y-m-d H:i:s');
                    break;
                }
            }

            // Fallback: Datum der letzten Statusänderung der Order
            if (empty($completion_date)) {
                $order = wc_get_order($order_id);
                if ($order) {
                    $completion_date = $order->get_date_modified() ? $order->get_date_modified()->date('Y-m-d H:i:s') : '';
                }
            }

            if (!empty($completion_date)) {
                update_post_meta($order_id, '_ab_contract_completion_date', $completion_date);
            }
        }

        update_option('ab_contract_dates_backfilled', true);
        error_log('[AB Schüler] Backfill abgeschlossen für ' . count($orders) . ' Bestellungen');
    }

    public static function add_menu_page() {
        add_submenu_page(
            'parkourone',
            'Schüler-Übersicht',
            'Schüler-Übersicht',
            'manage_woocommerce',
            'ab-schueler-uebersicht',
            [__CLASS__, 'render_page']
        );
    }

    /**
     * Alle Orders mit Status schuelerin/bestandkundeakz holen, nach Klasse gruppieren
     */
    private static function get_all_grouped_students() {
        $orders = wc_get_orders([
            'status' => ['schuelerin', 'bestandkundeakz'],
            'limit'  => -1,
        ]);

        $classes = [];

        foreach ($orders as $order) {
            $order_id = $order->get_id();
            $student = self::get_student_data_from_order($order);

            foreach ($order->get_items() as $item) {
                $class_name = $item->get_meta('_event_title_clean') ?: $item->get_meta('_event_title');
                if (empty($class_name)) {
                    $class_name = 'Ohne Klasse';
                }

                if (!isset($classes[$class_name])) {
                    $classes[$class_name] = [];
                }

                // Vermeidung von Duplikaten (gleiche Order-ID in gleicher Klasse)
                if (!isset($classes[$class_name][$order_id])) {
                    $classes[$class_name][$order_id] = $student;
                }
            }

            // Order ohne Line Items
            if (count($order->get_items()) === 0) {
                $class_name = 'Ohne Klasse';
                if (!isset($classes[$class_name])) {
                    $classes[$class_name] = [];
                }
                if (!isset($classes[$class_name][$order_id])) {
                    $classes[$class_name][$order_id] = $student;
                }
            }
        }

        // Alphabetisch nach Klassenname sortieren
        ksort($classes);

        // Innerhalb jeder Klasse nach Nachname sortieren
        foreach ($classes as &$students) {
            uasort($students, function ($a, $b) {
                return strcasecmp($a['nachname'], $b['nachname']);
            });
        }
        unset($students);

        return $classes;
    }

    /**
     * Student-Daten aus Order extrahieren (Priorität: Vertragsdaten > Billing-Daten)
     */
    private static function get_student_data_from_order($order) {
        $order_id = $order->get_id();
        $contract_data = get_post_meta($order_id, '_ab_contract_data', true) ?: [];

        $vorname = !empty($contract_data['vorname']) ? $contract_data['vorname'] : $order->get_billing_first_name();
        $nachname = !empty($contract_data['nachname']) ? $contract_data['nachname'] : $order->get_billing_last_name();
        $email = !empty($contract_data['email']) ? $contract_data['email'] : $order->get_billing_email();
        $geburtsdatum = !empty($contract_data['geburtsdatum']) ? $contract_data['geburtsdatum'] : '';

        // Dabei seit: Vertragsabschluss-Datum ermitteln
        $dabei_seit = get_post_meta($order_id, '_ab_contract_completion_date', true);
        if (!empty($dabei_seit)) {
            $dabei_seit = date_i18n('d.m.Y', strtotime($dabei_seit));
        } else {
            $dabei_seit = '';
        }

        return [
            'order_id'     => $order_id,
            'vorname'      => $vorname,
            'nachname'     => $nachname,
            'email'        => $email,
            'geburtsdatum' => $geburtsdatum,
            'dabei_seit'   => $dabei_seit,
            'status'       => $order->get_status(),
            'has_pdf'      => self::has_valid_pdf($order_id),
            'pdf_url'      => self::get_pdf_url($order_id),
        ];
    }

    /**
     * Prüft ob ein gültiges PDF für die Order existiert
     */
    private static function has_valid_pdf($order_id) {
        $pdf_path = get_post_meta($order_id, '_ab_contract_pdf', true);
        return !empty($pdf_path) && file_exists($pdf_path);
    }

    /**
     * Admin-PDF-URL generieren (bestehender Mechanismus)
     */
    private static function get_pdf_url($order_id) {
        return add_query_arg([
            'action'   => 'view_contract_pdf',
            'order_id' => $order_id,
            'nonce'    => wp_create_nonce('view_contract_pdf'),
        ], admin_url('admin.php'));
    }

    /**
     * CSV-Export Handler
     */
    public static function handle_csv_download() {
        if (!isset($_GET['ab_schueler_csv'])) {
            return;
        }
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Keine Berechtigung.');
        }
        check_admin_referer('ab_schueler_export');

        $classes = self::get_all_grouped_students();
        $filter_class = isset($_GET['klasse']) ? sanitize_text_field($_GET['klasse']) : '';

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename=schueler_export_' . date('Y-m-d') . '.csv');

        $output = fopen('php://output', 'w');
        // BOM für Excel UTF-8
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        fputcsv($output, ['Klasse', 'Vorname', 'Nachname', 'E-Mail', 'Geburtsdatum', 'Dabei seit', 'Vertrag', 'Bestellnummer', 'Status'], ';');

        foreach ($classes as $class_name => $students) {
            if ($filter_class && $class_name !== $filter_class) {
                continue;
            }
            foreach ($students as $student) {
                $status_label = $student['status'] === 'schuelerin' ? 'Schüler_in' : 'Bestandskunde (AKZ)';
                fputcsv($output, [
                    $class_name,
                    $student['vorname'],
                    $student['nachname'],
                    $student['email'],
                    $student['geburtsdatum'],
                    $student['dabei_seit'],
                    $student['has_pdf'] ? 'Ja' : 'Nein',
                    $student['order_id'],
                    $status_label,
                ], ';');
            }
        }

        fclose($output);
        exit;
    }

    /**
     * ZIP-Download Handler: Alle PDFs in Klassen-Unterordner
     */
    public static function handle_zip_download() {
        if (!isset($_GET['ab_schueler_zip'])) {
            return;
        }
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Keine Berechtigung.');
        }
        check_admin_referer('ab_schueler_export');

        if (!class_exists('ZipArchive')) {
            wp_die('ZipArchive ist auf diesem Server nicht verfügbar.');
        }

        $classes = self::get_all_grouped_students();
        $filter_class = isset($_GET['klasse']) ? sanitize_text_field($_GET['klasse']) : '';

        $temp_file = tempnam(sys_get_temp_dir(), 'ab_vertraege_');
        $zip = new ZipArchive();
        $zip->open($temp_file, ZipArchive::OVERWRITE);

        $file_count = 0;

        foreach ($classes as $class_name => $students) {
            if ($filter_class && $class_name !== $filter_class) {
                continue;
            }
            // Ordnername bereinigen
            $folder = preg_replace('/[^a-zA-Z0-9äöüÄÖÜß _\-]/', '', $class_name);

            foreach ($students as $student) {
                if (!$student['has_pdf']) {
                    continue;
                }
                $pdf_path = get_post_meta($student['order_id'], '_ab_contract_pdf', true);
                if ($pdf_path && file_exists($pdf_path)) {
                    $safe_nachname = preg_replace('/[^a-zA-Z0-9äöüÄÖÜß_\-]/', '', $student['nachname']);
                    $safe_vorname = preg_replace('/[^a-zA-Z0-9äöüÄÖÜß_\-]/', '', $student['vorname']);
                    $filename = $safe_nachname . '_' . $safe_vorname . '_' . $student['order_id'] . '.pdf';
                    $zip->addFile($pdf_path, $folder . '/' . $filename);
                    $file_count++;
                }
            }
        }

        $zip->close();

        if ($file_count === 0) {
            unlink($temp_file);
            wp_die('Keine PDFs zum Herunterladen gefunden.');
        }

        $download_name = 'vertraege_' . date('Y-m-d') . '.zip';
        if ($filter_class) {
            $safe_class = preg_replace('/[^a-zA-Z0-9äöüÄÖÜß_\-]/', '', $filter_class);
            $download_name = 'vertraege_' . $safe_class . '_' . date('Y-m-d') . '.zip';
        }

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename=' . $download_name);
        header('Content-Length: ' . filesize($temp_file));
        readfile($temp_file);
        unlink($temp_file);
        exit;
    }

    /**
     * Hauptseite rendern
     */
    public static function render_page() {
        $classes = self::get_all_grouped_students();

        // Statistiken berechnen
        $total = 0;
        $with_pdf = 0;
        $unique_orders = [];
        foreach ($classes as $students) {
            foreach ($students as $order_id => $student) {
                if (!isset($unique_orders[$order_id])) {
                    $unique_orders[$order_id] = true;
                    $total++;
                    if ($student['has_pdf']) {
                        $with_pdf++;
                    }
                }
            }
        }
        $without_pdf = $total - $with_pdf;

        // Export-URLs
        $csv_url = wp_nonce_url(
            admin_url('admin.php?page=ab-schueler-uebersicht&ab_schueler_csv=1'),
            'ab_schueler_export'
        );
        $zip_url = wp_nonce_url(
            admin_url('admin.php?page=ab-schueler-uebersicht&ab_schueler_zip=1'),
            'ab_schueler_export'
        );

        ?>
        <style>
            .ab-su-wrap { max-width: 1400px; }
            .ab-su-cards { display: flex; gap: 16px; margin-bottom: 20px; }
            .ab-su-card {
                background: #fff;
                border: 1px solid #c3c4c7;
                border-radius: 4px;
                padding: 16px 24px;
                min-width: 160px;
                border-left: 4px solid #2271b1;
            }
            .ab-su-card-value { font-size: 32px; font-weight: 600; line-height: 1.2; }
            .ab-su-card-label { color: #50575e; font-size: 13px; margin-top: 4px; }
            .ab-su-card.green { border-left-color: #00a32a; }
            .ab-su-card.red { border-left-color: #d63638; }

            .ab-su-toolbar {
                display: flex;
                gap: 12px;
                align-items: center;
                flex-wrap: wrap;
                margin-bottom: 24px;
                padding: 12px 16px;
                background: #fff;
                border: 1px solid #c3c4c7;
                border-radius: 4px;
            }
            .ab-su-toolbar .button { white-space: nowrap; }
            .ab-su-search {
                padding: 4px 8px;
                min-width: 220px;
                border: 1px solid #8c8f94;
                border-radius: 4px;
            }
            .ab-su-toolbar select {
                min-width: 180px;
            }
            .ab-su-toolbar label {
                display: flex;
                align-items: center;
                gap: 4px;
                white-space: nowrap;
                cursor: pointer;
            }

            .ab-su-class-section { margin-bottom: 28px; }
            .ab-su-class-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 10px 16px;
                background: #f0f0f1;
                border: 1px solid #c3c4c7;
                border-bottom: none;
                border-radius: 4px 4px 0 0;
            }
            .ab-su-class-title {
                font-size: 14px;
                font-weight: 600;
                margin: 0;
                border-left: 3px solid #2271b1;
                padding-left: 10px;
            }
            .ab-su-class-stats { color: #50575e; font-size: 13px; }
            .ab-su-class-actions { display: flex; gap: 6px; }

            .ab-su-table { border-top: none; border-radius: 0 0 4px 4px; }
            .ab-su-table th { font-size: 13px; }
            .ab-su-pdf-yes { color: #00a32a; font-weight: 600; }
            .ab-su-pdf-no { color: #d63638; font-weight: 600; }
            .ab-su-status { font-size: 12px; padding: 2px 8px; border-radius: 3px; }
            .ab-su-status-schuelerin { background: #e7f5e8; color: #00a32a; }
            .ab-su-status-bestandkundeakz { background: #e8f0fe; color: #2271b1; }
            .ab-su-row-hidden { display: none; }
        </style>

        <div class="wrap ab-su-wrap">
            <h1>Schüler-Übersicht</h1>

            <!-- Summary Cards -->
            <div class="ab-su-cards">
                <div class="ab-su-card">
                    <div class="ab-su-card-value"><?php echo $total; ?></div>
                    <div class="ab-su-card-label">Gesamt</div>
                </div>
                <div class="ab-su-card green">
                    <div class="ab-su-card-value"><?php echo $with_pdf; ?></div>
                    <div class="ab-su-card-label">mit PDF-Vertrag</div>
                </div>
                <div class="ab-su-card red">
                    <div class="ab-su-card-value"><?php echo $without_pdf; ?></div>
                    <div class="ab-su-card-label">ohne PDF-Vertrag</div>
                </div>
            </div>

            <!-- Toolbar -->
            <div class="ab-su-toolbar">
                <a href="<?php echo esc_url($csv_url); ?>" class="button button-secondary">CSV Export</a>
                <a href="<?php echo esc_url($zip_url); ?>" class="button button-secondary">ZIP Download</a>
                <span style="border-left: 1px solid #c3c4c7; height: 24px;"></span>
                <input type="text" id="ab-su-search" class="ab-su-search" placeholder="Suche nach Name / E-Mail...">
                <select id="ab-su-class-filter">
                    <option value="">Alle Klassen</option>
                    <?php foreach ($classes as $class_name => $students): ?>
                        <option value="<?php echo esc_attr(sanitize_title($class_name)); ?>">
                            <?php echo esc_html($class_name); ?> (<?php echo count($students); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <label>
                    <input type="checkbox" id="ab-su-no-pdf-filter"> Nur ohne PDF
                </label>
            </div>

            <!-- Klassen-Tabellen -->
            <?php foreach ($classes as $class_name => $students):
                $class_total = count($students);
                $class_pdf = 0;
                foreach ($students as $s) {
                    if ($s['has_pdf']) $class_pdf++;
                }
                $class_slug = sanitize_title($class_name);

                $class_csv_url = wp_nonce_url(
                    admin_url('admin.php?page=ab-schueler-uebersicht&ab_schueler_csv=1&klasse=' . urlencode($class_name)),
                    'ab_schueler_export'
                );
                $class_zip_url = wp_nonce_url(
                    admin_url('admin.php?page=ab-schueler-uebersicht&ab_schueler_zip=1&klasse=' . urlencode($class_name)),
                    'ab_schueler_export'
                );
            ?>
                <div class="ab-su-class-section" data-class="<?php echo esc_attr($class_slug); ?>">
                    <div class="ab-su-class-header">
                        <h3 class="ab-su-class-title"><?php echo esc_html($class_name); ?></h3>
                        <div style="display: flex; align-items: center; gap: 16px;">
                            <span class="ab-su-class-stats">
                                <?php echo $class_total; ?> Schüler / <?php echo $class_pdf; ?> mit PDF
                            </span>
                            <div class="ab-su-class-actions">
                                <a href="<?php echo esc_url($class_csv_url); ?>" class="button button-small" title="CSV dieser Klasse">CSV</a>
                                <a href="<?php echo esc_url($class_zip_url); ?>" class="button button-small" title="PDFs dieser Klasse">ZIP</a>
                            </div>
                        </div>
                    </div>
                    <table class="wp-list-table widefat fixed striped ab-su-table">
                        <thead>
                            <tr>
                                <th style="width: 20%;">Name</th>
                                <th style="width: 20%;">E-Mail</th>
                                <th style="width: 10%;">Geburtsdatum</th>
                                <th style="width: 10%;">Dabei seit</th>
                                <th style="width: 12%;">Status</th>
                                <th style="width: 6%;">PDF</th>
                                <th style="width: 22%;">Aktion</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($students)): ?>
                                <tr><td colspan="7">Keine Schüler in dieser Klasse.</td></tr>
                            <?php else: ?>
                                <?php foreach ($students as $order_id => $student):
                                    $status_label = $student['status'] === 'schuelerin' ? 'Schüler_in' : 'Bestandskunde';
                                    $status_class = 'ab-su-status-' . esc_attr($student['status']);
                                    $full_name = $student['vorname'] . ' ' . $student['nachname'];
                                ?>
                                    <tr class="ab-su-row"
                                        data-name="<?php echo esc_attr(mb_strtolower($full_name)); ?>"
                                        data-email="<?php echo esc_attr(mb_strtolower($student['email'])); ?>"
                                        data-has-pdf="<?php echo $student['has_pdf'] ? '1' : '0'; ?>">
                                        <td>
                                            <strong><?php echo esc_html($student['nachname']); ?></strong>, <?php echo esc_html($student['vorname']); ?>
                                        </td>
                                        <td><?php echo esc_html($student['email']); ?></td>
                                        <td><?php echo esc_html($student['geburtsdatum']); ?></td>
                                        <td><?php echo esc_html($student['dabei_seit']); ?></td>
                                        <td>
                                            <span class="ab-su-status <?php echo $status_class; ?>">
                                                <?php echo esc_html($status_label); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($student['has_pdf']): ?>
                                                <a href="<?php echo esc_url($student['pdf_url']); ?>" target="_blank" class="ab-su-pdf-yes" title="PDF öffnen">&#10003;</a>
                                            <?php else: ?>
                                                <span class="ab-su-pdf-no">&#10007;</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($student['has_pdf']): ?>
                                                <a href="<?php echo esc_url($student['pdf_url']); ?>" target="_blank" class="button button-small">PDF</a>
                                            <?php endif; ?>
                                            <a href="<?php echo esc_url(admin_url('post.php?post=' . $order_id . '&action=edit')); ?>" class="button button-small">#<?php echo $order_id; ?></a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>

            <?php if (empty($classes)): ?>
                <div class="notice notice-info" style="margin-top: 20px;">
                    <p>Keine aktiven Schüler gefunden (Status: Schüler_in oder Bestandskunde AKZ).</p>
                </div>
            <?php endif; ?>
        </div>

        <script>
        (function() {
            var searchInput = document.getElementById('ab-su-search');
            var classFilter = document.getElementById('ab-su-class-filter');
            var noPdfFilter = document.getElementById('ab-su-no-pdf-filter');

            function applyFilters() {
                var searchTerm = searchInput.value.toLowerCase();
                var selectedClass = classFilter.value;
                var onlyNoPdf = noPdfFilter.checked;

                // Klassen-Sektionen
                var sections = document.querySelectorAll('.ab-su-class-section');
                sections.forEach(function(section) {
                    var classSlug = section.getAttribute('data-class');
                    var classHidden = selectedClass && classSlug !== selectedClass;
                    section.style.display = classHidden ? 'none' : '';

                    if (classHidden) return;

                    // Rows innerhalb der Sektion
                    var rows = section.querySelectorAll('.ab-su-row');
                    var visibleCount = 0;
                    rows.forEach(function(row) {
                        var name = row.getAttribute('data-name');
                        var email = row.getAttribute('data-email');
                        var hasPdf = row.getAttribute('data-has-pdf') === '1';

                        var matchSearch = !searchTerm || name.indexOf(searchTerm) !== -1 || email.indexOf(searchTerm) !== -1;
                        var matchPdf = !onlyNoPdf || !hasPdf;

                        if (matchSearch && matchPdf) {
                            row.classList.remove('ab-su-row-hidden');
                            visibleCount++;
                        } else {
                            row.classList.add('ab-su-row-hidden');
                        }
                    });

                    // Sektion verstecken wenn keine sichtbaren Rows
                    if (visibleCount === 0 && (searchTerm || onlyNoPdf)) {
                        section.style.display = 'none';
                    }
                });
            }

            searchInput.addEventListener('input', applyFilters);
            noPdfFilter.addEventListener('change', applyFilters);

            classFilter.addEventListener('change', function() {
                applyFilters();
                // Smooth-Scroll zur gewählten Klasse
                if (classFilter.value) {
                    var target = document.querySelector('.ab-su-class-section[data-class="' + classFilter.value + '"]');
                    if (target) {
                        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                }
            });
        })();
        </script>
        <?php
    }
}
