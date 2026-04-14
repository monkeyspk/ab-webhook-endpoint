<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Tägliches Controlling-Mail an Admins mit kritischen Zuständen:
 * - Probetrainings in der Vergangenheit, bei denen der Status noch
 *   "Probetraining" ist (sollte längst auf schuelerin/abgelehnt/... sein)
 * - Bestellungen im Status "Vertrag verschickt", die seit > X Tagen
 *   dort hängen
 *
 * Bietet eine Admin-Settings-Seite zur Konfiguration und einen
 * Test-Button um den Report sofort zu versenden.
 */
class AB_Controlling_Report {

    const CRON_HOOK   = 'ab_controlling_report_check';
    const OPTION_KEY  = 'ab_controlling_report_settings';

    public static function init() {
        add_action(self::CRON_HOOK, [__CLASS__, 'send_report']);
        add_action('action_scheduler_init', [__CLASS__, 'schedule_with_action_scheduler']);
        add_action('init', [__CLASS__, 'maybe_schedule_wp_cron']);

        add_action('admin_menu', [__CLASS__, 'add_admin_page']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_post_ab_send_test_controlling_report', [__CLASS__, 'handle_test_send']);
    }

    public static function default_settings() {
        return [
            'enabled'                    => 0,
            'recipients'                 => get_option('admin_email'),
            'vertragverschickt_days'     => 14,
            'run_hour'                   => 8,
        ];
    }

    public static function get_settings() {
        $saved = get_option(self::OPTION_KEY, []);
        return wp_parse_args(is_array($saved) ? $saved : [], self::default_settings());
    }

    // =========================================================================
    // Scheduling
    // =========================================================================

    public static function schedule_with_action_scheduler() {
        $wp_cron_ts = wp_next_scheduled(self::CRON_HOOK);
        if ($wp_cron_ts) {
            wp_unschedule_event($wp_cron_ts, self::CRON_HOOK);
        }
        if (as_next_scheduled_action(self::CRON_HOOK) === false) {
            $settings = self::get_settings();
            $hour = max(0, min(23, intval($settings['run_hour'])));
            $timezone = new DateTimeZone(wp_timezone_string());
            $tomorrow = new DateTime('tomorrow ' . sprintf('%02d:00', $hour), $timezone);
            as_schedule_recurring_action(
                $tomorrow->getTimestamp(),
                DAY_IN_SECONDS,
                self::CRON_HOOK,
                [],
                'ab-controlling-report'
            );
        }
    }

    public static function maybe_schedule_wp_cron() {
        if (function_exists('as_next_scheduled_action')) return;
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), 'daily', self::CRON_HOOK);
        }
    }

    public static function get_next_run_formatted() {
        $next_ts = 0;
        if (function_exists('as_next_scheduled_action')) {
            $next_ts = as_next_scheduled_action(self::CRON_HOOK);
        }
        if (!$next_ts) {
            $next_ts = wp_next_scheduled(self::CRON_HOOK);
        }
        if (!$next_ts || !is_numeric($next_ts)) {
            return '';
        }
        if ($next_ts < time()) {
            $settings = self::get_settings();
            $hour = max(0, min(23, intval($settings['run_hour'])));
            $timezone = new DateTimeZone(wp_timezone_string());
            $tomorrow = new DateTime('tomorrow ' . sprintf('%02d:00', $hour), $timezone);
            $next_ts = $tomorrow->getTimestamp();
        }
        return date_i18n('d.m.Y H:i', $next_ts + (int) get_option('gmt_offset') * HOUR_IN_SECONDS);
    }

    // =========================================================================
    // Datenerhebung
    // =========================================================================

    /**
     * Sammelt alle kritischen Zustände die im Report enthalten sein sollen.
     *
     * @return array
     */
    public static function collect_report_data() {
        $settings = self::get_settings();
        $today = date('Y-m-d');

        // 1) Probetrainings in der Vergangenheit, Status noch probetraining
        $probetrainings = wc_get_orders([
            'status' => 'probetraining',
            'limit'  => -1,
        ]);

        $overdue_probetrainings = [];
        foreach ($probetrainings as $order) {
            $event_date = self::get_order_event_date($order);
            if (!$event_date) {
                continue;
            }
            if ($event_date < $today) {
                $overdue_probetrainings[] = [
                    'order'      => $order,
                    'event_date' => $event_date,
                    'days_ago'   => (int) ((strtotime($today) - strtotime($event_date)) / DAY_IN_SECONDS),
                ];
            }
        }

        // Nach Datum sortieren (älteste zuerst — am kritischsten)
        usort($overdue_probetrainings, function($a, $b) {
            return strcmp($a['event_date'], $b['event_date']);
        });

        // 2) Vertrag verschickt seit > X Tagen
        $vertrag_days = max(1, intval($settings['vertragverschickt_days']));
        $threshold_ts = strtotime("-{$vertrag_days} days");

        $vertragverschickt = wc_get_orders([
            'status' => 'vertragverschickt',
            'limit'  => -1,
        ]);

        $overdue_vertragverschickt = [];
        $total_vertragverschickt = count($vertragverschickt);

        foreach ($vertragverschickt as $order) {
            $status_date = self::get_status_change_date($order, 'vertragverschickt');
            if (!$status_date) {
                // Fallback: Order-Modified-Date
                $status_ts = strtotime($order->get_date_modified()->date('Y-m-d H:i:s'));
            } else {
                $status_ts = $status_date->getTimestamp();
            }

            if ($status_ts <= $threshold_ts) {
                $overdue_vertragverschickt[] = [
                    'order'    => $order,
                    'since'    => date_i18n('Y-m-d', $status_ts),
                    'days_ago' => (int) ((time() - $status_ts) / DAY_IN_SECONDS),
                ];
            }
        }

        usort($overdue_vertragverschickt, function($a, $b) {
            return strcmp($a['since'], $b['since']);
        });

        return [
            'overdue_probetrainings'   => $overdue_probetrainings,
            'total_vertragverschickt'  => $total_vertragverschickt,
            'overdue_vertragverschickt' => $overdue_vertragverschickt,
            'threshold_days'           => $vertrag_days,
        ];
    }

    /**
     * Holt das Event-Datum einer Order aus dem ersten Order-Item.
     */
    private static function get_order_event_date($order) {
        foreach ($order->get_items() as $item) {
            $date = $item->get_meta('_event_date');
            if (!empty($date)) {
                // Format kann 'DD-MM-YYYY' oder 'YYYY-MM-DD' sein
                $date = str_replace('.', '-', $date);
                $parts = explode('-', $date);
                if (count($parts) === 3) {
                    if (strlen($parts[0]) === 4) {
                        return $date; // bereits YYYY-MM-DD
                    }
                    return $parts[2] . '-' . str_pad($parts[1], 2, '0', STR_PAD_LEFT) . '-' . str_pad($parts[0], 2, '0', STR_PAD_LEFT);
                }
            }
        }
        return '';
    }

    /**
     * Datum des letzten Statuswechsels zu $target_status aus Order-Notes.
     */
    private static function get_status_change_date($order, $target_status) {
        $notes = wc_get_order_notes([
            'order_id' => $order->get_id(),
            'type'     => 'internal',
        ]);
        foreach ($notes as $note) {
            if (stripos($note->content, $target_status) !== false) {
                return new WC_DateTime($note->date_created->date('Y-m-d H:i:s'));
            }
        }
        return null;
    }

    // =========================================================================
    // Mail-Versand
    // =========================================================================

    public static function send_report($force = false) {
        $settings = self::get_settings();
        if (!$force && empty($settings['enabled'])) {
            return false;
        }

        $recipients = self::parse_recipients($settings['recipients']);
        if (empty($recipients)) {
            error_log('[AB Controlling Report] Keine Empfänger konfiguriert.');
            return false;
        }

        $data = self::collect_report_data();
        $html = self::render_html($data);
        $subject = self::build_subject($data);

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
        ];

        $sender_name = get_option('blogname');
        $sender_email = get_option('admin_email');
        $headers[] = 'From: ' . $sender_name . ' <' . $sender_email . '>';

        $sent = wp_mail($recipients, $subject, $html, $headers);

        if ($sent) {
            error_log('[AB Controlling Report] Report gesendet an: ' . implode(', ', $recipients));
        } else {
            error_log('[AB Controlling Report] Versand fehlgeschlagen.');
        }

        return $sent;
    }

    private static function parse_recipients($raw) {
        $parts = preg_split('/[,;\s]+/', (string) $raw);
        $emails = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if (is_email($p)) {
                $emails[] = $p;
            }
        }
        return array_unique($emails);
    }

    private static function build_subject($data) {
        $overdue_pt = count($data['overdue_probetrainings']);
        $overdue_vv = count($data['overdue_vertragverschickt']);
        $total_issues = $overdue_pt + $overdue_vv;

        $site = get_bloginfo('name');
        if ($total_issues === 0) {
            return '[' . $site . '] Controlling-Report: alles in Ordnung';
        }
        return '[' . $site . '] Controlling-Report: ' . $total_issues . ' Auffälligkeit' . ($total_issues === 1 ? '' : 'en');
    }

    private static function render_html($data) {
        ob_start();
        ?>
        <!DOCTYPE html>
        <html><head><meta charset="UTF-8"></head>
        <body style="font-family:Arial,sans-serif;color:#1d1d1f;max-width:640px;margin:0 auto;padding:24px;background:#f5f5f7;">
        <div style="background:#fff;padding:24px;border-radius:12px;">
            <h1 style="margin:0 0 8px;font-size:22px;">ParkourONE Controlling-Report</h1>
            <p style="color:#666;margin:0 0 24px;font-size:14px;">Stand: <?php echo date_i18n('d.m.Y H:i'); ?> Uhr</p>

            <h2 style="font-size:18px;margin:24px 0 8px;">
                🚨 Probetrainings in Vergangenheit mit Status „Probetraining"
            </h2>
            <?php if (empty($data['overdue_probetrainings'])): ?>
                <p style="color:#00a32a;">✓ Keine überfälligen Probetrainings — alle Teilnehmer wurden korrekt in Folge-Status überführt.</p>
            <?php else: ?>
                <p style="color:#d63638;font-weight:600;">
                    <?php echo count($data['overdue_probetrainings']); ?> Bestellung<?php echo count($data['overdue_probetrainings']) === 1 ? '' : 'en'; ?>
                    mit vergangenem Event-Datum — Status wurde nicht aktualisiert!
                </p>
                <table style="width:100%;border-collapse:collapse;margin-top:8px;font-size:13px;">
                    <thead><tr style="background:#f5f5f7;">
                        <th style="text-align:left;padding:8px;border:1px solid #ddd;">Order</th>
                        <th style="text-align:left;padding:8px;border:1px solid #ddd;">Event-Datum</th>
                        <th style="text-align:left;padding:8px;border:1px solid #ddd;">Vor</th>
                        <th style="text-align:left;padding:8px;border:1px solid #ddd;">Name</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($data['overdue_probetrainings'] as $entry):
                        $o = $entry['order']; ?>
                        <tr>
                            <td style="padding:8px;border:1px solid #ddd;">
                                <a href="<?php echo esc_url($o->get_edit_order_url()); ?>" style="color:#0066cc;">#<?php echo esc_html($o->get_order_number()); ?></a>
                            </td>
                            <td style="padding:8px;border:1px solid #ddd;"><?php echo esc_html(date_i18n('d.m.Y', strtotime($entry['event_date']))); ?></td>
                            <td style="padding:8px;border:1px solid #ddd;color:#d63638;font-weight:600;"><?php echo $entry['days_ago']; ?> Tagen</td>
                            <td style="padding:8px;border:1px solid #ddd;"><?php echo esc_html($o->get_billing_first_name() . ' ' . $o->get_billing_last_name()); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h2 style="font-size:18px;margin:32px 0 8px;">
                📬 Status „Vertrag verschickt"
            </h2>
            <p style="color:#666;margin:0 0 8px;">
                Insgesamt <strong><?php echo $data['total_vertragverschickt']; ?></strong>
                Bestellung<?php echo $data['total_vertragverschickt'] === 1 ? '' : 'en'; ?>
                im Status „Vertrag verschickt".
            </p>

            <?php if (empty($data['overdue_vertragverschickt'])): ?>
                <p style="color:#00a32a;">✓ Keine Bestellungen hängen länger als <?php echo intval($data['threshold_days']); ?> Tage im Status „Vertrag verschickt".</p>
            <?php else: ?>
                <p style="color:#d63638;font-weight:600;">
                    <?php echo count($data['overdue_vertragverschickt']); ?> Bestellung<?php echo count($data['overdue_vertragverschickt']) === 1 ? '' : 'en'; ?>
                    hängt länger als <?php echo intval($data['threshold_days']); ?> Tage im Status „Vertrag verschickt".
                </p>
                <table style="width:100%;border-collapse:collapse;margin-top:8px;font-size:13px;">
                    <thead><tr style="background:#f5f5f7;">
                        <th style="text-align:left;padding:8px;border:1px solid #ddd;">Order</th>
                        <th style="text-align:left;padding:8px;border:1px solid #ddd;">Seit</th>
                        <th style="text-align:left;padding:8px;border:1px solid #ddd;">Tage offen</th>
                        <th style="text-align:left;padding:8px;border:1px solid #ddd;">Name</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($data['overdue_vertragverschickt'] as $entry):
                        $o = $entry['order']; ?>
                        <tr>
                            <td style="padding:8px;border:1px solid #ddd;">
                                <a href="<?php echo esc_url($o->get_edit_order_url()); ?>" style="color:#0066cc;">#<?php echo esc_html($o->get_order_number()); ?></a>
                            </td>
                            <td style="padding:8px;border:1px solid #ddd;"><?php echo esc_html(date_i18n('d.m.Y', strtotime($entry['since']))); ?></td>
                            <td style="padding:8px;border:1px solid #ddd;color:#d63638;font-weight:600;"><?php echo $entry['days_ago']; ?></td>
                            <td style="padding:8px;border:1px solid #ddd;"><?php echo esc_html($o->get_billing_first_name() . ' ' . $o->get_billing_last_name()); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <hr style="border:none;border-top:1px solid #eee;margin:32px 0;" />
            <p style="font-size:12px;color:#999;">
                Dieser Report wird automatisch täglich versendet. Einstellungen unter
                ParkourONE → Controlling-Report.
            </p>
        </div>
        </body></html>
        <?php
        return ob_get_clean();
    }

    // =========================================================================
    // Admin-Settings-Seite
    // =========================================================================

    public static function add_admin_page() {
        add_submenu_page(
            'parkourone',
            'Controlling-Report',
            'Controlling-Report',
            'manage_woocommerce',
            'ab-controlling-report',
            [__CLASS__, 'render_settings_page']
        );
    }

    public static function register_settings() {
        register_setting(self::OPTION_KEY, self::OPTION_KEY, [
            'type' => 'array',
            'sanitize_callback' => [__CLASS__, 'sanitize_settings'],
        ]);
    }

    public static function sanitize_settings($input) {
        $out = self::default_settings();
        $out['enabled']                = !empty($input['enabled']) ? 1 : 0;
        $out['recipients']             = sanitize_text_field($input['recipients'] ?? '');
        $out['vertragverschickt_days'] = max(1, intval($input['vertragverschickt_days'] ?? 14));
        $out['run_hour']               = max(0, min(23, intval($input['run_hour'] ?? 8)));
        return $out;
    }

    public static function render_settings_page() {
        if (!current_user_can('manage_woocommerce')) return;

        $settings = self::get_settings();
        $data = self::collect_report_data();
        $next_run = self::get_next_run_formatted();

        $notice = '';
        if (isset($_GET['settings-updated'])) {
            $notice = '<div class="notice notice-success is-dismissible"><p><strong>Einstellungen gespeichert.</strong></p></div>';
        }
        if (isset($_GET['test_sent'])) {
            $notice = '<div class="notice notice-success is-dismissible"><p><strong>Test-Report versendet</strong> an <code>' . esc_html($_GET['test_sent']) . '</code>.</p></div>';
        }
        if (isset($_GET['test_error'])) {
            $notice = '<div class="notice notice-error is-dismissible"><p><strong>Fehler:</strong> ' . esc_html($_GET['test_error']) . '</p></div>';
        }
        ?>
        <div class="wrap">
            <h1>Controlling-Report</h1>
            <?php echo $notice; ?>
            <p>Tägliche Kontroll-Mail mit kritischen Zuständen, damit niemand in einem Status „hängen" bleibt.</p>

            <form method="post" action="options.php">
                <?php settings_fields(self::OPTION_KEY); ?>

                <table class="form-table">
                    <tr>
                        <th>Report aktivieren</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo self::OPTION_KEY; ?>[enabled]" value="1" <?php checked($settings['enabled'], 1); ?> />
                                Tägliches Kontroll-Mail versenden
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th><label>Empfänger</label></th>
                        <td>
                            <input type="text" name="<?php echo self::OPTION_KEY; ?>[recipients]" value="<?php echo esc_attr($settings['recipients']); ?>" class="regular-text" />
                            <p class="description">Eine oder mehrere E-Mail-Adressen, getrennt durch Komma oder Semikolon.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label>Vertrag-verschickt Schwelle</label></th>
                        <td>
                            <input type="number" name="<?php echo self::OPTION_KEY; ?>[vertragverschickt_days]" value="<?php echo esc_attr($settings['vertragverschickt_days']); ?>" min="1" max="90" class="small-text" /> Tage
                            <p class="description">Ab wievielen Tagen im Status „Vertrag verschickt" soll gewarnt werden (Default: 14).</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label>Uhrzeit des Versands</label></th>
                        <td>
                            <input type="number" name="<?php echo self::OPTION_KEY; ?>[run_hour]" value="<?php echo esc_attr($settings['run_hour']); ?>" min="0" max="23" class="small-text" /> Uhr
                            <p class="description">Stunde des Tages, zu der der Report versendet wird (0–23).</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button('Einstellungen speichern'); ?>
            </form>

            <hr style="margin:32px 0;" />

            <h2>Aktueller Zustand (Live-Daten)</h2>
            <div style="padding:16px;background:<?php echo (count($data['overdue_probetrainings']) + count($data['overdue_vertragverschickt']) > 0) ? '#fcf0f1' : '#f0f9ec'; ?>;border:1px solid <?php echo (count($data['overdue_probetrainings']) + count($data['overdue_vertragverschickt']) > 0) ? '#f0c0c2' : '#c3d9a4'; ?>;border-radius:6px;">
                <ul style="margin:0;padding-left:18px;">
                    <li><strong><?php echo count($data['overdue_probetrainings']); ?></strong> überfällige Probetrainings (Event-Datum in Vergangenheit, Status noch „Probetraining")</li>
                    <li><strong><?php echo $data['total_vertragverschickt']; ?></strong> Bestellungen insgesamt im Status „Vertrag verschickt"</li>
                    <li>Davon <strong><?php echo count($data['overdue_vertragverschickt']); ?></strong> seit mehr als <?php echo intval($data['threshold_days']); ?> Tagen offen</li>
                </ul>
                <?php if ($next_run): ?>
                <p style="margin:12px 0 0;color:#666;font-size:12px;">⏰ Nächster automatischer Report: <strong><?php echo esc_html($next_run); ?> Uhr</strong></p>
                <?php endif; ?>
            </div>

            <h3 style="margin-top:24px;">Test-Versand</h3>
            <p>Sendet den aktuellen Report-Stand jetzt an eine beliebige Adresse (ohne dass der Report aktiviert sein muss).</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="ab_send_test_controlling_report" />
                <?php wp_nonce_field('ab_controlling_report_test'); ?>
                <input type="email" name="test_recipient" value="<?php echo esc_attr(wp_get_current_user()->user_email); ?>" class="regular-text" placeholder="test@beispiel.de" required />
                <button type="submit" class="button button-primary">Test-Report jetzt senden</button>
            </form>
        </div>
        <?php
    }

    public static function handle_test_send() {
        if (!current_user_can('manage_woocommerce')) wp_die('Keine Berechtigung');
        check_admin_referer('ab_controlling_report_test');

        $recipient = sanitize_email($_POST['test_recipient'] ?? '');
        if (!is_email($recipient)) {
            wp_safe_redirect(add_query_arg([
                'page'       => 'ab-controlling-report',
                'test_error' => 'Ungültige E-Mail-Adresse',
            ], admin_url('admin.php')));
            exit;
        }

        $data = self::collect_report_data();
        $html = self::render_html($data);
        $subject = '[TEST] ' . self::build_subject($data);

        $sender_name = get_option('blogname');
        $sender_email = get_option('admin_email');
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $sender_name . ' <' . $sender_email . '>',
        ];

        $sent = wp_mail($recipient, $subject, $html, $headers);

        $args = ['page' => 'ab-controlling-report'];
        if ($sent) {
            $args['test_sent'] = $recipient;
        } else {
            $args['test_error'] = 'Versand fehlgeschlagen';
        }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }
}
