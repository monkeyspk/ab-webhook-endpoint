jQuery(document).ready(function($) {
    // Modal öffnen bei Klick auf Kunden-Email
    $('.view-customer-details').on('click', function(e) {
        e.preventDefault();

        const email = $(this).closest('tr').data('email');
        const modal = $('#ab-customer-modal');
        const detailsContainer = $('#ab-customer-details');

        // Loading-Indikator
        detailsContainer.html('<div class="spinner is-active"></div>');
        modal.show();

        // AJAX Request
        $.ajax({
            url: abCustomerAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'ab_get_customer_details',
                email: email,
                nonce: abCustomerAdmin.nonce
            },
            success: function(response) {
                detailsContainer.html(response);

                // Tab-Funktionalität initialisieren
                initializeTabs();
            },
            error: function() {
                detailsContainer.html('<p>Fehler beim Laden der Kundendetails.</p>');
            }
        });
    });

    // Modal schließen
    $('.ab-modal-close, .ab-modal').on('click', function(e) {
        if (e.target === this) {
            $('#ab-customer-modal').hide();
        }
    });

    // Tab-Funktionalität
    function initializeTabs() {
        $('.tab-button').on('click', function() {
            const tabId = $(this).data('tab');

            // Buttons aktiv/inaktiv
            $('.tab-button').removeClass('active');
            $(this).addClass('active');

            // Tab-Inhalte ein/ausblenden
            $('.tab-content').hide();
            $('#' + tabId + '-tab').show();
        });
    }

    // Tabellen-Sortierung (optional)
    $('.wp-list-table th').on('click', function() {
        // Hier könnte eine Sortier-Funktionalität implementiert werden
    });
});
