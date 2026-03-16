jQuery(document).ready(function($) {
    var selectedAmount = 0;

    // --- Preset-Button Auswahl ---
    $('.ab-gutschein-amount-btn').on('click', function() {
        $('.ab-gutschein-amount-btn').removeClass('selected');
        $(this).addClass('selected');
        $('#ab-gutschein-custom-input').val('');
        selectedAmount = parseFloat($(this).data('amount'));
        updatePreview();
        updateButtonState();
    });

    // --- Custom Betrag ---
    $('#ab-gutschein-custom-input').on('input', function() {
        var val = parseFloat($(this).val());
        if (val && val > 0) {
            $('.ab-gutschein-amount-btn').removeClass('selected');
            selectedAmount = val;
        } else {
            selectedAmount = 0;
        }
        updatePreview();
        updateButtonState();
    });

    // --- Preview-Karte aktualisieren ---
    function updatePreview() {
        var formatted = selectedAmount.toFixed(2).replace('.', ',') + ' \u20AC';
        $('.ab-gutschein-card-amount').text(formatted);
    }

    // --- Button-State ---
    function updateButtonState() {
        var minAmount = parseFloat($('#ab-gutschein-custom-input').attr('min')) || 1;
        var maxAmount = parseFloat($('#ab-gutschein-custom-input').attr('max')) || 9999;
        var valid = selectedAmount >= minAmount && selectedAmount <= maxAmount;
        $('#ab-gutschein-add-to-cart').prop('disabled', !valid);
    }

    // --- Add to Cart ---
    var isSubmitting = false;

    $('#ab-gutschein-add-to-cart').on('click', function(e) {
        e.preventDefault();

        if (isSubmitting) return;
        isSubmitting = true;

        var $btn = $(this);
        var originalText = $btn.text();
        $btn.prop('disabled', true).addClass('loading').text('Wird hinzugefuegt...');

        var data = {
            action: 'ab_gutschein_add_to_cart',
            nonce: $('#ab-gutschein-nonce').val(),
            product_id: $('#ab-gutschein-product-id').val(),
            amount: selectedAmount,
            recipient_email: $('#ab-gutschein-recipient-email').val().trim(),
            message: $('#ab-gutschein-message').val().trim()
        };

        $.ajax({
            url: abGutschein.ajaxurl,
            type: 'POST',
            data: data,
            success: function(response) {
                if (response.success) {
                    showFeedback(response.data.message || 'Gutschein wurde zum Warenkorb hinzugefuegt!', 'success');
                    $(document.body).trigger('wc_fragment_refresh');
                    if (response.data && response.data.cart_url) {
                        setTimeout(function() {
                            window.location.href = response.data.cart_url;
                        }, 1000);
                    }
                } else {
                    showFeedback(response.data.message || 'Fehler beim Hinzufuegen.', 'error');
                }
            },
            error: function() {
                showFeedback('Ein Fehler ist aufgetreten. Bitte versuche es erneut.', 'error');
            },
            complete: function() {
                isSubmitting = false;
                $btn.removeClass('loading').text(originalText);
                updateButtonState();
            }
        });
    });

    function showFeedback(msg, type) {
        var $feedback = $('.ab-gutschein-feedback');
        $feedback.removeClass('success error').addClass(type).text(msg).fadeIn();
        if (type === 'success') {
            setTimeout(function() { $feedback.fadeOut(); }, 5000);
        }
    }

    // Initialisierung
    updatePreview();
    updateButtonState();
});
