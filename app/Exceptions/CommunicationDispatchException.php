<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A delivery row was created but its queue job could not be dispatched.
 *
 * The row is marked failed before this exception is thrown, so it can never
 * remain permanently queued without a corresponding job. The original queue
 * exception is deliberately not chained: database queue failures can include
 * serialized payloads or SQL bindings, and those must not reach logs or users.
 */
class CommunicationDispatchException extends RuntimeException
{
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'The message could not be queued. No delivery was attempted; review Delivery History before retrying.',
        ], 503);
    }
}
