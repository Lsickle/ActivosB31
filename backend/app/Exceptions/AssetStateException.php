<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * D-001: la operación no es válida para el estado actual del activo (p. ej.
 * cambiar estado o ubicación de un activo Asignado o Dado de baja, o
 * entregar uno que no está Disponible). Se responde 409 con el mensaje.
 */
class AssetStateException extends RuntimeException
{
    public const string ASSIGNED_MESSAGE = 'El activo está asignado: primero debe devolverse en Entregas.';

    public const string RETIRED_MESSAGE = 'El activo está dado de baja: la baja es definitiva.';

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 409);
    }
}
