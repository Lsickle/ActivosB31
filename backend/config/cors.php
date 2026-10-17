<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // El SPA se autentica con cookie Sanctum: requiere origen explícito y
    // credentials ('*' no es válido con supports_credentials=true).
    'allowed_origins' => array_filter(explode(',', env('FRONTEND_URL', 'http://localhost:3001'))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    // 008 (D-002): el frontend lee el nombre del archivo descargado; sin
    // esto, con el frontend en otro origen (dev), el navegador lo oculta.
    'exposed_headers' => ['Content-Disposition'],

    'max_age' => 0,

    'supports_credentials' => true,

];
