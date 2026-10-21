<?php

/*
|--------------------------------------------------------------------------
| Marca de la estación (RN-041)
|--------------------------------------------------------------------------
|
| Logo que llevan todos los PDF de la app (reportes y actas de entrega):
| arriba a la derecha y como marca de agua centrada en cada página. Es un
| PNG versionado en el repo (Render despliega solo backend/). Si el archivo
| falta, los PDF se generan sin logo (App\Pdf\Branding).
|
*/

return [

    'logo_path' => resource_path('branding/logo-b31.png'),

];
