// In assets/js/contract-wizard.js

jQuery(document).ready(function($) {
    // FIX: Globale Variable um Doppel-Submissions zu verhindern
    var isSubmitting = false;

    $('.contract-wizard-form').on('submit', function(e) {
        e.preventDefault();
        e.stopPropagation(); // Zusätzlich: Event-Propagation stoppen

        // FIX: Prüfen ob bereits eine Submission läuft
        if (isSubmitting) {
            console.log('Form submission already in progress - ignoring');
            return false;
        }
        isSubmitting = true;

        console.log('Form submitted');

        const $form = $(this);
        const $submitButton = $form.find('button[type="submit"]');

        // FIX: Button sofort und komplett deaktivieren
        $submitButton.prop('disabled', true);
        $submitButton.attr('disabled', 'disabled'); // Doppelte Sicherheit
        $submitButton.addClass('is-loading');
        $submitButton.text($submitButton.data('loading-text') || 'Vertrag wird verarbeitet...');

        // Log form data
        const formData = new FormData(this);
        console.log('Form data:');
        for (let pair of formData.entries()) {
            console.log(pair[0] + ': ' + pair[1]);
        }

        // FIX: Das action-Feld entfernen/überschreiben um POST-Handler zu verhindern
        formData.delete('action');
        formData.append('action', 'handle_contract_wizard');
        formData.append('nonce', contractWizard.nonce);

        // Make AJAX call
        $.ajax({
            url: contractWizard.ajaxurl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                console.log('AJAX response:', response);

                if (response.success && response.data?.redirect) {
                    console.log('Redirecting to:', response.data.redirect);
                    window.location.href = response.data.redirect;
                    // isSubmitting bleibt true - Seite wird weitergeleitet
                } else {
                    console.error('Error response:', response);
                    alert(response.data?.message || 'Ein Fehler ist aufgetreten.');
                    // Reset bei Fehler
                    isSubmitting = false;
                    $submitButton.prop('disabled', false);
                    $submitButton.removeAttr('disabled');
                    $submitButton.removeClass('is-loading');
                    $submitButton.text('Vertrag kostenpflichtig abschließen');
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX error:', {xhr, status, error});
                alert('Ein Fehler ist aufgetreten. Bitte versuche es erneut.');
                // Reset bei Fehler
                isSubmitting = false;
                $submitButton.prop('disabled', false);
                $submitButton.removeAttr('disabled');
                $submitButton.removeClass('is-loading');
                $submitButton.text('Vertrag kostenpflichtig abschließen');
            }
        });

        return false; // Zusätzliche Sicherheit gegen Form-Submission
    });

    // FIX: Auch Click-Event auf dem Button abfangen
    $('.contract-wizard-form button[type="submit"]').on('click', function(e) {
        if (isSubmitting) {
            e.preventDefault();
            e.stopPropagation();
            console.log('Button click blocked - submission in progress');
            return false;
        }
    });
});

function getUrlParam(param) {
   const queryString = window.location.search;
   const urlParams = new RegExp('[?&]' + param + '=([^&#]*)').exec(queryString);
   if (urlParams == null) return null;
   return decodeURI(urlParams[1]);
}

jQuery(document).ready(function($) {
    $('.upload-image-button').click(function(e) {
        e.preventDefault();
        var button = $(this);
        var custom_uploader = wp.media({
            title: 'Vertragsbild auswählen',
            button: { text: 'Bild verwenden' },
            multiple: false
        }).on('select', function() {
            var attachment = custom_uploader.state().get('selection').first().toJSON();
            $('#ab_vertrag_bild').val(attachment.id);
            // Verwende die Original-Bildgröße für maximale Qualität
            var imgSrc = attachment.sizes.full ? attachment.sizes.full.url : attachment.url;
            $('.image-preview').html('<img src="' + imgSrc + '">');
        }).open();
    });
});

document.addEventListener('DOMContentLoaded', function () {
    const toggles = document.querySelectorAll('.accordion-toggle');

    toggles.forEach(toggle => {
        toggle.addEventListener('click', function () {
            const content = this.nextElementSibling;

            if (this.classList.contains('open')) {
                // Schließen
                this.classList.remove('open');
                content.classList.remove('open');
            } else {
                // Andere Akkordeons schließen
                document.querySelectorAll('.accordion-toggle.open').forEach(openToggle => {
                    openToggle.classList.remove('open');
                    openToggle.nextElementSibling.classList.remove('open');
                });

                // Dieses öffnen
                this.classList.add('open');
                content.classList.add('open');
            }
        });
    });
});





// Geburtsdatum Logik



document.addEventListener('DOMContentLoaded', function() {
    const toggleButtons = document.querySelectorAll('.toggle-button'); // Ändere die Klasse entsprechend

    toggleButtons.forEach(button => {
        button.addEventListener('click', function() {
            const content = this.nextElementSibling;
            content.style.display = content.style.display === 'none' ? 'block' : 'none';
        });
    });
});
