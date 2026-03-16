<?php
class AB_Email_Image_Processor {
    public static function init() {
        add_filter('ab_process_email_content', [self::class, 'process_email_content'], 10, 2);
    }

    public static function process_email_content($content, $order) {
        // Debugging
        error_log('AB Email Image Processor: Starting image processing');

        // Prüfe ob der Shortcode überhaupt vorhanden ist
        if (strpos($content, '[ab_event_coach_image]') === false) {
            error_log('AB Email Image Processor: No coach image shortcode found in content');
            return $content;
        }

        // Suche nach Coach-Bild in den Bestellposten
        foreach ($order->get_items() as $item) {
            $coach_image_url = $item->get_meta('_event_coach_image');

            if (empty($coach_image_url)) {
                error_log('AB Email Image Processor: No coach image URL found in order item');
                continue;
            }

            error_log('AB Email Image Processor: Found image URL: ' . $coach_image_url);

            // Versuche das Bild zu laden
            try {
                $image_data = @file_get_contents($coach_image_url);

                if ($image_data === false) {
                    throw new Exception('Could not load image data from URL');
                }

                // Bestimme den MIME-Type
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime_type = $finfo->buffer($image_data);

                if (!$mime_type) {
                    throw new Exception('Could not determine MIME type');
                }

                // Konvertiere zu Base64
                $base64 = base64_encode($image_data);

                // Erstelle das IMG-Tag mit responsiven Styles
                $image_tag = sprintf(
                    '<img src="data:%s;base64,%s" style="max-width: 200px; width: 100%%; height: auto; border-radius: 50%%; margin: 15px 0;" alt="Coach">',
                    $mime_type,
                    $base64
                );

                // Ersetze den Shortcode
                $content = str_replace('[ab_event_coach_image]', $image_tag, $content);
                error_log('AB Email Image Processor: Successfully replaced image shortcode');

                // Wenn ein Bild erfolgreich verarbeitet wurde, brechen wir die Schleife ab
                break;

            } catch (Exception $e) {
                error_log('AB Email Image Processor Error: ' . $e->getMessage());
                // Bei Fehler: Ersetze den Shortcode mit einem leeren String
                $content = str_replace('[ab_event_coach_image]', '', $content);
            }
        }

        return $content;
    }
}

// Initialisiere den Processor
AB_Email_Image_Processor::init();
