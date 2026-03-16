<?php
if (!defined('ABSPATH')) {
    exit;
}

class AB_Payment_Methods {
    const DIRECT_DEBIT = 'direct_debit';
    const BANK_TRANSFER = 'bank_transfer';
    const INVOICE = 'invoice';

    public static function get_methods() {
        return [
            self::DIRECT_DEBIT => 'Lastschriftverfahren',
            self::BANK_TRANSFER => 'Dauerauftrag (monatlich)',
            self::INVOICE => 'Rechnung monatlich'
        ];
    }

    public static function get_fields() {
        return [
            self::BANK_TRANSFER => [
                'company_iban' => [
                    'label' => 'Firmen IBAN',
                    'type' => 'text',
                    'required' => true
                ],
                'company_bic' => [
                    'label' => 'Firmen BIC',
                    'type' => 'text',
                    'required' => true
                ],
                'company_bank' => [
                    'label' => 'Bank',
                    'type' => 'text',
                    'required' => false // Optional, falls nicht immer benötigt
                ]
            ],

            self::INVOICE => [
                'invoice_text' => [
                    'label' => 'Rechnungsinformationen',
                    'type' => 'textarea',
                    'required' => true
                ]
            ]
        ];
    }
}
