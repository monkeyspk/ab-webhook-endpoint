<?php
class AB_Contract_Types {
    private static $html_fields = [
        'ab_accordion_basic',
        'ab_accordion_training',
        'ab_accordion_conditions'
    ];

    public static function init() {
        add_action('init', [__CLASS__, 'register_cpt']);
        add_action('add_meta_boxes', [__CLASS__, 'add_meta_box']);
        add_action('save_post_ab_contract_type', [__CLASS__, 'save_meta_box']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_media']);
    }

    public static function enqueue_media($hook) {
        global $post;

        if (('post.php' === $hook || 'post-new.php' === $hook) &&
            isset($post) && 'ab_contract_type' === $post->post_type) {

            // WordPress Media API laden
            wp_enqueue_media();

            // Unser eigenes Script für den Image-Button laden
            wp_enqueue_script(
                'ab-contract-media-js',
                plugins_url('assets/js/contract-wizard.js', dirname(__FILE__)),
                array('jquery'),
                '1.0.0',
                true
            );

            // Admin CSS laden
            wp_enqueue_style(
                'ab-contract-admin-style',
                plugins_url('assets/css/admin-style.css', dirname(__FILE__)),
                array(),
                '1.0.0'
            );
        }
    }

    public static function register_cpt() {
        $labels = [
            'name' => 'Vertragstypen',
            'singular_name' => 'Vertragstyp',
            'add_new' => 'Neuen Vertragstyp erstellen',
            'add_new_item' => 'Neuen Vertragstyp hinzufügen',
            'edit_item' => 'Vertragstyp bearbeiten',
            'all_items' => 'Alle Vertragstypen',
            'menu_name' => 'Vertragstypen',
        ];

        $args = [
            'label' => 'Vertragstypen',
            'labels' => $labels,
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'parkourone',
            'capability_type' => 'post',
            'hierarchical' => false,
            'supports' => ['title', 'editor'],
            'has_archive' => false,
            'menu_position' => 25,
            'menu_icon' => 'dashicons-media-text'
        ];
        register_post_type('ab_contract_type', $args);
    }

    public static function add_meta_box() {
        add_meta_box(
            'ab_contract_type_meta',
            'Vertragsdetails',
            [__CLASS__, 'render_meta_box'],
            'ab_contract_type',
            'normal',
            'high'
        );
    }

    public static function render_meta_box($post) {
        $accordion_titles = [
            'basic' => get_post_meta($post->ID, '_ab_accordion_title_basic', true) ?: 'Allgemeine Vertragsbedingungen',
            'training' => get_post_meta($post->ID, '_ab_accordion_title_training', true) ?: 'Leistungen ParkourONE',
            'conditions' => get_post_meta($post->ID, '_ab_accordion_title_conditions', true) ?: 'Abwesenheit / Abmeldung',
        ];

        $fields = [
            'vertrag_preis' => get_post_meta($post->ID, '_ab_vertrag_preis', true),
            'trainingsumfang' => get_post_meta($post->ID, '_ab_trainingsumfang', true),
            'verlaengerung' => get_post_meta($post->ID, '_ab_verlaengerung', true),
            'kuendigungsfrist' => get_post_meta($post->ID, '_ab_kuendigungsfrist', true),
            'probezeit' => get_post_meta($post->ID, '_ab_probezeit', true),
            'event_match' => get_post_meta($post->ID, '_ab_event_description', true),
            'vertrag_bild' => get_post_meta($post->ID, '_ab_vertrag_bild', true),
        ];
        ?>
        <p>
            <label for="ab_vertrag_preis">Monatlicher Preis (z.B. 65.00)</label>
            <input type="text" id="ab_vertrag_preis" name="ab_vertrag_preis" value="<?php echo esc_attr($fields['vertrag_preis']); ?>">
        </p>
        <p>
            <label for="ab_trainingsumfang">Trainingsumfang</label>
            <input type="text" id="ab_trainingsumfang" name="ab_trainingsumfang" value="<?php echo esc_attr($fields['trainingsumfang']); ?>">
            <small>Z.B. "1x wöchentlich 1,5h Parkour Training"</small>
        </p>
        <p>
            <label for="ab_verlaengerung">Verlängerung</label>
            <input type="text" id="ab_verlaengerung" name="ab_verlaengerung" value="<?php echo esc_attr($fields['verlaengerung']); ?>">
            <small>Z.B. "Jeden Monat (unbefristet)"</small>
        </p>
        <p>
            <label for="ab_kuendigungsfrist">Kündigungsfrist</label>
            <input type="text" id="ab_kuendigungsfrist" name="ab_kuendigungsfrist" value="<?php echo esc_attr($fields['kuendigungsfrist']); ?>">
            <small>Z.B. "1 Monat zum Ende einer Trainingsperiode"</small>
        </p>
        <p>
            <label for="ab_probezeit">Probezeit</label>
            <input type="text" id="ab_probezeit" name="ab_probezeit" value="<?php echo esc_attr($fields['probezeit']); ?>">
            <small>Z.B. "1 Monat mit fristloser Kündigung"</small>
        </p>
        <p>
            <label for="ab_event_description">Event Beschreibung für Zuordnung (Fallback)</label>
            <input type="text" id="ab_event_description" name="ab_event_description" value="<?php echo esc_attr($fields['event_match']); ?>">
            <small>Z.B. "Kids" - wird nur verwendet wenn keine Course-ID gesetzt ist</small>
        </p>
        <p>
            <label for="ab_course_id">Course-ID (AcademyBoard)</label>
            <input type="number" id="ab_course_id" name="ab_course_id" value="<?php echo esc_attr(get_post_meta($post->ID, '_ab_course_id', true)); ?>">
            <small>Die eindeutige ID der Klasse aus dem AcademyBoard (z.B. 7) - primäre Zuordnung, robust gegen Umbenennungen</small>
        </p>

        <p>
            <label for="ab_vertrag_bild">Vertragsbild</label>
            <input type="hidden" id="ab_vertrag_bild" name="ab_vertrag_bild" value="<?php echo esc_attr($fields['vertrag_bild']); ?>">
            <button type="button" class="upload-image-button button">Bild auswählen</button>
            <div class="image-preview-container">
                <div class="image-preview">
                    <?php echo $fields['vertrag_bild'] ? wp_get_attachment_image($fields['vertrag_bild'], 'full') : ''; ?>
                </div>
            </div>
        </p>

        <p>
            <label for="ab_accordion_title_basic">Titel für diesen Abschnitt</label>
            <input type="text" id="ab_accordion_title_basic" name="ab_accordion_title_basic" value="<?php echo esc_attr($accordion_titles['basic']); ?>">
        </p>
        <div class="accordion">
            <button type="button" class="accordion-toggle">
                <?php echo esc_html($accordion_titles['basic']); ?>
            </button>
            <div class="accordion-content">
                <?php
                wp_editor(
                    wp_kses_post(get_post_meta($post->ID, '_ab_accordion_basic', true)),
                    'ab_accordion_basic',
                    array(
                        'textarea_name' => 'ab_accordion_basic',
                        'textarea_rows' => 10,
                        'media_buttons' => false,
                        'teeny'        => false,
                        'quicktags'    => true,
                        'wpautop'      => false,
                        'tinymce'      => array(
                            'verify_html' => false,
                            'forced_root_block' => false
                        )
                    )
                );
                ?>
                <small>Grundlegende Infos wie AGB, Leistungen, Preise etc.</small>
            </div>
        </div>

        <p>
            <label for="ab_accordion_title_training">Titel für diesen Abschnitt</label>
            <input type="text" id="ab_accordion_title_training" name="ab_accordion_title_training" value="<?php echo esc_attr($accordion_titles['training']); ?>">
        </p>
        <div class="accordion">
            <button type="button" class="accordion-toggle">
                <?php echo esc_html($accordion_titles['training']); ?>
            </button>
            <div class="accordion-content">
                <?php
                wp_editor(
                    wp_kses_post(get_post_meta($post->ID, '_ab_accordion_training', true)),
                    'ab_accordion_training',
                    array(
                        'textarea_name' => 'ab_accordion_training',
                        'textarea_rows' => 10,
                        'media_buttons' => false,
                        'teeny'        => false,
                        'quicktags'    => true,
                        'wpautop'      => false,
                        'tinymce'      => array(
                            'verify_html' => false,
                            'forced_root_block' => false
                        )
                    )
                );
                ?>
                <small>Ferien, Feiertage, Änderungen, Trainingsausfall etc.</small>
            </div>
        </div>

        <p>
            <label for="ab_accordion_title_conditions">Titel für diesen Abschnitt</label>
            <input type="text" id="ab_accordion_title_conditions" name="ab_accordion_title_conditions" value="<?php echo esc_attr($accordion_titles['conditions']); ?>">
        </p>
        <div class="accordion">
            <button type="button" class="accordion-toggle">
                <?php echo esc_html($accordion_titles['conditions']); ?>
            </button>
            <div class="accordion-content">
                <?php
                wp_editor(
                    wp_kses_post(get_post_meta($post->ID, '_ab_accordion_conditions', true)),
                    'ab_accordion_conditions',
                    array(
                        'textarea_name' => 'ab_accordion_conditions',
                        'textarea_rows' => 10,
                        'media_buttons' => false,
                        'teeny'        => false,
                        'quicktags'    => true,
                        'wpautop'      => false,
                        'tinymce'      => array(
                            'verify_html' => false,
                            'forced_root_block' => false
                        )
                    )
                );
                ?>
                <small>Academy Board, Abwesenheit, Kündigungen etc.</small>
            </div>
        </div>

        <?php
        wp_nonce_field('ab_contract_type_nonce', 'ab_contract_type_nonce_field');
    }

    public static function save_meta_box($post_id) {
        if (!isset($_POST['ab_contract_type_nonce_field']) ||
            !wp_verify_nonce($_POST['ab_contract_type_nonce_field'], 'ab_contract_type_nonce')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Standard Felder
        $text_fields = [
            'ab_vertrag_preis',
            'ab_trainingsumfang',
            'ab_verlaengerung',
            'ab_kuendigungsfrist',
            'ab_probezeit',
            'ab_event_description',
            'ab_vertrag_bild',
            'ab_course_id'
        ];

        $accordion_title_fields = [
            'ab_accordion_title_basic',
            'ab_accordion_title_training',
            'ab_accordion_title_conditions'
        ];

        // Text Felder speichern
        foreach ($text_fields as $field) {
            if (isset($_POST[$field])) {
                update_post_meta($post_id, '_' . $field, sanitize_text_field($_POST[$field]));
            }
        }

        // Akkordeon Titel speichern
        foreach ($accordion_title_fields as $field) {
            if (isset($_POST[$field])) {
                update_post_meta($post_id, '_' . $field, sanitize_text_field($_POST[$field]));
            }
        }

        // HTML Felder speichern
        foreach (self::$html_fields as $field) {
            if (isset($_POST[$field])) {
                update_post_meta($post_id, '_' . $field, wp_kses_post($_POST[$field]));
            }
        }
    }
}
