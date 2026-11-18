<?php

return [
    /*
    | Sandbox mode is the default until live provider credentials are supplied.
    | Sandbox adapters simulate provider behaviour but never mark a payment as
    | successful from a browser redirect.
    */
    'sandbox' => (bool) env('PAYMENTS_SANDBOX', true),
    'default_currency' => env('PAYMENTS_CURRENCY', 'KES'),

    'callbacks' => [
        'signature_header' => 'X-Brathamatu-Signature',
        'sandbox_secret' => env('PAYMENTS_SANDBOX_SECRET', 'local-sandbox-secret'),
    ],

    'mpesa' => [
        'shortcode' => env('MPESA_SHORTCODE', '174379'),
        'paybill' => env('MPESA_PAYBILL', '174379'),
        'till' => env('MPESA_TILL'),
        'callback_url' => env('MPESA_CALLBACK_URL', 'http://127.0.0.1:8000/api/v1/payments/mpesa/callback'),
    ],

    'card' => [
        'gateway' => env('CARD_GATEWAY', 'sandbox-card'),
        'callback_url' => env('CARD_CALLBACK_URL', 'http://127.0.0.1:8000/api/v1/payments/card/callback'),
    ],

    'bank' => [
        'name' => env('BANK_NAME', 'BRATHAMATU Sandbox Bank'),
        'account_name' => env('BANK_ACCOUNT_NAME', 'BRATHAMATU Ltd'),
        'account_number' => env('BANK_ACCOUNT_NUMBER', '0000000000'),
        'branch' => env('BANK_BRANCH', 'Nairobi'),
    ],
];
