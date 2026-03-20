<?php
if (!defined('ABSPATH')) {
    exit;
}

class AB_Contract_Overview {
    public static function render($contract_id, $order_id = null) {
        // order_id aus der URL auslesen, falls nicht übergeben
        if (!$order_id && isset($_GET['order_id'])) {
            $order_id = intval($_GET['order_id']);
        }

        $details = self::get_contract_details($contract_id, $order_id);
        $training_name = '';

        if ($order_id) {
            $order = wc_get_order($order_id);
            if ($order) {
                $training_name = self::get_training_name_from_order($order);
            }
        }

        ob_start();
        ?>
        <div class="contract-overview">

            <div class="overview-toggle">
                <h2 class="overview-title">Dein Vertrag im Überblick</h2>
            </div>
            <?php
            if (!empty($details['vertrag_bild'])) {
                echo '<div class="overview-image-container">';
                // Entferne alle inline width/height Attribute und setze loading="eager"
                echo wp_get_attachment_image($details['vertrag_bild'], 'full', false, [
                    'class' => 'overview-image',
                    'loading' => 'eager'
                ]);
                echo '</div>';
            }
            ?>
            <div class="overview-content">
                <?php if ($training_name): ?>
                    <h3 class="training-name">Vertrag: <?php echo esc_html($training_name); ?></h3>
                <?php else: ?>
                    <h3 class="training-name">Vertrag: Unbekanntes Training</h3>
                <?php endif; ?>
                <div class="overview-header">
                    <h4 class="monthly-fee-title">Dein Monatsbeitrag</h4>
                    <p class="price"><?php echo esc_html($details['vertrag_preis']); ?> <?php echo get_woocommerce_currency_symbol(); ?></p>
                </div>
                <div class="overview-details">
                    <?php self::render_detail('Trainingsumfang', $details['trainingsumfang']); ?>
                    <?php self::render_detail('Verlängerung', $details['verlaengerung']); ?>
                    <?php self::render_detail('Kündigungsfrist', $details['kuendigungsfrist']); ?>
                    <?php self::render_detail('Probezeit', $details['probezeit']); ?>
                </div>
            </div>
        </div>
        <script>
        document.querySelector('.overview-toggle').addEventListener('click', function() {
            this.closest('.contract-overview').classList.toggle('expanded');
        });
        </script>
        <?php
        return ob_get_clean();
    }

    public static function get_contract_details($contract_id, $order_id = null) {
        $details = [
            'vertrag_preis' => get_post_meta($contract_id, '_ab_vertrag_preis', true),
            'trainingsumfang' => get_post_meta($contract_id, '_ab_trainingsumfang', true),
            'verlaengerung' => get_post_meta($contract_id, '_ab_verlaengerung', true),
            'kuendigungsfrist' => get_post_meta($contract_id, '_ab_kuendigungsfrist', true),
            'probezeit' => get_post_meta($contract_id, '_ab_probezeit', true),
            'event_match' => get_post_meta($contract_id, '_ab_event_description', true),
            'vertrag_bild' => get_post_meta($contract_id, '_ab_vertrag_bild', true)
        ];

        // Individueller Preis überschreibt den Standard-Vertragspreis
        if ($order_id) {
            $custom_price = get_post_meta($order_id, '_ab_custom_price', true);
            if ($custom_price !== '' && $custom_price !== false) {
                $details['vertrag_preis'] = $custom_price;
            }
        }

        return $details;
    }



    private static function get_training_name_from_order($order) {
        global $ab_current_order;
        $ab_current_order = $order;

        // 1. Versuche '_event_title_clean' von der Bestellung
        $clean_event_title = $order->get_meta('_event_title_clean');
        if (!empty($clean_event_title)) {
            return $clean_event_title;
        }

        // 2. Versuche normalen Event-Titel aus Bestellposition
        foreach ($order->get_items() as $item) {
            if ($title = $item->get_meta('_event_title_clean')) {
                return $title;
            }
        }

        // 3. Fallback auf Produktname
        foreach ($order->get_items() as $item) {
            if ($product = $item->get_product()) {
                return $product->get_name();
            }
        }

        return 'Unbekanntes Training';
    }


    private static function render_detail($label, $value) {
        if (!$value) return;
        ?>
        <div class="overview-detail">
            <div class="overview-detail-label"><?php echo esc_html($label); ?></div>
            <div class="overview-detail-value"><?php echo esc_html($value); ?></div>
        </div>
        <?php
    }
}

function add_duplicate_contract_button($actions, $post) {
    if ($post->post_type === 'ab_contract_type') {
        $url = wp_nonce_url(admin_url('admin-post.php?action=duplicate_contract&post=' . $post->ID), 'duplicate_contract_nonce');
        $actions['duplicate'] = '<a href="' . esc_url($url) . '" title="Diesen Vertrag duplizieren">Duplizieren</a>';
    }
    return $actions;
}
add_filter('post_row_actions', 'add_duplicate_contract_button', 10, 2);
