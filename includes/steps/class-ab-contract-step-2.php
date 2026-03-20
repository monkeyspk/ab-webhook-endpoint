<?php
if (!defined('ABSPATH')) {
    exit;
}

class AB_Contract_Step_2 {
    public static function render($order, $contract_id) {
        $post_content = get_post_field('post_content', $contract_id);
        $preis = get_post_meta($contract_id, '_ab_vertrag_preis', true);
        // Individueller Preis überschreibt Standard
        $custom_price = get_post_meta($order->get_id(), '_ab_custom_price', true);
        if ($custom_price !== '' && $custom_price !== false) {
            $preis = $custom_price;
        }
        $accordion_basic = get_post_meta($contract_id, '_ab_accordion_basic', true);
        $accordion_training = get_post_meta($contract_id, '_ab_accordion_training', true);
        $accordion_conditions = get_post_meta($contract_id, '_ab_accordion_conditions', true);

        $accordion_titles = [
                    'basic' => get_post_meta($contract_id, '_ab_accordion_title_basic', true) ?: 'Allgemeine Vertragsbedingungen',
                    'training' => get_post_meta($contract_id, '_ab_accordion_title_training', true) ?: 'Leistungen ParkourONE',
                    'conditions' => get_post_meta($contract_id, '_ab_accordion_title_conditions', true) ?: 'Abwesenheit / Abmeldung',
                ];

        ob_start();
        ?>
        <form method="post" class="contract-wizard-form">
            <input type="hidden" name="form_action" value="save_step2">
            <input type="hidden" name="current_step" value="2">
            <input type="hidden" name="order_id" value="<?php echo esc_attr($order->get_id()); ?>">
            <input type="hidden" name="token" value="<?php echo isset($_GET['token']) ? esc_attr($_GET['token']) : ''; ?>">


            <?php wp_nonce_field('contract_wizard_nonce', 'nonce'); ?>

            <div class="contract-content">
                <h3>Vertragsbedingungen</h3>

                <div class="contract-text scrollable">
                    <?php echo wpautop(wp_kses_post($post_content)); ?>
                </div>

                <div class="contract-details">
                  <div class="accordion">
                                          <button type="button" class="accordion-toggle">
                                              <?php echo esc_html($accordion_titles['basic']); ?>
                                          </button>
                                          <div class="accordion-content">
                                              <?php echo wp_kses_post($accordion_basic); ?>
                                          </div>
                                      </div>
                                      <div class="accordion">
                                          <button type="button" class="accordion-toggle">
                                              <?php echo esc_html($accordion_titles['training']); ?>
                                          </button>
                                          <div class="accordion-content">
                                              <?php echo wp_kses_post($accordion_training); ?>
                                          </div>
                                      </div>
                                      <div class="accordion">
                                          <button type="button" class="accordion-toggle">
                                              <?php echo esc_html($accordion_titles['conditions']); ?>
                                          </button>
                                          <div class="accordion-content">
                                              <?php echo wp_kses_post($accordion_conditions); ?>
                                          </div>
                                      </div>
                                  </div>
                              </div>

            <div class="form-row agree-section">
                <div class="form-row checkbox-container">
                    <input type="checkbox" id="agb_confirm" name="agb_confirm" required>
                    <label for="agb_confirm">
                        Ich habe die <a href="/AGB" target="_blank" class="privacy-link">AGB</a>, die <a href="/datenschutz" target="_blank" class="privacy-link">Datenschutzbestimmungen</a> und die Vertragsbedingungen gelesen und akzeptiere diese.
                    </label>
                </div>
            </div>


            <?php echo AB_Contract_Wizard::get_navigation_buttons(2); ?>
        </form>
        <?php
        return ob_get_clean();
    }
}
