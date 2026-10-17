<?php

namespace Database\Factories;

use App\Enums\AssetStatus;
use App\Enums\TrackingMode;
use App\Models\Asset;
use App\Models\AssetType;
use App\Models\Employee;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Asset>
 */
class AssetFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'asset_type_id' => AssetType::factory()->state(['tracking_mode' => TrackingMode::Serialized]),
            'plate' => 'B31-'.fake()->unique()->numerify('#####'),
            'serial' => fake()->bothify('SN-####-????'),
            'brand' => fake()->company(),
            'model' => fake()->bothify('M-###'),
            'status' => AssetStatus::Available,
        ];
    }

    public function at(Location $location): static
    {
        return $this->state(fn (array $attributes) => ['location_id' => $location->id]);
    }

    public function inMaintenance(): static
    {
        return $this->state(fn (array $attributes) => ['status' => AssetStatus::Maintenance]);
    }

    public function retired(): static
    {
        return $this->state(fn (array $attributes) => ['status' => AssetStatus::Retired]);
    }

    /**
     * RN-039: asignado a un empleado (normalmente lo hace AssignAsset).
     */
    public function assignedTo(?Employee $employee = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AssetStatus::Assigned,
            'holder_employee_id' => $employee?->id ?? Employee::factory(),
        ]);
    }
}
