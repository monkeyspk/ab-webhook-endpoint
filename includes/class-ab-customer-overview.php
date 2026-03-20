<?php
if (!defined('ABSPATH')) {
    exit;
}

class AB_Customer_Overview {

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_menu_page']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_scripts']);
        add_action('wp_ajax_ab_get_customer_details', [__CLASS__, 'ajax_get_customer_details']);
    }

    public static function add_menu_page() {
        add_submenu_page(
            'parkourone',
            'Kunden',
            'Kunden',
            'manage_options',
            'ab-customers',
            [__CLASS__, 'render_customers_page']
        );
    }

    public static function enqueue_admin_scripts($hook) {
        if ($hook !== 'parkourone_page_ab-customers') {
            return;
        }

        wp_enqueue_script(
            'ab-customer-admin',
            plugins_url('assets/js/customer-admin.js', dirname(__FILE__)),
            ['jquery'],
            '1.0.0',
            true
        );

        wp_localize_script('ab-customer-admin', 'abCustomerAdmin', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ab_customer_admin')
        ]);

        wp_enqueue_style(
            'ab-customer-admin',
            plugins_url('assets/css/customer-admin.css', dirname(__FILE__)),
            [],
            '1.0.0'
        );
    }

    public static function render_customers_page() {
        ?>
        <div class="wrap">
            <h1>Kundenübersicht</h1>

            <div class="ab-customers-container">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 30%;">E-Mail</th>
                            <th style="width: 20%;">Name</th>
                            <th style="width: 15%;">Anzahl Bestellungen</th>
                            <th style="width: 15%;">Aktive Abos</th>
                            <th style="width: 20%;">Letzter Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php echo self::get_customers_list(); ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Modal für Kundendetails -->
        <div id="ab-customer-modal" class="ab-modal" style="display: none;">
            <div class="ab-modal-content">
                <span class="ab-modal-close">&times;</span>
                <div id="ab-customer-details">
                    <!-- Wird per AJAX geladen -->
                </div>
            </div>
        </div>
        <?php
    }

    private static function get_customers_list() {
        global $wpdb;

        // Hole alle eindeutigen E-Mail-Adressen aus den Bestellungen
        $query = "
            SELECT DISTINCT
                pm.meta_value as email,
                COUNT(DISTINCT p.ID) as order_count,
                MAX(p.post_date) as last_order_date
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = 'shop_order'
            AND pm.meta_key = '_billing_email'
            AND pm.meta_value != ''
            GROUP BY pm.meta_value
            ORDER BY last_order_date DESC
        ";

        $customers = $wpdb->get_results($query);

        $output = '';
        foreach ($customers as $customer) {
            $customer_data = self::get_customer_summary($customer->email);

            $output .= sprintf(
                '<tr class="customer-row" data-email="%s">
                    <td><a href="#" class="view-customer-details">%s</a></td>
                    <td>%s</td>
                    <td>%d</td>
                    <td>%d</td>
                    <td><span class="order-status status-%s">%s</span></td>
                </tr>',
                esc_attr($customer->email),
                esc_html($customer->email),
                esc_html($customer_data['name']),
                $customer_data['order_count'],
                $customer_data['active_subscriptions'],
                esc_attr($customer_data['latest_status_slug']),
                esc_html($customer_data['latest_status'])
            );
        }

        return $output ?: '<tr><td colspan="5">Keine Kunden gefunden.</td></tr>';
    }

    private static function get_customer_summary($email) {
        // Hole die neueste Bestellung für diese E-Mail
        $args = [
            'post_type' => 'shop_order',
            'meta_key' => '_billing_email',
            'meta_value' => $email,
            'posts_per_page' => -1,
            'orderby' => 'date',
            'order' => 'DESC',
            'post_status' => 'any'
        ];

        $orders = get_posts($args);
        $latest_order = !empty($orders) ? $orders[0] : null;

        $name = '';
        $latest_status = '';
        $latest_status_slug = '';
        $active_subscriptions = 0;

        if ($latest_order) {
            $order = wc_get_order($latest_order->ID);
            if ($order) {
                $name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
                $latest_status_slug = $order->get_status();

                // Hole Status-Label
                $statuses = wc_get_order_statuses();
                $latest_status = isset($statuses['wc-' . $latest_status_slug])
                    ? $statuses['wc-' . $latest_status_slug]
                    : $latest_status_slug;

                // Zähle aktive Abos (Status = schuelerin)
                foreach ($orders as $order_post) {
                    $order_obj = wc_get_order($order_post->ID);
                    if ($order_obj && $order_obj->get_status() === 'schuelerin') {
                        $active_subscriptions++;
                    }
                }
            }
        }

        return [
            'name' => $name,
            'order_count' => count($orders),
            'active_subscriptions' => $active_subscriptions,
            'latest_status' => $latest_status,
            'latest_status_slug' => $latest_status_slug
        ];
    }

    public static function ajax_get_customer_details() {
        check_ajax_referer('ab_customer_admin', 'nonce');

        $email = sanitize_email($_POST['email'] ?? '');
        if (!$email) {
            wp_die('Keine E-Mail angegeben');
        }

        // Hole alle Bestellungen für diese E-Mail
        $args = [
            'post_type' => 'shop_order',
            'meta_key' => '_billing_email',
            'meta_value' => $email,
            'posts_per_page' => -1,
            'orderby' => 'date',
            'order' => 'DESC',
            'post_status' => 'any'
        ];

        $orders = get_posts($args);

        if (empty($orders)) {
            wp_die('Keine Bestellungen gefunden');
        }

        // Hole Kundeninfo von der ersten Bestellung
        $first_order = wc_get_order($orders[0]->ID);
        $customer_name = $first_order->get_billing_first_name() . ' ' . $first_order->get_billing_last_name();

        ob_start();
        ?>
        <div class="customer-details-header">
            <h2><?php echo esc_html($customer_name); ?></h2>
            <p><strong>E-Mail:</strong> <?php echo esc_html($email); ?></p>
        </div>

        <div class="customer-details-tabs">
            <button class="tab-button active" data-tab="orders">Bestellungen</button>
            <button class="tab-button" data-tab="subscriptions">Abos</button>
        </div>

        <div class="tab-content" id="orders-tab">
            <h3>Bestellungen</h3>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Bestellung #</th>
                        <th>Datum</th>
                        <th>Training</th>
                        <th>Status</th>
                        <th>Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order_post):
                        $order = wc_get_order($order_post->ID);
                        if (!$order) continue;

                        // Hole Event-Titel
                        $event_title = '';
                        foreach ($order->get_items() as $item) {
                            $event_title = $item->get_meta('_event_title_clean') ?: $item->get_meta('_event_title');
                            if ($event_title) break;
                        }

                        $statuses = wc_get_order_statuses();
                        $status_label = isset($statuses['wc-' . $order->get_status()])
                            ? $statuses['wc-' . $order->get_status()]
                            : $order->get_status();
                    ?>
                        <tr>
                            <td>
                                <a href="<?php echo esc_url(admin_url('post.php?post=' . $order->get_id() . '&action=edit')); ?>">
                                    #<?php echo esc_html($order->get_order_number()); ?>
                                </a>
                            </td>
                            <td><?php echo esc_html($order->get_date_created()->date_i18n('d.m.Y')); ?></td>
                            <td><?php echo esc_html($event_title); ?></td>
                            <td>
                                <span class="order-status status-<?php echo esc_attr($order->get_status()); ?>">
                                    <?php echo esc_html($status_label); ?>
                                </span>
                            </td>
                            <td>
                                <a href="<?php echo esc_url(admin_url('post.php?post=' . $order->get_id() . '&action=edit')); ?>"
                                   class="button button-small">Bearbeiten</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="tab-content" id="subscriptions-tab" style="display: none;">
            <h3>Aktive Abos</h3>
            <?php
            $active_subscriptions = [];
            foreach ($orders as $order_post) {
                $order = wc_get_order($order_post->ID);
                if (!$order || $order->get_status() !== 'schuelerin') continue;

                // Hole Vertragsdetails
                $contract_id = AB_Contract_Wizard::determine_contract_type($order);
                $contract_details = [];
                if ($contract_id) {
                    $contract_details = AB_Contract_Overview::get_contract_details($contract_id, $order->get_id());
                }

                // Hole Event-Details
                foreach ($order->get_items() as $item) {
                    $event_title = $item->get_meta('_event_title_clean') ?: $item->get_meta('_event_title');
                    $event_date = $item->get_meta('_event_date');
                    $event_time = $item->get_meta('_event_time');
                    $event_venue = $item->get_meta('_event_venue');

                    if ($event_title) {
                        $active_subscriptions[] = [
                            'order' => $order,
                            'event_title' => $event_title,
                            'event_date' => $event_date,
                            'event_time' => $event_time,
                            'event_venue' => $event_venue,
                            'contract_details' => $contract_details
                        ];
                        break;
                    }
                }
            }

            if (!empty($active_subscriptions)): ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Training</th>
                            <th>Ort</th>
                            <th>Zeit</th>
                            <th>Monatsbeitrag</th>
                            <th>Status</th>
                            <th>Aktionen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($active_subscriptions as $subscription): ?>
                            <tr>
                                <td><?php echo esc_html($subscription['event_title']); ?></td>
                                <td><?php echo esc_html($subscription['event_venue']); ?></td>
                                <td>
                                    <?php
                                    if ($subscription['event_date'] && $subscription['event_time']) {
                                        echo esc_html($subscription['event_date'] . ' ' . $subscription['event_time']);
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    if (!empty($subscription['contract_details']['vertrag_preis'])) {
                                        echo esc_html($subscription['contract_details']['vertrag_preis'] . ' ' . get_woocommerce_currency_symbol());
                                    }
                                    ?>
                                </td>
                                <td>
                                    <span class="order-status status-schuelerin">Schüler_in</span>
                                </td>
                                <td>
                                    <a href="<?php echo esc_url(admin_url('post.php?post=' . $subscription['order']->get_id() . '&action=edit')); ?>"
                                       class="button button-small">Details</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>Keine aktiven Abos vorhanden.</p>
            <?php endif; ?>
        </div>
        <?php

        wp_die(ob_get_clean());
    }
}
