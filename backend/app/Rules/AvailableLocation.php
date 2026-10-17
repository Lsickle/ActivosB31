<?php

namespace App\Rules;

use App\Models\Location;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Edge case de 005: un activo solo se ubica en una ubicación disponible
 * (activa y de un tipo de ubicación activo, Location::available()).
 */
class AvailableLocation implements ValidationRule
{
    public const string INACTIVE_MESSAGE = 'La ubicación seleccionada está inactiva.';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);

        if ($id === false) {
            return;
        }

        if (! Location::query()->whereKey($id)->exists()) {
            $fail('validation.exists')->translate();

            return;
        }

        if (! Location::query()->available()->whereKey($id)->exists()) {
            $fail(self::INACTIVE_MESSAGE);
        }
    }
}
