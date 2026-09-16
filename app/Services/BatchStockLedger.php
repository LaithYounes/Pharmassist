<?php

namespace App\Services;

use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BatchStockLedger
{
    public function record(MedicineBatch $batch, string $type, int $delta, string $sourceType,
        int $sourceId, ?int $actorId, ?string $reason = null, int $eventQuantity = 0): StockMovement
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Batch movement requires an enclosing transaction.');
        }
        if ($sourceType === '' || $sourceId < 1 || ($delta === 0 && $eventQuantity < 1)) {
            throw new \InvalidArgumentException('Movement needs a source and a real quantity or event.');
        }
        $allowed = ['opening', 'receipt', 'sale', 'return_restock', 'return_unsellable',
            'damage', 'adjustment_positive', 'adjustment_negative'];
        if (!in_array($type, $allowed, true)) {
            throw new \InvalidArgumentException('Unknown batch movement type.');
        }
        if (in_array($type, ['damage', 'adjustment_positive', 'adjustment_negative'], true)
            && trim((string) $reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required.']);
        }
        if (StockMovement::where('source_type', $sourceType)->where('source_id', $sourceId)->exists()) {
            throw ValidationException::withMessages(['source' => 'This source already has a stock movement.']);
        }
        $locked = MedicineBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
        $before = (int) $locked->available_quantity;
        $after = $before + $delta;
        $signs = ['opening' => 1, 'receipt' => 1, 'sale' => -1, 'return_restock' => 1,
            'return_unsellable' => 0, 'damage' => -1, 'adjustment_positive' => 1,
            'adjustment_negative' => -1];
        if (($signs[$type] === 0 && ($delta !== 0 || $eventQuantity < 1))
            || ($signs[$type] !== 0 && $delta * $signs[$type] <= 0)) {
            throw new \InvalidArgumentException('Movement direction does not match its type.');
        }
        if ($after < 0) {
            throw ValidationException::withMessages(['quantity' => 'Batch quantity cannot become negative.']);
        }
        $movement = StockMovement::create([
            'medicine_id' => $locked->medicine_id, 'batch_id' => $locked->id,
            'type' => $type, 'quantity' => $eventQuantity ?: abs($delta),
            'quantity_delta' => $delta, 'quantity_before' => $before, 'quantity_after' => $after,
            'source_type' => $sourceType, 'source_id' => $sourceId,
            'pharmacist_id' => $actorId, 'reason' => $reason, 'occurred_at' => now(),
            'unit_purchase_cost_at_movement' => $locked->unit_purchase_cost,
        ]);
        if ($delta !== 0 && DB::getDriverName() !== 'sqlite') {
            $locked->available_quantity = $after;
            $locked->saveOrFail();
        }
        Medicine::whereKey($locked->medicine_id)->update([
            'quantity_in_stock' => MedicineBatch::where('medicine_id', $locked->medicine_id)->sum('available_quantity'),
        ]);
        return $movement;
    }
}
