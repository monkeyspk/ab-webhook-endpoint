<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Kombinierte Einstellungen-Seite mit Tabs
 * Fasst Zahlungseinstellungen, Adressänderung und Vertragstypen-Export zusammen
 */
class AB_Combined_Settings {

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_menu_page']);
    }

    public static function add_menu_page() {
        add_submenu_page(
            'parkourone',
            'Einstellungen',
            'Einstellungen',
            'manage_options',
            'ab-settings',
            [__CLASS__, 'render_page']
        );
    }

    public static function render_page() {
        $current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'zahlungen';
        $tabs = [
            'zahlungen' => 'Zahlungen',
            'adressen'  => 'Adressen',
            'export'    => 'Vertragstypen Export',
        ];
        ?>
        <div class="wrap">
            <h1>Einstellungen</h1>
            <nav class="nav-tab-wrapper">
                <?php foreach ($tabs as $tab_key => $tab_label): ?>
                    <a href="<?php echo esc_url(add_query_arg('tab', $tab_key, admin_url('admin.php?page=ab-settings'))); ?>"
                       class="nav-tab <?php echo $current_tab === $tab_key ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html($tab_label); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="ab-tab-content" style="margin-top: 20px;">
                <?php
                switch ($current_tab) {
                    case 'adressen':
                        self::render_tab_content('ab_contract_address_page');
                        break;
                    case 'export':
                        self::render_tab_content([AB_Contract_Type_Export::class, 'render_page']);
                        break;
                    default:
                        self::render_tab_content([AB_Payment_Settings::class, 'render_settings_page']);
                        break;
                }
                ?>
            </div>
        </div>
        <style>
            .ab-tab-content > .wrap { padding: 0; margin: 0; }
            .ab-tab-content > .wrap > h1:first-child { display: none; }
        </style>
        <?php
    }

    /**
     * Rendert den Tab-Inhalt und strippt den äußeren Wrapper
     */
    private static function render_tab_content($callback) {
        ob_start();
        call_user_func($callback);
        $content = ob_get_clean();

        // Entferne den äußeren <div class="wrap"> und den <h1>
        $content = preg_replace('/^\s*<div class="wrap">\s*/i', '', $content, 1);
        $content = preg_replace('/<h1[^>]*>.*?<\/h1>/is', '', $content, 1);
        $content = preg_replace('/<\/div>\s*$/i', '', $content, 1);

        echo $content;
    }
}
