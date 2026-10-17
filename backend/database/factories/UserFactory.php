<?php

namespace Database\Factories;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'username' => Str::replace('@', '', fake()->unique()->userName()),
            'email' => fake()->unique()->safeEmail(),
            'is_active' => true,
            'must_change_password' => false,
            'role_id' => Role::factory(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Cuenta desactivada: no puede iniciar sesión ni usar la API.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Rol Administrador (RN-010); lo crea si los seeders no corrieron.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role_id' => Role::query()->where('code', Role::ADMIN)->value('id')
                ?? Role::factory()->create(['code' => Role::ADMIN, 'name' => 'Administrador', 'is_system' => true])->id,
        ]);
    }

    /**
     * Contraseña temporal pendiente de cambio (RN-014).
     */
    public function mustChangePassword(): static
    {
        return $this->state(fn (array $attributes) => [
            'must_change_password' => true,
        ]);
    }
}
