<?php

return [
    'base_domain' => env('TENANT_BASE_DOMAIN', 'communitygroup.co.ke'),
    'platform_subdomains' => ['www', 'api', 'app', 'admin', 'mail', 'support', 'status'],
    'domain_reservation_minutes' => (int) env('DOMAIN_RESERVATION_MINUTES', 30),
    'minimum_slug_length' => (int) env('TENANT_SLUG_MIN_LENGTH', 2),
    'maximum_slug_length' => (int) env('TENANT_SLUG_MAX_LENGTH', 63),
    'suggestion_number_limit' => (int) env('TENANT_SLUG_SUGGESTION_NUMBER_LIMIT', 10),
    'suggestion_descriptors' => ['shop', 'mart', 'retail', 'market', 'store', 'supplies'],
    'registration' => [
        'verification_expiry_minutes' => (int) env('REGISTRATION_VERIFICATION_MINUTES', 15),
        'verification_max_attempts' => (int) env('REGISTRATION_VERIFICATION_MAX_ATTEMPTS', 5),
        'resend_cooldown_seconds' => (int) env('REGISTRATION_RESEND_COOLDOWN_SECONDS', 60),
        'max_resends' => (int) env('REGISTRATION_MAX_RESENDS', 5),
    ],
];
