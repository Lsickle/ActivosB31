<?php

namespace App\Models;

use App\Enums\DeliveryKind;
use App\Enums\DeliveryStatus;
use App\Models\Concerns\InsertOnly;
use Database\Factories\DeliveryFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use League\Flysystem\UnableToReadFile;

/**
 * Entrega de artículos a un empleado (007, FR-001, RN-023..RN-025). Solo
 * inserción (trait + trigger prevent_row_changes()): no se edita ni se borra
 * (US1-6). Se crea únicamente con App\Actions\Entregas\CreateDelivery.
 *
 * - D-001: el consecutivo `code` (ENT-000001) lo asigna la base de datos.
 * - D-002: la firma es un PNG privado en el disco ASSETS_DISK; se sirve con
 *   URL temporal.
 * - D-005: el estado (abierta, cerrada, vencida) se deriva de los ítems.
 * - M-3: signature_sha256 es la huella del PNG de la firma (signatureIntact()).
 *
 * B-1: id, signature_path y signature_sha256 no son asignables en masa; solo
 * CreateDelivery los fija con forceCreate().
 */
#[Fillable([
    'employee_id', 'kind', 'due_on', 'from_location_id', 'notes', 'delivered_by', 'delivered_at',
])]
class Delivery extends Model
{
    /** @use HasFactory<DeliveryFactory> */
    use HasFactory, InsertOnly;

    public const string IMMUTABLE_MESSAGE = 'Las entregas no se modifican ni se borran.';

    public const ?string UPDATED_AT = null;

    /**
     * Zona horaria de la estación: "hoy" para préstamos y vencidos.
     */
    public const string LOCAL_TIMEZONE = 'America/Bogota';

    /**
     * D-002: vigencia del enlace temporal de la firma.
     */
    public const int SIGNATURE_URL_MINUTES = 10;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => DeliveryKind::class,
            'due_on' => 'date:Y-m-d',
            'delivered_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * D-001: el consecutivo lo pone el DEFAULT de la columna; se lee tras
     * insertar para que la instancia quede completa.
     */
    protected static function booted(): void
    {
        static::created(function (Delivery $delivery) {
            if ($delivery->code === null) {
                $delivery->code = DB::table('deliveries')->where('id', $delivery->id)->value('code');
                $delivery->syncOriginalAttribute('code');
            }
        });
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'from_location_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function deliveredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delivered_by');
    }

    /**
     * @return HasMany<DeliveryItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(DeliveryItem::class)->orderBy('id');
    }

    /**
     * "Hoy" en la estación (Y-m-d).
     */
    public static function today(): string
    {
        return now(self::LOCAL_TIMEZONE)->toDateString();
    }

    /**
     * Unidades aún sin devolver (con los ítems cargados).
     */
    public function pendingQuantity(): int
    {
        return (int) $this->items->sum(fn (DeliveryItem $item) => $item->pending_quantity);
    }

    /**
     * D-005: estado derivado (requiere los ítems cargados).
     */
    public function status(): DeliveryStatus
    {
        if ($this->pendingQuantity() === 0) {
            return DeliveryStatus::Closed;
        }

        return $this->isPastDue() ? DeliveryStatus::Overdue : DeliveryStatus::Open;
    }

    private function isPastDue(): bool
    {
        return $this->kind === DeliveryKind::Prestamo
            && $this->due_on !== null
            && $this->due_on->toDateString() < self::today();
    }

    /**
     * D-005: filtro por estado derivado con subconsultas.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function withStatus(Builder $query, DeliveryStatus $status): void
    {
        $pending = fn (Builder $items) => $items->pending();
        $pastDue = fn (Builder $query) => $query
            ->where($this->qualifyColumn('kind'), DeliveryKind::Prestamo->value)
            ->where($this->qualifyColumn('due_on'), '<', self::today());

        match ($status) {
            DeliveryStatus::Closed => $query->whereDoesntHave('items', $pending),
            DeliveryStatus::Overdue => $query->whereHas('items', $pending)->where($pastDue),
            DeliveryStatus::Open => $query->whereHas('items', $pending)->whereNot($pastDue),
        };
    }

    /**
     * FR-005 / RN-026: préstamos vencidos con pendientes.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function overdue(Builder $query): void
    {
        $query->withStatus(DeliveryStatus::Overdue);
    }

    /**
     * Búsqueda por consecutivo o por nombre del empleado (sin distinguir
     * mayúsculas ni tildes). No busca por documento: en Entregas el empleado
     * se muestra sin datos personales (RN-022, D-006).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function search(Builder $query, ?string $term): void
    {
        if ($term === null || trim($term) === '') {
            return;
        }

        $fold = fn (string $expression) => "translate(lower({$expression}), 'áéíóúüñ', 'aeiouun')";
        $pattern = '%'.addcslashes((string) preg_replace('/\s+/u', ' ', trim($term)), '\\%_').'%';

        $query->where(function (Builder $query) use ($fold, $pattern) {
            $query->whereRaw("{$fold('deliveries.code')} like {$fold('?')}", [$pattern])
                ->orWhereHas('employee', function (Builder $employee) use ($fold, $pattern) {
                    $employee->where(function (Builder $employee) use ($fold, $pattern) {
                        foreach (['first_name', 'last_name', "first_name || ' ' || last_name", "last_name || ' ' || first_name"] as $expression) {
                            $employee->orWhereRaw("{$fold($expression)} like {$fold('?')}", [$pattern]);
                        }
                    });
                });
        });
    }

    /**
     * D-002: disco privado de las firmas (el mismo de las fotos, ASSETS_DISK).
     */
    public static function signatureDisk(): Filesystem
    {
        return Asset::photoDisk();
    }

    public static function signaturePathFor(int $deliveryId): string
    {
        return "signatures/{$deliveryId}.png";
    }

    /**
     * D-002: enlace temporal (10 minutos) a la firma; nunca público.
     */
    public function signatureUrl(): string
    {
        return self::signatureDisk()->temporaryUrl($this->signature_path, now()->addMinutes(self::SIGNATURE_URL_MINUTES));
    }

    /**
     * M-3: el archivo de la firma existe y su SHA-256 coincide con el
     * registrado al crear la entrega (falso si se cambió o se perdió).
     */
    public function signatureIntact(): bool
    {
        try {
            $contents = self::signatureDisk()->get($this->signature_path);
        } catch (UnableToReadFile) {
            return false;
        }

        return $contents !== null && hash_equals((string) $this->signature_sha256, hash('sha256', $contents));
    }
}
