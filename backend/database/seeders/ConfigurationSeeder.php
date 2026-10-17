<?php

namespace Database\Seeders;

use App\Enums\TrackingMode;
use App\Models\AssetCategory;
use App\Models\LocationType;
use App\Models\Position;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Catálogos precargados de Configuración (RN-012, FR-014), confirmados por
 * el usuario el 2026-10-17 (tabla "Datos iniciales" de la spec 002).
 *
 * D-008: cada grupo se carga solo si su tabla está vacía. Así repetir la
 * carga (Render ejecuta el seeder en cada arranque) no duplica nada ni
 * recrea registros que el administrador renombró o desactivó, que no se
 * pueden borrar (RN-003).
 */
class ConfigurationSeeder extends Seeder
{
    private const TrackingMode S = TrackingMode::Serialized;

    private const TrackingMode C = TrackingMode::Quantity;

    public function run(): void
    {
        DB::transaction(function () {
            if (! AssetCategory::query()->exists()) {
                $this->seedAssetCategories();
            }

            if (! LocationType::query()->exists()) {
                $this->seedLocationTypes();
            }

            if (! Position::query()->exists()) {
                $this->seedPositions();
            }
        });
    }

    /**
     * RN-035: cargos o rangos confirmados por el usuario, en este orden
     * (editables). Las compañías o turnos no se precargan (RN-036).
     */
    private function seedPositions(): void
    {
        foreach (['Comandante', 'Capitán', 'Teniente', 'Subteniente', 'Sargento', 'Cabo', 'Bombero', 'Administrativo'] as $name) {
            Position::query()->create(['name' => $name]);
        }
    }

    private function seedAssetCategories(): void
    {
        foreach (self::assetCategories() as $categoryName => $types) {
            $category = AssetCategory::query()->create(['name' => $categoryName]);

            foreach ($types as $typeName => $trackingMode) {
                $category->assetTypes()->create(['name' => $typeName, 'tracking_mode' => $trackingMode]);
            }
        }
    }

    private function seedLocationTypes(): void
    {
        foreach (['Bodega', 'Vehículo', 'Oficina', 'Otro'] as $name) {
            LocationType::query()->create(['name' => $name]);
        }
    }

    /**
     * Categoría => [tipo de activo => modo de control (RN-007)].
     *
     * @return array<string, array<string, TrackingMode>>
     */
    private static function assetCategories(): array
    {
        return [
            'Equipos de protección personal (EPP)' => [
                'Casco estructural' => self::S,
                'Chaqueta estructural' => self::S,
                'Pantalón estructural' => self::S,
                'Botas estructurales' => self::C,
                'Guantes estructurales' => self::C,
                'Monja / capucha' => self::C,
                'Guantes de rescate' => self::C,
            ],
            'Equipos de respiración autónoma' => [
                'Equipo SCBA' => self::S,
                'Cilindro SCBA' => self::S,
                'Máscara facial' => self::S,
            ],
            'Herramientas de rescate' => [
                'Equipo hidráulico de rescate' => self::S,
                'Motosierra' => self::S,
                'Motor de corte / tronzadora' => self::S,
                'Herramientas manuales de entrada forzada' => self::C,
                'Cuerdas de rescate' => self::S,
            ],
            'Equipos de extinción' => [
                'Manguera 1½"' => self::C,
                'Manguera 2½"' => self::C,
                'Boquilla / pitón' => self::C,
                'Extintor portátil' => self::S,
            ],
            'Comunicaciones' => [
                'Radio portátil' => self::S,
                'Radio móvil / base' => self::S,
            ],
            'Atención prehospitalaria' => [
                'Desfibrilador DEA' => self::S,
                'Camilla' => self::S,
                'Inmovilizadores' => self::C,
                'Insumos médicos' => self::C,
            ],
            'Vehículos' => [
                'Máquina extintora' => self::S,
                'Ambulancia' => self::S,
                'Vehículo de rescate' => self::S,
                'Camioneta' => self::S,
            ],
            'Uniformes y dotación' => [
                'Camisa de dotación' => self::C,
                'Pantalón de dotación' => self::C,
                'Botas de dotación' => self::C,
            ],
            'Consumibles' => [
                'Agente espumógeno' => self::C,
                'Baterías y pilas' => self::C,
            ],
        ];
    }
}
