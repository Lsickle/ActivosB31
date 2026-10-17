<?php

namespace App\Http\Controllers\Concerns;

use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * B-4: si dos peticiones pasan la validación de unicidad a la vez, el índice
 * único de la base de datos rechaza la segunda; se responde 422 con el mismo
 * mensaje de duplicado que daría la validación, en vez de un 500.
 */
trait TranslatesUniqueViolations
{
    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @param  array<string, string>  $constraintFields  nombre del índice único => campo del formulario
     * @return TResult
     *
     * @throws ValidationException
     */
    protected function translatingUniqueViolations(Closure $callback, array $constraintFields): mixed
    {
        try {
            return $callback();
        } catch (UniqueConstraintViolationException $exception) {
            foreach ($constraintFields as $constraint => $field) {
                if (str_contains($exception->getMessage(), $constraint)) {
                    throw ValidationException::withMessages([
                        $field => [__('validation.unique', ['attribute' => __("validation.attributes.{$field}")])],
                    ]);
                }
            }

            throw $exception;
        }
    }
}
