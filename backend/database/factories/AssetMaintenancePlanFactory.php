<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\AssetMaintenancePlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssetMaintenancePlan>
 */
class AssetMaintenancePlanFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'asset_id' => Asset::factory(),
            'name' => fake()->randomElement(['Revisión general', 'Prueba hidrostática', 'Calibración']),
            'interval_months' => 12,
            'last_done_on' => null,
            'alert_days' => AssetMaintenancePlan::DEFAULT_ALERT_DAYS,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
