<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesUserIdentifiers;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edición de datos y rol (RN-013). La contraseña se restablece aparte.
 */
class UpdateUserRequest extends FormRequest
{
    use NormalizesUserIdentifiers;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('user'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->route('user');
        $identifiers = $this->identifierRules($user->id);

        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'username' => ['sometimes', ...$identifiers['username']],
            'email' => ['sometimes', ...$identifiers['email']],
            // RN-017: puede conservar su rol actual aunque esté inactivo, pero
            // no recibir otro rol inactivo.
            'role_id' => [
                'sometimes', 'required', 'integer',
                Rule::exists('roles', 'id')->where(
                    fn ($query) => $query->where('is_active', true)->orWhere('id', $user->role_id),
                ),
            ],
        ];
    }
}
