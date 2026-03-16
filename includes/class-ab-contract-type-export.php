<?php
if (!defined('ABSPATH')) {
    exit;
}

class AB_Contract_Type_Export {

    public static function init() {
        // Menü-Registrierung erfolgt über AB_Combined_Settings (kombinierte Seite)
        add_action('admin_init', [__CLASS__, 'handle_csv_download']);
    }

    /**
     * Alle ab_contract_type Posts mit relevanten Meta-Feldern holen
     */
    private static function get_all_contract_types() {
        $posts = get_posts([
            'post_type'      => 'ab_contract_type',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'post_status'    => 'publish',
        ]);

        $data = [];
        foreach ($posts as $post) {
            $data[] = [
                'id'                => $post->ID,
                'title'             => $post->post_title,
                'event_description' => get_post_meta($post->ID, '_ab_event_description', true),
                'course_id'         => get_post_meta($post->ID, '_ab_course_id', true),
                'preis'             => get_post_meta($post->ID, '_ab_vertrag_preis', true),
                'trainingsumfang'   => get_post_meta($post->ID, '_ab_trainingsumfang', true),
            ];
        }

        return $data;
    }

    /**
     * CSV-Download Handler
     */
    public static function handle_csv_download() {
        if (!isset($_GET['ab_export_contract_types_csv'])) {
            return;
        }
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Keine Berechtigung.');
        }
        check_admin_referer('ab_export_contract_types');

        $data = self::get_all_contract_types();

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename=vertragstypen_export_' . date('Y-m-d') . '.csv');

        $output = fopen('php://output', 'w');
        // BOM für Excel UTF-8
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        fputcsv($output, ['ID', 'Titel', 'Event Description', 'Course ID', 'Preis', 'Trainingsumfang'], ';');

        foreach ($data as $row) {
            fputcsv($output, [
                $row['id'],
                $row['title'],
                $row['event_description'],
                $row['course_id'],
                $row['preis'],
                $row['trainingsumfang'],
            ], ';');
        }

        fclose($output);
        exit;
    }

    /**
     * Admin-Seite rendern
     */
    public static function render_page() {
        $data = self::get_all_contract_types();
        $csv_url = wp_nonce_url(
            admin_url('admin.php?page=ab-contract-type-export&ab_export_contract_types_csv=1'),
            'ab_export_contract_types'
        );
        ?>
        <div class="wrap">
            <h1>Vertragstypen Übersicht</h1>
            <p>
                Alle registrierten Vertragstypen mit IDs. Nutze diese IDs für den Bestandskunden-Import.
                <a href="<?php echo esc_url($csv_url); ?>" class="button button-secondary" style="margin-left: 10px;">CSV Export</a>
            </p>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 60px;">ID</th>
                        <th>Titel</th>
                        <th>Event Description</th>
                        <th style="width: 100px;">Course ID</th>
                        <th style="width: 100px;">Preis</th>
                        <th>Trainingsumfang</th>
                        <th style="width: 80px;">Aktion</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($data)): ?>
                        <tr><td colspan="7">Keine Vertragstypen gefunden.</td></tr>
                    <?php else: ?>
                        <?php foreach ($data as $row): ?>
                            <tr>
                                <td><strong><?php echo esc_html($row['id']); ?></strong></td>
                                <td><?php echo esc_html($row['title']); ?></td>
                                <td><code><?php echo esc_html($row['event_description'] ?: '—'); ?></code></td>
                                <td><code><?php echo esc_html($row['course_id'] ?: '—'); ?></code></td>
                                <td><?php echo esc_html($row['preis'] ? $row['preis'] . ' €' : '—'); ?></td>
                                <td><?php echo esc_html($row['trainingsumfang'] ?: '—'); ?></td>
                                <td>
                                    <a href="<?php echo get_edit_post_link($row['id']); ?>" class="button button-small">Bearbeiten</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <p style="margin-top: 20px; color: #666;">
                <strong>Gesamt:</strong> <?php echo count($data); ?> Vertragstypen
            </p>
        </div>
        <?php
    }
}
