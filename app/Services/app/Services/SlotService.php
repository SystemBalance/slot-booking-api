<?php

namespace App\Services;

use App\Models\Hold;
use App\Models\Slot;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SlotService
{
    private const CACHE_TTL = 15; // seconds
    private const HOLD_TTL = 300; // 5 minutes
    private const CACHE_STAMPEDE_LOCK_TTL = 2; // seconds

    /**
     * Get availability for a slot with cache and stampede protection
     */
    public function getAvailability(int $slotId): array
    {
        $cacheKey = "slot.availability.{$slotId}";
        $lockKey = "{$cacheKey}.lock";

        // Try to get from cache first
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        // Prevent cache stampede: only allow one request to compute
        if (!Cache::add($lockKey, true, self::CACHE_STAMPEDE_LOCK_TTL)) {
            // Another request is computing, wait and retry
            usleep(100000); // 100ms
            if (Cache::has($cacheKey)) {
                return Cache::get($cacheKey);
            }
        }

        try {
            // Fetch from database with lock
            $slot = Slot::lockForUpdate()->find($slotId);

            if (!$slot) {
                return [
                    'error' => 'Slot not found',
                    'code' => 404,
                ];
            }

            // Count active holds (not expired)
            $activeHolds = Hold::where('slot_id', $slotId)
                ->where('status', '!=', 'cancelled')
                ->where('expires_at', '>', now())
                ->count();

            $available = $slot->capacity - $activeHolds;

            $data = [
                'slot_id' => $slot->id,
                'capacity' => $slot->capacity,
                'available' => max(0, $available),
                'held' => $activeHolds,
            ];

            // Cache the result
            Cache::put($cacheKey, $data, self::CACHE_TTL);

            return $data;
        } finally {
            // Release the stampede lock
            Cache::forget($lockKey);
        }
    }

    /**
     * Create a hold with idempotency
     */
    public function createHold(int $slotId, string $idempotencyKey): array
    {
        // Check idempotency: return existing hold if already created
        $existingHold = Hold::where('slot_id', $slotId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existingHold) {
            return [
                'hold_id' => $existingHold->id,
                'slot_id' => $existingHold->slot_id,
                'status' => $existingHold->status,
                'expires_at' => $existingHold->expires_at,
            ];
        }

        // Start transaction for atomicity
        return DB::transaction(function () use ($slotId, $idempotencyKey) {
            // Get slot with lock to prevent race conditions
            $slot = Slot::lockForUpdate()->find($slotId);

            if (!$slot) {
                return [
                    'error' => 'Slot not found',
                    'code' => 404,
                ];
            }

            // Count active holds
            $activeHolds = Hold::where('slot_id', $slotId)
                ->where('status', '!=', 'cancelled')
                ->where('expires_at', '>', now())
                ->count();

            $available = $slot->capacity - $activeHolds;

            if ($available <= 0) {
                return [
                    'error' => 'No available slots',
                    'code' => 409,
                ];
            }

            // Create the hold
            $hold = Hold::create([
                'slot_id' => $slotId,
                'idempotency_key' => $idempotencyKey,
                'status' => 'pending',
                'expires_at' => now()->addSeconds(self::HOLD_TTL),
            ]);

            // Invalidate cache
            Cache::forget("slot.availability.{$slotId}");

            return [
                'hold_id' => $hold->id,
                'slot_id' => $hold->slot_id,
                'status' => $hold->status,
                'expires_at' => $hold->expires_at,
            ];
        });
    }

    /**
     * Confirm a hold with transaction safety
     */
    public function confirmHold(int $holdId): array
    {
        return DB::transaction(function () use ($holdId) {
            // Get hold with lock
            $hold = Hold::lockForUpdate()->find($holdId);

            if (!$hold) {
                return [
                    'error' => 'Hold not found',
                    'code' => 404,
                ];
            }

            // Check if hold is still valid
            if ($hold->status === 'confirmed') {
                return [
                    'error' => 'Hold already confirmed',
                    'code' => 409,
                ];
            }

            if ($hold->status === 'cancelled') {
                return [
                    'error' => 'Hold is cancelled',
                    'code' => 409,
                ];
            }

            if ($hold->expires_at < now()) {
                return [
                    'error' => 'Hold expired',
                    'code' => 409,
                ];
            }

            // Get slot with lock for final availability check
            $slot = Slot::lockForUpdate()->find($hold->slot_id);

            // Count active holds excluding current
            $activeHolds = Hold::where('slot_id', $hold->slot_id)
                ->where('id', '!=', $holdId)
                ->where('status', '!=', 'cancelled')
                ->where('expires_at', '>', now())
                ->count();

            if ($activeHolds >= $slot->capacity) {
                return [
                    'error' => 'Slot capacity exhausted',
                    'code' => 409,
                ];
            }

            // Update hold status
            $hold->update([
                'status' => 'confirmed',
                'confirmed_at' => now(),
            ]);

            // Invalidate cache
            Cache::forget("slot.availability.{$hold->slot_id}");

            return [
                'hold_id' => $hold->id,
                'slot_id' => $hold->slot_id,
                'status' => $hold->status,
                'confirmed_at' => $hold->confirmed_at,
            ];
        });
    }

    /**
     * Cancel a hold
     */
    public function cancelHold(int $holdId): array
    {
        return DB::transaction(function () use ($holdId) {
            $hold = Hold::lockForUpdate()->find($holdId);

            if (!$hold) {
                return [
                    'error' => 'Hold not found',
                    'code' => 404,
                ];
            }

            if ($hold->status === 'cancelled') {
                return [
                    'error' => 'Hold already cancelled',
                    'code' => 409,
                ];
            }

            // Update hold status
            $hold->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
            ]);

            // Invalidate cache
            Cache::forget("slot.availability.{$hold->slot_id}");

            return [
                'hold_id' => $hold->id,
                'status' => $hold->status,
                'cancelled_at' => $hold->cancelled_at,
            ];
        });
    }
}
