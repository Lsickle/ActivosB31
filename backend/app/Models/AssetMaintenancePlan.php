<?php

namespace App\Models;

use Database\Factories\AssetMaintenancePlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Plan de mantenimiento de un activo (RN-029): p. ej. "Prueba hidrostática"
 * cada 60 meses. El próximo vencimiento se calcula siempre al guardar (D-003).
 */
#[Fillable(['name', 'interval_months', 'last_done_on', 'alert_days', 'is_active'])]
class AssetMaintenancePlan extends Model
{
    /** @use HasFactory<AssetMaintenancePlanFactory> */
    use HasFactory;

    /**
     * RN-030: anticipación de alerta por defecto.
     */
    public const int DEFAULT_ALERT_DAYS = 30;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'alert_days' => self::DEFAULT_ALERT_DAYS,
        'is_active' => true,
    ];

    protected static function booted(): void
    {
        static::saving(function (self $plan) {
            $plan->next_due_on = $plan->calculateNextDueOn();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'interval_months' => 'integer',
            'last_done_on' => 'date:Y-m-d',
            'next_due_on' => 'date:Y-m-d',
            'alert_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * D-003: (último realizado ?? fecha de creación del plan) + periodicidad.
     * Sin desbordar el mes: 31 de enero + 1 mes = 28/29 de febrero. La fecha
     * de creación es el día en America/Bogota.
     */
    public function calculateNextDueOn(): Carbon
    {
        $base = $this->last_done_on
            ?? ($this->created_at ?? Carbon::now())->copy()->setTimezone(Asset::LOCAL_TIMEZONE)->startOfDay();

        return Carbon::parse($base->toDateString())->addMonthsNoOverflow($this->interval_months);
    }
}
