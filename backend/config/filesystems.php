<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Disco de fotos de activos (005, FR-008)
    |--------------------------------------------------------------------------
    |
    | Privado siempre: las fotos se entregan con URLs temporales. "local" en
    | desarrollo y tests (servido con URLs firmadas); "b2" (Backblaze B2,
    | bucket activosb31-fotos) en producción (decisión 44).
    |
    */

    'assets_disk' => env('ASSETS_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Disco de firmas de entregas (007, D-002; decisiones 45 y 46)
    |--------------------------------------------------------------------------
    |
    | Privado, con su propio bucket (activosb31-firmas, con Object Lock) y su
    | propia llave sin permiso de borrado: "b2_firmas" en producción. Si no se
    | define, usa el mismo disco de las fotos (local en desarrollo y tests).
    |
    */

    'signatures_disk' => env('SIGNATURES_DISK', env('ASSETS_DISK', 'local')),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'visibility' => 'private',
            // FR-008: sirve los archivos solo con URL firmada (temporaryUrl).
            // M-3 (revisión de seguridad de 005): apagado salvo que se pida
            // (desarrollo y tests); en producción fotos y firmas van a B2.
            'serve' => (bool) env('LOCAL_DISK_SERVE', false),
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        // Backblaze B2 (decisión 44, docs/infraestructura.md): buckets privados,
        // archivos con temporaryUrl(). aws-sdk-php >= 3.337 calcula checksums
        // CRC32 por defecto y la API S3 de B2 los rechaza: solo cuando la
        // operación lo exige. B2_ENDPOINT = https://s3.<región>.backblazeb2.com.
        'b2' => [
            'driver' => 's3',
            'key' => env('B2_ACCESS_KEY_ID'),
            'secret' => env('B2_SECRET_ACCESS_KEY'),
            'region' => env('B2_REGION'),
            'bucket' => env('B2_BUCKET'),
            'endpoint' => env('B2_ENDPOINT'),
            'use_path_style_endpoint' => true,
            'request_checksum_calculation' => 'when_required',
            'response_checksum_validation' => 'when_required',
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ],

        // Firmas de entregas (decisiones 45 y 46): bucket aparte con Object
        // Lock y una application key limitada a ese bucket, sin deleteFiles
        // ni bypassGovernance. Misma región y endpoint que las fotos.
        'b2_firmas' => [
            'driver' => 's3',
            'key' => env('B2_SIGNATURES_ACCESS_KEY_ID'),
            'secret' => env('B2_SIGNATURES_SECRET_ACCESS_KEY'),
            'region' => env('B2_REGION'),
            'bucket' => env('B2_SIGNATURES_BUCKET'),
            'endpoint' => env('B2_ENDPOINT'),
            'use_path_style_endpoint' => true,
            'request_checksum_calculation' => 'when_required',
            'response_checksum_validation' => 'when_required',
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
