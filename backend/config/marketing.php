<?php

return [
    /*
    | Public website defaults. Everything visible is deliberately configurable:
    | brand, contact details and social links are seeded into marketing_settings
    | and can be overridden per environment until the owner confirms real values.
    */
    'brand' => [
        'name' => env('MARKETING_BRAND_NAME', 'BRATHAMATU'),
        'stocktake' => env('MARKETING_BRAND_STOCKTAKE', 'BRATHAMATU Stocktake'),
        'pos' => env('MARKETING_BRAND_POS', 'BRATHAMATU POS'),
        'tagline' => env('MARKETING_BRAND_TAGLINE', 'Professional stock-taking and point-of-sale for growing businesses'),
        'short_description' => env('MARKETING_BRAND_DESCRIPTION', 'Professional stock-taking services and BRATHAMATU POS for retail, wholesale and warehouse operations.'),
    ],

    'contact' => [
        'email' => env('MARKETING_CONTACT_EMAIL'),
        'phone' => env('MARKETING_CONTACT_PHONE'),
        'whatsapp' => env('MARKETING_CONTACT_WHATSAPP'),
        'address' => env('MARKETING_CONTACT_ADDRESS'),
    ],

    'social' => [
        'facebook' => env('MARKETING_SOCIAL_FACEBOOK'),
        'instagram' => env('MARKETING_SOCIAL_INSTAGRAM'),
        'linkedin' => env('MARKETING_SOCIAL_LINKEDIN'),
        'x' => env('MARKETING_SOCIAL_X'),
    ],

    'seo' => [
        'title_suffix' => env('MARKETING_SEO_TITLE_SUFFIX', ' | BRATHAMATU'),
        'default_description' => env('MARKETING_SEO_DESCRIPTION', 'Professional stock-taking services and BRATHAMATU POS for businesses in Kenya.'),
    ],

    /*
    | Canonical base (scheme + host) used for canonicals and sitemap URLs. When
    | unset, APP_URL is used. Canonical host redirects are opt-in via config.
    */
    'canonical_base' => env('MARKETING_CANONICAL_BASE'),
    'canonical_host' => env('MARKETING_CANONICAL_HOST'),

    /*
    | Indexing is forced off outside production unless explicitly enabled. This
    | keeps staging and preview environments out of search results.
    */
    'indexable' => (bool) env('MARKETING_INDEXABLE', env('APP_ENV', 'production') === 'production'),

    'app_url' => env('FRONTEND_URL', env('APP_URL')),
];
