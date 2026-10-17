<?php

namespace App\Models;

use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Empleado de la estación (RN-021). Sus datos personales solo los ve quien
 * tenga empleados.ver (RN-022, Ley 1581 de 2012). No se borra, se desactiva
 * para conservar su historial de entregas.
 */
#[Fillable([
    'document_type', 'document_number', 'first_name', 'last_name', 'position_id', 'company_id',
    'phone', 'email', 'hire_date', 'shirt_size', 'pants_size', 'boot_size', 'glove_size',
])]
class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use HasFactory;

    /**
     * FR-002: tipos de documento admitidos (CHECK en la base de datos).
     *
     * @var list<string>
     */
    public const array DOCUMENT_TYPES = ['CC', 'CE', 'TI', 'PA', 'PPT'];

    /**
     * Zona horaria de la estación: "hoy" para la fecha de ingreso.
     */
    public const string LOCAL_TIMEZONE = 'America/Bogota';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hire_date' => 'date:Y-m-d',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Position, $this>
     */
    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * RN-015: usuario del sistema vinculado (opcional, uno a uno, por
     * users.employee_id).
     *
     * @return HasOne<User, $this>
     */
    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    /**
     * @return Attribute<string, never>
     */
    protected function fullName(): Attribute
    {
        return Attribute::get(fn () => trim("{$this->first_name} {$this->last_name}"));
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where($this->qualifyColumn('is_active'), true);
    }

    /**
     * FR-001: apellidos, nombres y desempate estable.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function alphabetical(Builder $query): void
    {
        $query->orderBy($this->qualifyColumn('last_name'))
            ->orderBy($this->qualifyColumn('first_name'))
            ->orderBy($this->qualifyColumn('id'));
    }

    /**
     * D-003: nombres, apellidos y nombre completo (en ambos órdenes) sin
     * distinguir mayúsculas ni tildes, con translate() de PostgreSQL. El
     * documento se compara además con el término normalizado como en D-002,
     * para encontrar "80.123.456" aunque se guarde "80123456".
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
        [$first, $last, $document] = array_map(
            fn (string $column) => $grammar->wrap($this->qualifyColumn($column)),
            ['first_name', 'last_name', 'document_number'],
        );

        $fold = fn (string $expression) => "translate(lower({$expression}), 'áéíóúüñ', 'aeiouun')";
        $pattern = '%'.addcslashes((string) preg_replace('/\s+/u', ' ', trim($term)), '\\%_').'%';
        $documentPattern = self::normalizeDocumentNumber($term);

        $query->where(function (Builder $query) use ($fold, $pattern, $documentPattern, $first, $last, $document) {
            foreach ([$first, $last, $document, "{$first} || ' ' || {$last}", "{$last} || ' ' || {$first}"] as $expression) {
                $query->orWhereRaw("{$fold($expression)} like {$fold('?')}", [$pattern]);
            }

            if ($documentPattern !== '') {
                $query->orWhere($this->qualifyColumn('document_number'), 'like', "%{$documentPattern}%");
            }
        });
    }

    /**
     * D-002: el número de documento se guarda solo con [A-Z0-9].
     */
    public static function normalizeDocumentNumber(string $value): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($value));
    }
}
