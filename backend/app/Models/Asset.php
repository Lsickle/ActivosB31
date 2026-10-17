<?php

namespace App\Models;

use App\Enums\AssetStatus;
use Database\Factories\AssetFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * Activo serializado (RN-001, RN-007, RN-008): cada unidad identificable con
 * placa. No se borra; se da de baja (RN-005). El estado, el poseedor, la
 * ubicación y la foto solo se cambian con las acciones de App\Actions\Activos
 * para que todo quede en el historial (FR-004).
 */
#[Fillable([
    'asset_type_id', 'plate', 'serial', 'brand', 'model', 'acquisition_date', 'acquisition_value',
    'useful_life_months', 'expires_on', 'notes',
])]
class Asset extends Model
{
    /** @use HasFactory<AssetFactory> */
    use HasFactory;

    /**
     * Zona horaria de la estación: "hoy" para fechas y alertas (D-004).
     */
    public const string LOCAL_TIMEZONE = 'America/Bogota';

    /**
     * FR-008: vigencia de los enlaces temporales de la foto.
     */
    public const int PHOTO_URL_MINUTES = 10;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'available',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'acquisition_date' => 'date:Y-m-d',
            'acquisition_value' => 'decimal:2',
            'useful_life_months' => 'integer',
            'expires_on' => 'date:Y-m-d',
            'status' => AssetStatus::class,
        ];
    }

    /**
     * D-006: la placa se guarda recortada y en mayúsculas.
     *
     * @return Attribute<string, string>
     */
    protected function plate(): Attribute
    {
        return Attribute::set(fn (string $value) => mb_strtoupper(trim($value)));
    }

    /**
     * @return BelongsTo<AssetType, $this>
     */
    public function assetType(): BelongsTo
    {
        return $this->belongsTo(AssetType::class);
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * RN-039: quién tiene el activo (lo fija Entregas).
     *
     * @return BelongsTo<Employee, $this>
     */
    public function holder(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'holder_employee_id');
    }

    /**
     * @return HasMany<AssetEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(AssetEvent::class);
    }

    /**
     * @return HasMany<AssetMaintenancePlan, $this>
     */
    public function maintenancePlans(): HasMany
    {
        return $this->hasMany(AssetMaintenancePlan::class);
    }

    /**
     * @return HasMany<AssetMaintenanceRecord, $this>
     */
    public function maintenanceRecords(): HasMany
    {
        return $this->hasMany(AssetMaintenanceRecord::class);
    }

    /**
     * Búsqueda por placa, serial, marca o modelo sin distinguir mayúsculas ni
     * tildes (US1-5).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function search(Builder $query, ?string $term): void
    {
        if ($term === null || trim($term) === '') {
            return;
        }

        $grammar = $query->getQuery()->getGrammar();
        $fold = fn (string $expression) => "translate(lower({$expression}), 'áéíóúüñ', 'aeiouun')";
        $pattern = '%'.addcslashes(trim($term), '\\%_').'%';

        $query->where(function (Builder $query) use ($grammar, $fold, $pattern) {
            foreach (['plate', 'serial', 'brand', 'model'] as $column) {
                $query->orWhereRaw("{$fold($grammar->wrap($this->qualifyColumn($column)))} like {$fold('?')}", [$pattern]);
            }
        });
    }

    /**
     * Próximo mantenimiento: el vencimiento más cercano de sus planes activos.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function withNextMaintenance(Builder $query): void
    {
        $query->withMin(
            ['maintenancePlans as next_maintenance_on' => fn (Builder $plans) => $plans->where('is_active', true)],
            'next_due_on',
        );
    }

    /**
     * FR-008: disco privado de las fotos (ASSETS_DISK).
     */
    public static function photoDisk(): Filesystem
    {
        return Storage::disk(config('filesystems.assets_disk'));
    }

    /**
     * FR-008 / US1-6: enlace temporal (10 minutos) a la foto; nunca público.
     */
    public function photoUrl(): ?string
    {
        if ($this->photo_path === null) {
            return null;
        }

        return self::photoDisk()->temporaryUrl($this->photo_path, now()->addMinutes(self::PHOTO_URL_MINUTES));
    }
}
