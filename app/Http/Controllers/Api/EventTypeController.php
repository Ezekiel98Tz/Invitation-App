<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\EventCapabilities;
use App\Support\EventTypes;
use Illuminate\Http\JsonResponse;

/**
 * Serves the configuration-driven event type taxonomy and capability
 * definitions so the frontend shares a single source of truth with the backend.
 *
 * Read-only and public (like the invitation template catalogue): it exposes
 * static reference data with no event-specific or user-specific information.
 */
class EventTypeController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'default' => EventTypes::default(),
            'types' => EventTypes::all(),
            'capabilities' => EventCapabilities::definitions(),
        ]);
    }
}
