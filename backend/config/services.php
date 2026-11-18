<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI', rtrim((string) env('APP_URL', 'http://127.0.0.1:8000'), '/').'/api/v1/platform/marketing/integrations/google/callback'),
        'scopes' => array_values(array_filter(array_map('trim', explode(',', (string) env('GOOGLE_OAUTH_SCOPES', implode(',', [
            'openid',
            'email',
            'profile',
            'https://www.googleapis.com/auth/webmasters.readonly',
            'https://www.googleapis.com/auth/analytics.readonly',
            'https://www.googleapis.com/auth/business.manage',
            'https://www.googleapis.com/auth/tagmanager.readonly',
        ])))))),
        'business_account_id' => env('GOOGLE_BUSINESS_ACCOUNT_ID'),
        'analytics_property_id' => env('GOOGLE_ANALYTICS_PROPERTY_ID'),
    ],

    'meta' => [
        'app_id' => env('META_APP_ID'),
        'app_secret' => env('META_APP_SECRET'),
        'redirect_uri' => env('META_REDIRECT_URI', rtrim((string) env('APP_URL', 'http://127.0.0.1:8000'), '/').'/api/v1/platform/marketing/integrations/meta/callback'),
        'graph_version' => env('META_GRAPH_VERSION', 'v21.0'),
        'scopes' => array_values(array_filter(array_map('trim', explode(',', (string) env('META_OAUTH_SCOPES', implode(',', [
            'pages_show_list',
            'pages_read_engagement',
            'pages_manage_posts',
            'read_insights',
            'instagram_basic',
            'instagram_content_publish',
            'ads_read',
            'business_management',
        ])))))),
        'ad_account_id' => env('META_AD_ACCOUNT_ID'),
        'business_id' => env('META_BUSINESS_ID'),
    ],

];
