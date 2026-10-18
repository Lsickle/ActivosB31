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

    // Pendiente de seguridad 1 (TrustVercelProxy): secreto compartido con el
    // rewrite de vercel.json (cabecera X-Proxy-Secret). Con required=true se
    // rechaza (403) todo lo que no llegue por el proxy, salvo /up.
    'vercel_proxy' => [
        'secret' => env('VERCEL_PROXY_SECRET', ''),
        // Solo durante una rotación del secreto (docs/despliegue.md); vacío el resto del tiempo.
        'previous_secret' => env('VERCEL_PROXY_SECRET_PREVIOUS', ''),
        'required' => (bool) env('VERCEL_PROXY_REQUIRED', false),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
