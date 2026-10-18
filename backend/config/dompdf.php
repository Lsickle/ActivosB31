<?php

/*
|--------------------------------------------------------------------------
| dompdf (008, RN-032, D-005)
|--------------------------------------------------------------------------
|
| Configuración endurecida (docs/infraestructura.md): los PDF de reportes se
| arman solo con plantillas Blade propias (resources/views/reports), con
| tablas y CSS inline, sin HTML del usuario ni imágenes.
|
| - enable_remote / allowed_remote_hosts: nunca descargar recursos remotos
|   (SSRF, CVE de dompdf corregidas en 3.1.6).
| - enable_php / enable_javascript: nada de código embebido.
| - chroot + allowed_protocols: ningún protocolo (R-7); los archivos locales
|   quedan además limitados a la carpeta de plantillas (sin fotos ni firmas).
| - DejaVu Sans (incluida en dompdf) para tildes y eñes.
|
*/

return [

    'show_warnings' => false,

    'public_path' => null,

    'convert_entities' => true,

    'options' => [
        'font_dir' => storage_path('fonts'),

        'font_cache' => storage_path('fonts'),

        'temp_dir' => sys_get_temp_dir(),

        'chroot' => [resource_path('views/reports')],

        // R-7 (revisión de seguridad de 008): la plantilla no carga ningún
        // recurso (ni imágenes, ni hojas de estilo, ni @font-face): ningún
        // protocolo permitido, ni siquiera data://. Las fuentes de dompdf se
        // leen de font_dir sin pasar por esta lista.
        'allowed_protocols' => [],

        'log_output_file' => null,

        // SC-001: sin subconjunto la fuente se incrusta completa (~1 MB por
        // PDF) pero un reporte de 1 000 filas tarda la mitad.
        'enable_font_subsetting' => false,

        'pdf_backend' => 'CPDF',

        'default_media_type' => 'print',

        'default_paper_size' => 'letter',

        'default_paper_orientation' => 'portrait',

        'default_font' => 'DejaVu Sans',

        'dpi' => 96,

        'enable_php' => false,

        'enable_javascript' => false,

        'enable_remote' => false,

        'allowed_remote_hosts' => [],

        'font_height_ratio' => 1.1,

        'enable_html5_parser' => true,
    ],

];
