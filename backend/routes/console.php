<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// FR-016 (D-009): depura los tokens Bearer (móvil) vencidos. En Render el
// scheduler necesita un cron job o worker que ejecute `schedule:run`.
Schedule::command('sanctum:prune-expired --hours=24')->daily();

// M-3 (revisión de seguridad de 007/008): auditoría semanal de las firmas de
// entrega (RN-024 / D-002); las alteradas o faltantes quedan en la bitácora.
Schedule::command('deliveries:verify-signatures')
    ->weeklyOn(0, '03:00')
    ->timezone('America/Bogota');
