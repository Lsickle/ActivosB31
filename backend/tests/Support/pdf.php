<?php

/*
 * Ayudas de los tests de PDF (reportes de 008 y acta de entrega, RN-041).
 * Se cargan con require_once (Pest define las funciones de forma global: un
 * único archivo evita redefinirlas).
 */

use Illuminate\Support\Facades\View;

/**
 * Captura el HTML con el que se genera un PDF (por defecto la plantilla
 * reports.pdf): el binario del PDF no permite buscar texto (fuente embebida).
 *
 * @return Closure(): string
 */
function capturePdfHtml(string $view = 'reports.pdf'): Closure
{
    $data = null;
    View::composer($view, function ($view) use (&$data) {
        $data = $view->getData();
    });

    return function () use (&$data, $view): string {
        expect($data)->not->toBeNull("No se renderizó la plantilla {$view}.");

        return view($view, $data)->render();
    };
}

/**
 * PNG real dibujado con GD para usarlo como logo de la estación.
 */
function brandLogoPng(int $width = 120, int $height = 80): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 200, 30, 30));
    imagefilledellipse($image, (int) ($width / 2), (int) ($height / 2), (int) ($width / 2), (int) ($height / 2), imagecolorallocate($image, 255, 255, 255));
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

/**
 * Escribe un logo PNG en un archivo temporal y lo configura como el logo de
 * los PDF (config('branding.logo_path')). Se borra al terminar el test.
 *
 * @return string contenido binario del PNG
 */
function useBrandLogo(?string $png = null): string
{
    $png ??= brandLogoPng();
    $path = tempnam(sys_get_temp_dir(), 'logo-test-');
    file_put_contents($path, $png);
    config(['branding.logo_path' => $path]);
    test()->beforeApplicationDestroyed(fn () => @unlink($path));

    return $png;
}

/**
 * Configura una ruta de logo que no existe.
 */
function withoutBrandLogo(): void
{
    config(['branding.logo_path' => sys_get_temp_dir().'/no-existe-'.uniqid().'.png']);
}

/**
 * Etiquetas <img> del HTML.
 *
 * @return list<string>
 */
function pdfImgTags(string $html): array
{
    preg_match_all('/<img\b[^>]*>/i', $html, $matches);

    return $matches[0];
}

/**
 * D-005 / RN-041: las únicas imágenes del PDF son las de marca (logo arriba
 * a la derecha y marca de agua) incrustadas como data:image/png;base64.
 * Devuelve cuántas hay.
 */
function expectOnlyBrandImages(string $html): int
{
    $tags = pdfImgTags($html);

    foreach ($tags as $tag) {
        expect($tag)->toMatch('/\bclass="brand-(logo|watermark)"/')
            ->toMatch('/\bsrc="data:image\/png;base64,[A-Za-z0-9+\/=]+"/');
    }

    return count($tags);
}

/**
 * Número de páginas de un PDF de dompdf (CPDF).
 */
function pdfPageCount(string $pdf): int
{
    return preg_match_all('/\/Type\s*\/Page\b(?!s)/', $pdf);
}

/**
 * Número de imágenes incrustadas (XObject de tipo Image).
 */
function pdfImageCount(string $pdf): int
{
    return preg_match_all('/\/Subtype\s*\/Image/', $pdf);
}
