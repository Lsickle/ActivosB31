<?php

namespace Database\Factories;

use App\Enums\DeliveryKind;
use App\Models\Delivery;
use App\Models\Employee;
use App\Models\Location;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Entrega sin ítems ni efectos (no asigna activos ni mueve existencias). En
 * el flujo real se registra con App\Actions\Entregas\CreateDelivery.
 *
 * @extends Factory<Delivery>
 */
class DeliveryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'kind' => DeliveryKind::Dotacion,
            'due_on' => null,
            'from_location_id' => Location::factory(),
            'signature_path' => 'signatures/factory-'.Str::uuid().'.png',
            // M-3: huella de un archivo que la factory no escribe.
            'signature_sha256' => hash('sha256', 'factory'),
            'notes' => null,
            'delivered_by' => User::factory(),
            'delivered_at' => now(),
        ];
    }

    /**
     * RN-023: préstamo con fecha esperada de devolución (Y-m-d).
     */
    public function loan(string $dueOn): static
    {
        return $this->state(fn (array $attributes) => ['kind' => DeliveryKind::Prestamo, 'due_on' => $dueOn]);
    }
}
