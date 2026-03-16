<?php
/**
 * Update-Manager für das Plugin
 * Prüft auf Updates im zentralen Repository und wendet sie an
 */

if (!defined('ABSPATH')) {
    exit;
}

class Plugin_Update_Manager {
    private $plugin_file;
    private $plugin_slug;
    private $current_version;
    private $central_dir;

    public function __construct($plugin_file, $plugin_slug) {
        $this->plugin_file = $plugin_file;
        $this->plugin_slug = $plugin_slug;

        // Plugin-Daten auslesen
        $plugin_data = get_plugin_data($plugin_file);
        $this->current_version = $plugin_data['Version'];

        // Zentralen Ordner finden
        $this->find_central_directory();

        // Update-Check bei Admin-Initialisierung
        add_action('admin_init', array($this, 'check_for_updates'));
    }

    private function find_central_directory() {
        // Verschiedene Methoden, um den zentralen Ordner zu finden
        $central_dir = ABSPATH . '../zentral-plugins/' . $this->plugin_slug . '/';

        if (!file_exists($central_dir)) {
            $central_dir = $_SERVER['DOCUMENT_ROOT'] . '/../zentral-plugins/' . $this->plugin_slug . '/';
        }

        $this->central_dir = file_exists($central_dir) ? $central_dir : false;

        if (!$this->central_dir) {
            error_log('Update Manager: Zentraler Plugin-Ordner nicht gefunden für: ' . $this->plugin_slug);
        }
    }

    public function check_for_updates() {
        if (!$this->central_dir) {
            return;
        }

        $version_file = $this->central_dir . 'version.txt';

        if (file_exists($version_file)) {
            $central_version = trim(file_get_contents($version_file));

            if (version_compare($central_version, $this->current_version, '>')) {
                $this->update_from_central();
                $this->show_update_notice();
            }
        }
    }

    private function update_from_central() {
        $plugin_dir = plugin_dir_path($this->plugin_file);

        // Rekursiv alle Dateien kopieren, außer update-manager.php
        if (is_dir($this->central_dir)) {
            $dir_iterator = new RecursiveDirectoryIterator($this->central_dir, RecursiveDirectoryIterator::SKIP_DOTS);
            $iterator = new RecursiveIteratorIterator($dir_iterator, RecursiveIteratorIterator::SELF_FIRST);

            foreach ($iterator as $file) {
                $dest = $plugin_dir . substr($file->getPathname(), strlen($this->central_dir));

                if ($iterator->isDir()) {
                    if (!is_dir($dest)) {
                        mkdir($dest, 0755, true);
                    }
                } else {
                    $file_path = $file->getPathname();
                    $file_name = basename($file_path);

                    // Update-Manager nicht überschreiben
                    if ($file_name !== 'update-manager.php') {
                        copy($file_path, $dest);
                    }
                }
            }

            update_option($this->plugin_slug . '_last_update', time());
        }
    }

    private function show_update_notice() {
        $plugin_data = get_plugin_data($this->plugin_file);
        $plugin_name = $plugin_data['Name'];

        add_action('admin_notices', function() use ($plugin_name) {
            ?>
            <div class="notice notice-success is-dismissible">
                <p><?php echo sprintf(__('%s wurde automatisch aktualisiert.'), $plugin_name); ?></p>
            </div>
            <?php
        });
    }
}
