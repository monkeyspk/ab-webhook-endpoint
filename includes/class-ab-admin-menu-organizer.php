<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Organisiert die Plugin-Menüpunkte unter dem ParkourONE-Menü
 * in visuell getrennte Gruppen mit Section-Headern.
 */
class AB_Admin_Menu_Organizer {

    /**
     * Gruppen-Definition: welche Menü-Slugs gehören zusammen
     */
    private static $groups = [
        'events' => [
            'label' => 'Events',
            'items' => [
                'edit.php?post_type=event',
                'post-new.php?post_type=event',
                'edit-tags.php?taxonomy=event_category&post_type=event',
                'event-admin',
            ]
        ],
        'contracts' => [
            'label' => 'Verträge & Kunden',
            'items' => [
                'edit.php?post_type=ab_contract_type',
                'post-new.php?post_type=ab_contract_type',
                'ab-customers',
                'ab-schueler-uebersicht',
                'ab-email-customizer',
                'ab-gutschein-settings',
                'ab-bestandskunden-import',
                'ab-settings',
            ]
        ],
        'system' => [
            'label' => 'System',
            'items' => [
                'parkourone-updates',
            ]
        ],
    ];

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'organize_menu'], 999);
        add_action('admin_head', [__CLASS__, 'add_styles']);
    }

    /**
     * Sortiert die Submenu-Einträge in Gruppen und fügt Header ein
     */
    public static function organize_menu() {
        global $submenu;

        if (!isset($submenu['parkourone'])) {
            return;
        }

        $current_items = $submenu['parkourone'];
        $theme_items = [];
        $grouped_items = [];

        // Items nach Gruppen sortieren
        foreach ($current_items as $item) {
            $slug = $item[2];
            $assigned = false;

            foreach (self::$groups as $group_key => $group) {
                if (in_array($slug, $group['items'])) {
                    $grouped_items[$group_key][] = $item;
                    $assigned = true;
                    break;
                }
            }

            if (!$assigned) {
                $theme_items[] = $item;
            }
        }

        // Menü neu aufbauen: Theme-Items → Gruppen mit Headern
        $new_submenu = $theme_items;

        foreach (self::$groups as $group_key => $group) {
            if (!empty($grouped_items[$group_key])) {
                // Gruppen-Header einfügen
                $new_submenu[] = [
                    $group['label'],
                    'read',
                    '#ab-section-' . $group_key,
                    '',
                ];

                // Gruppen-Items einfügen (in definierter Reihenfolge)
                $ordered = [];
                foreach ($group['items'] as $expected_slug) {
                    foreach ($grouped_items[$group_key] as $item) {
                        if ($item[2] === $expected_slug) {
                            $ordered[] = $item;
                        }
                    }
                }
                foreach ($ordered as $item) {
                    $new_submenu[] = $item;
                }
            }
        }

        $submenu['parkourone'] = $new_submenu;
    }

    /**
     * CSS für die Gruppen-Header im Admin-Menü
     */
    public static function add_styles() {
        ?>
        <style>
            /* Gruppen-Header im ParkourONE Submenu */
            #adminmenu .wp-submenu a[href^="#ab-section-"] {
                color: #a0a5aa !important;
                font-weight: 600 !important;
                font-size: 10px !important;
                text-transform: uppercase;
                letter-spacing: 0.8px;
                pointer-events: none;
                cursor: default;
                padding-top: 10px !important;
                padding-bottom: 2px !important;
                margin-top: 4px;
                border-top: 1px solid rgba(255, 255, 255, 0.08);
            }
            #adminmenu .wp-submenu a[href^="#ab-section-"]:hover,
            #adminmenu .wp-submenu a[href^="#ab-section-"]:focus {
                color: #a0a5aa !important;
                background: transparent !important;
            }
            /* Verstecke den Bullet-Point */
            #adminmenu .wp-submenu li a[href^="#ab-section-"]::before {
                display: none;
            }
        </style>
        <?php
    }
}
