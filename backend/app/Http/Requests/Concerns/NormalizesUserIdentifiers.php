<?php

namespace App\Http\Requests\Concerns;

use App\Models\User;
use App\Rules\UniqueCaseInsensitive;

/**
 * FR-008: username y correo se validan ya en minúsculas, igual que se guardan.
 */
trait NormalizesUserIdentifiers
{
    protected function prepareForValidation(): void
    {
        foreach (['username', 'email'] as $field) {
            if ($this->has($field) && (is_string($this->input($field)) || $this->input($field) === null)) {
                $this->merge([$field => User::normalizeIdentifier($this->input($field))]);
            }
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function identifierRules(?int $ignoreUserId = null): array
    {
        return [
            'username' => [
                'bail', 'required', 'string', 'min:3', 'max:40',
                'not_regex:/@/',
                'regex:/^[a-z0-9._-]+$/',
                new UniqueCaseInsensitive('users', 'username', $ignoreUserId),
            ],
            'email' => [
                'bail', 'nullable', 'string', 'email', 'max:255',
                new UniqueCaseInsensitive('users', 'email', $ignoreUserId),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'username.not_regex' => 'El nombre de usuario no puede contener "@".',
            'username.regex' => 'El nombre de usuario solo admite letras minúsculas sin tilde, números, punto, guion y guion bajo.',
        ];
    }
}
