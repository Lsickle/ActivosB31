{{--
    Layout común de los PDF de la app (RN-041): reportes (008) y acta de
    entrega. Solo tablas y CSS en el documento (dompdf: sin flexbox, grid ni
    Tailwind) y todo dato escapado con {{ }}: nunca HTML del usuario.

    Logo de la estación (App\Pdf\Branding, $brandLogo ?BrandLogo):
    - arriba a la derecha, en el margen superior de cada página;
    - marca de agua centrada en cada página, detrás del contenido.
    Los dos son position: fixed (dompdf los repite en todas las páginas) y se
    incrustan como data:image/png;base64 (PdfRenderer solo permite data://).
    Sin logo el documento se genera igual.

    Variables: $title, $orientation (portrait|landscape), $brandLogo.
    Secciones: styles (CSS propio) y content.
--}}
@php
    // Carta en mm y márgenes de @page. dompdf ubica lo fijo respecto de la
    // caja de contenido (dentro de los márgenes) y calcula mal los
    // porcentajes, así que la marca de agua se centra en la página con
    // medidas absolutas.
    $landscape = ($orientation ?? 'portrait') === 'landscape';
    [$pageWidth, $pageHeight] = $landscape ? [279.4, 215.9] : [215.9, 279.4];
    [$marginTop, $marginSide] = [22, 12];
    $headerLogo = $brandLogo?->fit(45, 13);
    $watermark = $brandLogo?->fit(120, 120);
    $watermarkLeft = $watermark ? round($pageWidth / 2 - $marginSide - $watermark['width'] / 2, 1) : 0;
    $watermarkTop = $watermark ? round($pageHeight / 2 - $marginTop - $watermark['height'] / 2, 1) : 0;
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { size: letter {{ $landscape ? 'landscape' : 'portrait' }}; margin: {{ $marginTop }}mm {{ $marginSide }}mm 14mm {{ $marginSide }}mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.5pt; color: #111111; }
        h1 { font-size: 14pt; margin: 0 0 6pt 0; }
        h2 { font-size: 10.5pt; margin: 12pt 0 4pt 0; }
        img.brand-logo { position: fixed; top: -17mm; right: 0; }
        img.brand-watermark { position: fixed; opacity: 0.08; z-index: -1; }
        @yield('styles')
    </style>
</head>
<body>
    @if ($brandLogo)
        <img class="brand-watermark" src="{{ $brandLogo->dataUri }}" alt="" style="top: {{ $watermarkTop }}mm; left: {{ $watermarkLeft }}mm; width: {{ $watermark['width'] }}mm; height: {{ $watermark['height'] }}mm;">
        <img class="brand-logo" src="{{ $brandLogo->dataUri }}" alt="Estación de Bomberos de Madrid" style="width: {{ $headerLogo['width'] }}mm; height: {{ $headerLogo['height'] }}mm;">
    @endif

    @yield('content')
</body>
</html>
