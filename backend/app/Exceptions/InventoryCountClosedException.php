<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * D-004: un conteo cerrado es definitivo; registrar renglones o volver a
 * cerrarlo responde 409.
 */
class InventoryCountClosedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('El conteo ya está cerrado.');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 409);
    }
}
