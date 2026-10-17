<?php

namespace App\Http\Controllers\Api;

use App\Actions\Entregas\RegisterDeliveryReturn;
use App\Http\Controllers\Controller;
use App\Http\Requests\Entregas\StoreDeliveryReturnRequest;
use App\Http\Resources\DeliveryResource;
use App\Models\Delivery;

/**
 * Devoluciones de una entrega (007, FR-003, D-004): entregas.gestionar.
 * Responde la entrega actualizada (sin signature_url, que solo va en la
 * ficha y al registrar).
 */
class DeliveryReturnController extends Controller
{
    public function store(StoreDeliveryReturnRequest $request, Delivery $delivery, RegisterDeliveryReturn $registerReturn): DeliveryResource
    {
        $registerReturn->handle($delivery, $request->items(), $request->user());

        return DeliveryResource::make($delivery->fresh()->load(DeliveryResource::RELATIONS));
    }
}
