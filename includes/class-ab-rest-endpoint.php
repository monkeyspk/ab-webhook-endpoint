<?php
if (!defined('ABSPATH')) {
    exit;
}

class AB_Rest_Endpoint {

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function register_routes() {
        register_rest_route('ab/v1', '/update-order-status', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_update_order_status'],
            'permission_callback' => '__return_true', // oder eigene Auth-Checks
        ]);

        // Neuer Endpoint für Order-Historie
        register_rest_route('ab/v1', '/order-history/(?P<order_id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'handle_get_order_history'],
            'permission_callback' => '__return_true',
            'args' => [
                'order_id' => [
                    'validate_callback' => function($param, $request, $key) {
                        return is_numeric($param);
                    }
                ],
            ],
        ]);

        // Bulk-Historie Endpoint
        register_rest_route('ab/v1', '/orders-history', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_get_bulk_order_history'],
            'permission_callback' => '__return_true',
        ]);

        // Bulk-Update Endpoint
        register_rest_route('ab/v1', '/bulk-update-status', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_bulk_update_status'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function handle_update_order_status($request) {
        $order_id      = $request->get_param('order_id');
        $academy_state = $request->get_param('new_status');
        $silent        = $request->get_param('silent'); // Neuer Parameter für stilles Update


        // Bestellung laden
        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('order_not_found', 'Bestellung nicht gefunden.', ['status' => 404]);
        }

        // Mapping anstoßen
        $new_status = AB_Custom_Statuses::map_academyboard_status($academy_state);
        if (!$new_status) {
            return new WP_Error('invalid_status', 'Der gelieferte Status ist ungültig.', ['status' => 400]);
        }

        // SICHERHEITSPRÜFUNG: Status "schuelerin" und "bestandkundeakz" nur erlauben wenn Vertrag abgeschlossen
        if ($new_status === 'wc-schuelerin' || $new_status === 'wc-bestandkundeakz') {
            $contract_status = get_post_meta($order_id, '_contract_status', true);

            // Status-Label für die Fehlermeldung bestimmen
            $status_labels = [
                'wc-schuelerin' => 'Schüler_in',
                'wc-bestandkundeakz' => 'Bestandskunde akzeptiert',
            ];
            $status_label = isset($status_labels[$new_status]) ? $status_labels[$new_status] : $new_status;

            if ($contract_status !== 'completed') {
                error_log(sprintf(
                    '[AB REST API] ABGELEHNT: Status-Änderung auf "%s" für Order #%d verweigert. ' .
                    'Grund: Kein abgeschlossener Vertrag vorhanden (_contract_status = "%s")',
                    $new_status,
                    $order_id,
                    $contract_status ?: 'nicht gesetzt'
                ));

                return new WP_Error(
                    'contract_not_completed',
                    sprintf(
                        'Status-Änderung auf "%s" nicht möglich: Für Bestellung #%d liegt kein abgeschlossener Vertrag vor. ' .
                        'Der Vertrag muss zuerst über den Vertrags-Wizard abgeschlossen werden.',
                        $status_label,
                        $order_id
                    ),
                    ['status' => 403]
                );
            }

            error_log(sprintf(
                '[AB REST API] Status-Änderung auf "%s" für Order #%d erlaubt - Vertrag ist abgeschlossen.',
                $new_status,
                $order_id
            ));
        }

        // Status aktualisieren
        try {
            // Wenn silent=true, setze temporären Marker für E-Mail-Unterdrückung
            $is_silent = ($silent === true || $silent === 'true' || $silent === '1');
            if ($is_silent) {
                update_post_meta($order_id, '_ab_silent_update', 'yes');
            }

            // Erstelle aussagekräftige Notiz
            $note = sprintf(
                'Status via Academyboard API aktualisiert%s. [AB → WC Sync]',
                $is_silent ? ' (silent, keine E-Mails)' : ''
            );

            $order->update_status($new_status, $note);

            // E-Mail verschicken (wird durch Silent-Flag gesteuert)
            $email_sent = false;
            if ($silent !== true && $silent !== 'true' && $silent !== '1') {
                // E-Mail-Marker löschen, damit Academy Board immer E-Mails senden kann
                // (z.B. bei "Vertrag erneut versenden")
                $status_key = str_replace('wc-', '', $new_status);
                delete_post_meta($order_id, '_ab_email_sent_' . $status_key);

                $email_sent = AB_Email_Sender::send_status_email($order_id, $new_status);
            } else {
                // Silent-Marker wieder entfernen
                delete_post_meta($order_id, '_ab_silent_update');
            }

            return [
                'success'    => true,
                'message'    => sprintf('Bestellung #%d wurde auf %s gesetzt.', $order_id, $new_status),
                'email_sent' => $email_sent,
                'silent'     => ($silent === true || $silent === 'true' || $silent === '1')
            ];
        } catch (Exception $e) {
            // Aufräumen falls Fehler
            delete_post_meta($order_id, '_ab_silent_update');
            return new WP_Error('update_failed', $e->getMessage(), ['status' => 500]);
        }
    }

    /**
     * Handler für Order-Historie Abruf
     */
    public static function handle_get_order_history($request) {
        $order_id = $request->get_param('order_id');
        
        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('order_not_found', 'Bestellung nicht gefunden.', ['status' => 404]);
        }

        // Order Notes (Status-Historie) abrufen - WooCommerce way
        $args = [
            'order_id' => $order_id,
            'order_by' => 'date_created',
            'order'    => 'ASC',
        ];

        // Nutze WooCommerce's eigene Funktion
        $notes = wc_get_order_notes($args);
        $history = [];
        
        // Debug: Zeige alle Notes für Entwicklung
        $all_notes_debug = [];

        foreach ($notes as $note) {
            // WooCommerce note object hat andere Eigenschaften
            $note_content = $note->content;
            $note_date = $note->date_created->date('Y-m-d H:i:s');
            $note_author = $note->added_by;
            
            // Debug: Sammle ALLE Notes
            $all_notes_debug[] = [
                'content' => $note_content,
                'date' => $note_date,
                'author' => $note_author,
                'customer_note' => $note->customer_note
            ];
            
            // Nur Status-Änderungen erfassen
            if (strpos($note_content, 'Status geändert von') !== false || 
                strpos($note_content, 'Order status changed from') !== false ||
                strpos($note_content, 'Status via') !== false ||
                strpos($note_content, '[AB → WC Sync]') !== false ||
                strpos($note_content, 'changed status from') !== false ||
                strpos($note_content, 'Status der Buchung von') !== false) {
                
                // Versuche alte und neue Status zu extrahieren
                $pattern = '/von (.+?) zu (.+?)\./';
                $pattern_en = '/from (.+?) to (.+?)\./';
                $pattern_wc = '/changed status from (.+?) to (.+?)\./';
                $pattern_booking = '/Status der Buchung von (.+?) auf (.+?) geändert/';
                
                $from_status = '';
                $to_status = '';
                
                if (preg_match($pattern, $note_content, $matches)) {
                    $from_status = $matches[1];
                    $to_status = $matches[2];
                } elseif (preg_match($pattern_en, $note_content, $matches)) {
                    $from_status = $matches[1];
                    $to_status = $matches[2];
                } elseif (preg_match($pattern_wc, $note_content, $matches)) {
                    $from_status = $matches[1];
                    $to_status = $matches[2];
                } elseif (preg_match($pattern_booking, $note_content, $matches)) {
                    $from_status = $matches[1];
                    $to_status = $matches[2];
                }

                // Bestimme die Quelle
                $source = 'manual';
                if (strpos($note_content, '[AB → WC Sync]') !== false) {
                    $source = 'academyboard_api';
                } elseif (strpos($note_content, 'REST API') !== false) {
                    $source = 'rest_api';
                } elseif ($note_author === 'WooCommerce' || $note_author === 'system') {
                    $source = 'woocommerce';
                }

                // Prüfe ob silent
                $is_silent = strpos($note_content, '(silent') !== false;

                $history[] = [
                    'date'    => $note_date,
                    'from'    => $from_status,
                    'to'      => $to_status,
                    'note'    => $note_content,
                    'author'  => $note_author,
                    'source'  => $source,
                    'silent'  => $is_silent
                ];
            }
        }

        return [
            'order_id'       => $order_id,
            'current_status' => $order->get_status(),
            'history'        => $history,
            'meta'           => [
                'billing_email' => $order->get_billing_email(),
                'first_name'    => $order->get_billing_first_name(),
                'last_name'     => $order->get_billing_last_name(),
            ],
            'debug' => [
                'total_notes_found' => count($notes),
                'history_entries' => count($history),
                'all_notes' => $all_notes_debug  // Zeigt ALLE Notes zum Debuggen
            ]
        ];
    }

    /**
     * Handler für Bulk Order-Historie
     */
    public static function handle_get_bulk_order_history($request) {
        $order_ids = $request->get_param('order_ids');
        
        if (!is_array($order_ids) || empty($order_ids)) {
            return new WP_Error('invalid_input', 'order_ids muss ein Array mit mindestens einer ID sein.', ['status' => 400]);
        }

        $results = [];
        
        foreach ($order_ids as $order_id) {
            $single_request = new WP_REST_Request('GET');
            $single_request->set_param('order_id', $order_id);
            
            $history = self::handle_get_order_history($single_request);
            
            if (!is_wp_error($history)) {
                $results[$order_id] = $history;
            } else {
                $results[$order_id] = [
                    'error' => true,
                    'message' => $history->get_error_message()
                ];
            }
        }

        return [
            'orders' => $results,
            'count'  => count($results)
        ];
    }

    /**
     * Handler für Bulk Status Updates
     */
    public static function handle_bulk_update_status($request) {
        $updates = $request->get_param('updates');
        
        if (!is_array($updates) || empty($updates)) {
            return new WP_Error('invalid_input', 'updates muss ein Array mit mindestens einem Update sein.', ['status' => 400]);
        }

        $results = [];
        $success_count = 0;
        $error_count = 0;

        foreach ($updates as $update) {
            if (!isset($update['order_id']) || !isset($update['new_status'])) {
                $results[] = [
                    'error' => true,
                    'message' => 'order_id und new_status sind erforderlich'
                ];
                $error_count++;
                continue;
            }

            $single_request = new WP_REST_Request('POST');
            $single_request->set_param('order_id', $update['order_id']);
            $single_request->set_param('new_status', $update['new_status']);
            
            // Silent flag weitergeben falls vorhanden
            if (isset($update['silent'])) {
                $single_request->set_param('silent', $update['silent']);
            }

            $result = self::handle_update_order_status($single_request);
            
            if (!is_wp_error($result)) {
                $results[] = $result;
                $success_count++;
            } else {
                $results[] = [
                    'order_id' => $update['order_id'],
                    'error' => true,
                    'message' => $result->get_error_message()
                ];
                $error_count++;
            }
        }

        return [
            'results' => $results,
            'summary' => [
                'total' => count($updates),
                'success' => $success_count,
                'errors' => $error_count
            ]
        ];
    }
}
