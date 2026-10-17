<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\Position;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_type' => 'CC',
            'document_number' => (string) fake()->unique()->numberBetween(1000000, 1999999999),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'position_id' => Position::factory(),
            'company_id' => null,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
