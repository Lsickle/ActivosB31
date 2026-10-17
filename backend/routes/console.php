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
