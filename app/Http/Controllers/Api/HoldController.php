<?php

namespace App\Http\Controllers\Api;

use App\Services\SlotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HoldController
{
    public function __construct(
        private SlotService $slotService
    ) {}

    /**
     * Create a hold for a slot
     */
    public function store(Request $request, int $slotId): JsonResponse
    {
        $validated = $request->validate([
            'Idempotency-Key' => 'required|uuid',
        ], [], ['Idempotency-Key' => 'idempotency_key']);

        $result = $this->slotService->createHold($slotId, $request->header('Idempotency-Key'));
        
        if (isset($result['error'])) {
            return response()->json($result, $result['code']);
        }
        
        return response()->json($result, 201);
    }

    /**
     * Confirm a hold
     */
    public function confirm(Request $request, int $holdId): JsonResponse
    {
        $result = $this->slotService->confirmHold($holdId);
        
        if (isset($result['error'])) {
            return response()->json($result, $result['code']);
        }
        
        return response()->json($result);
    }

    /**
     * Cancel a hold
     */
    public function destroy(Request $request, int $holdId): JsonResponse
    {
        $result = $this->slotService->cancelHold($holdId);
        
        if (isset($result['error'])) {
            return response()->json($result, $result['code']);
        }
        
        return response()->json($result);
    }
}
