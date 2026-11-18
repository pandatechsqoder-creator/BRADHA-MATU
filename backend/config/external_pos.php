<?php

return [

    /*
    |--------------------------------------------------------------------------
    | External POS/ERP System Configurations
    |--------------------------------------------------------------------------
    |
    | Here you can configure credentials and settings for external POS/ERP systems
    | that integrate with the BRATHAMATU stock-taking platform.
    |
    | Supported systems should have their own configuration block below.
    |
    */

    'default' => [

        'driver' => env('EXTERNAL_POS_DRIVER', 'csv'),

        'csv' => [

            'model_map' => [

                // Map external CSV column names to internal model attributes
                'product_sku' => 'sku',
                'product_barcode' => 'barcode',
                'product_name' => 'name',
                'product_unit' => 'unit',
                'product_price' => 'unit_price',

            ],

            'batch_size' => 100,

            'import_path' => storage_path('app/external_pos_imports'),

        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Shopify POS Integration
    |--------------------------------------------------------------------------
    |
    | Configuration for Shopify POS external system integration.
    |
    */

    'shopify' => [

        'base_url' => env('SHOPIFY_POS_BASE_URL', ''),
        'api_version' => env('SHOPIFY_POS_API_VERSION', '2024-01'),
        'timeout' => 30,

        'credentials' => [

            'api_key' => env('SHOPIFY_API_KEY', ''),
            'api_secret' => env('SHOPIFY_API_SECRET', ''),
            'access_token' => env('SHOPIFY_ACCESS_TOKEN', ''),

        ],

        'endpoints' => [

            'products' => '/admin/api/'.env('SHOPIFY_POS_API_VERSION', '2024-01').'/products.json',
            'orders' => '/admin/api/'.env('SHOPIFY_POS_API_VERSION', '2024-01').'/orders.json',

        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Magento 2 POS Integration
    |--------------------------------------------------------------------------
    |
    | Configuration for Magento 2 POS external system integration.
    |
    */

    'magento2' => [

        'base_url' => env('MAGENTO2_POS_BASE_URL', ''),
        'timeout' => 30,

        'credentials' => [

            'consumer_key' => env('MAGENTO2_CONSUMER_KEY', ''),
            'consumer_secret' => env('MAGENTO2_CONSUMER_SECRET', ''),
            'access_token' => env('MAGENTO2_ACCESS_TOKEN', ''),
            'access_token_secret' => env('MAGENTO2_ACCESS_TOKEN_SECRET', ''),

        ],

        'endpoints' => [

            'products' => '/rest/V1/products',
            'orders' => '/rest/V1/orders',

        ],

    ],

];