<?php

namespace App\Http\Controllers\Api;

use App\Services\SlotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AvailabilityController
{
    public function __construct(
        private SlotService $slotService
    ) {}

    /**
     * Get slot availability
     */
    public function show(Request $request, int $slotId): JsonResponse
    {
        $availability = $this->slotService->getAvailability($slotId);
        
        if (isset($availability['error'])) {
            return response()->json($availability, $availability['code']);
        }
        
        return response()->json($availability);
    }
}
