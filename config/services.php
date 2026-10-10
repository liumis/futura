<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
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

    'ocr_space' => [
        'key' => env('OCR_SPACE_API_KEY'),
    ],

    'invoice_ai' => [
        'key' => env('INVOICE_AI_API_KEY', env('OPENAI_API_KEY')),
        'base_url' => env('INVOICE_AI_BASE_URL', 'https://api.openai.com/v1'),
        'model' => env('INVOICE_AI_MODEL', 'gpt-4o-mini'),
    ],

    /*
    | Delegated Microsoft Graph OAuth for Outlook Calendar sync.
    | Falls back to SharePoint Entra app client_id/secret/tenant when unset.
    */
    'microsoft_calendar' => [
        'tenant_id' => env('MICROSOFT_CALENDAR_TENANT_ID'),
        'client_id' => env('MICROSOFT_CALENDAR_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CALENDAR_CLIENT_SECRET'),
        'redirect' => env('MICROSOFT_CALENDAR_REDIRECT_URI'),
    ],

];
