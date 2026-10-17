<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Ajustes generales clave → valor (D-005). Si un ajuste no tiene fila se usa
 * su valor por defecto.
 */
#[Fillable(['key', 'value'])]
class AppSetting extends Model
{
    /**
     * RN-030: anticipación global de las alertas de vencimiento, en días.
     */
    public const string EXPIRY_ALERT_DAYS = 'expiry_alert_days';

    /**
     * @var array<string, mixed>
     */
    public const array DEFAULTS = [
        self::EXPIRY_ALERT_DAYS => 30,
    ];

    public const ?string CREATED_AT = null;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }

    public static function current(string $key): mixed
    {
        $setting = static::query()->find($key);

        return $setting === null ? self::DEFAULTS[$key] ?? null : $setting->value;
    }

    public static function put(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    public static function expiryAlertDays(): int
    {
        return (int) static::current(self::EXPIRY_ALERT_DAYS);
    }
}
