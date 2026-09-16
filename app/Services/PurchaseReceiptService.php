<?php

namespace App\Services;

use App\Enums\PurchaseStatus;
use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\Pharmacist;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReceipt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PurchaseReceiptService
{
    public function __construct(private PurchaseLifecycle $lifecycle, private BatchStockLedger $ledger) {}

    public function receive(int $purchaseId, Pharmacist $actor, array $lines): array
    {
        return DB::transaction(function () use ($purchaseId, $actor, $lines) {
            $purchase = Purchase::whereKey($purchaseId)->lockForUpdate()->firstOrFail();
            if ($purchase->statusCode() !== PurchaseStatus::Approved || $purchase->receipt()->exists()) {
                throw new ConflictHttpException('Only an unreceived approved purchase can be received.');
            }
            $items = PurchaseItem::where('purchase_id', $purchaseId)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $totals = $items->mapWithKeys(fn ($item) => [$item->id => 0])->all();
            $seen = [];
            foreach ($lines as $line) {
                $id = (int) $line['purchase_item_id'];
                $item = $items->get($id);
                if (!$item || (isset($line['medicine_id']) && (int) $line['medicine_id'] !== (int) $item->medicine_id)) {
                    throw ValidationException::withMessages(['batches' => 'A batch does not belong to this purchase item.']);
                }
                $key = $item->medicine_id.'|'.$line['batch_number'];
                if (isset($seen[$key])) {
                    throw ValidationException::withMessages(['batches' => 'Duplicate batch in receipt.']);
                }
                $seen[$key] = true;
                $totals[$id] += (int) $line['quantity_received'];
                if ($totals[$id] > (int) $item->quantity) {
                    throw ValidationException::withMessages(['batches' => 'Received quantity exceeds requested quantity.']);
                }
            }
            $medicineIds = $items->pluck('medicine_id')->unique()->sort()->values();
            Medicine::whereIn('id', $medicineIds)->orderBy('id')->lockForUpdate()->get();
            $receipt = PurchaseReceipt::create([
                'purchase_id' => $purchaseId, 'received_by' => $actor->id, 'received_at' => now(),
            ]);
            foreach ($lines as $line) {
                $item = $items->get((int) $line['purchase_item_id']);
                $batch = MedicineBatch::where('medicine_id', $item->medicine_id)
                    ->where('batch_number', $line['batch_number'])->lockForUpdate()->first();
                $cost = $item->price === null || Money::cents($item->price) === 0
                    ? null : Money::decimal(Money::cents($item->price));
                if ($batch) {
                    if ($batch->expiration_date->toDateString() !== $line['expiration_date']
                        || $batch->status !== 'available' || $batch->unit_purchase_cost !== $cost) {
                        throw ValidationException::withMessages(['batches' => 'Existing batch expiry, status or cost differs.']);
                    }
                } else {
                    $batch = MedicineBatch::create([
                        'medicine_id' => $item->medicine_id, 'batch_number' => $line['batch_number'],
                        'expiration_date' => $line['expiration_date'],
                        'available_quantity' => 0,
                        'unit_purchase_cost' => $cost, 'status' => 'available',
                    ]);
                }
                $receiptLine = $receipt->lines()->create([
                    'purchase_item_id' => $item->id, 'medicine_batch_id' => $batch->id,
                    'quantity_received' => (int) $line['quantity_received'],
                    'quantity_before' => (int) $batch->available_quantity,
                    'quantity_after' => (int) $batch->available_quantity + (int) $line['quantity_received'],
                ]);
                $this->ledger->record($batch, 'receipt', (int) $line['quantity_received'],
                    'purchase_receipt_line', $receiptLine->id, $actor->id);
            }
            $this->lifecycle->transitionLocked($purchase, PurchaseStatus::Received, $actor);
            return [
                'purchase_id' => $purchaseId, 'status' => 'received', 'receipt_id' => $receipt->id,
                'items' => $items->values()->map(fn ($item) => [
                    'purchase_item_id' => $item->id, 'medicine_id' => $item->medicine_id,
                    'quantity_requested' => (int) $item->quantity,
                    'quantity_received' => $totals[$item->id],
                    'quantity_short' => (int) $item->quantity - $totals[$item->id],
                ])->all(),
            ];
        }, 3);
    }
}
