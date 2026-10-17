<?php

namespace Database\Factories;

use App\Enums\TrackingMode;
use App\Models\AssetCategory;
use App\Models\AssetType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssetType>
 */
class AssetTypeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'asset_category_id' => AssetCategory::factory(),
            'name' => fake()->unique()->words(3, true),
            'description' => fake()->sentence(),
            'tracking_mode' => fake()->randomElement(TrackingMode::cases()),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
