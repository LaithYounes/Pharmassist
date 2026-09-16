<?php

namespace App\Repositories;

use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\MedicineReturn;
use App\Models\MedicineReturnRequest;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleItemBatchAllocation;
use App\Services\BatchStockLedger;
use App\Repositories\Interfaces\MedicineReturnRepositoryInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MedicineReturnRepository implements MedicineReturnRepositoryInterface
{
    public function __construct(private BatchStockLedger $ledger) {}
    public function getAll()
    {
        return MedicineReturn::with(['sale', 'saleItem', 'allocation.batch', 'performer'])->get();
    }

    public function getBySaleId($saleId)
    {
        return MedicineReturn::where('sale_id', $saleId)
            ->with(['saleItem', 'allocation.batch', 'performer'])->get();
    }

    public function store(array $data): array
    {
        $items = $data['items'] ?? [[
            'sale_item_id' => $data['sale_item_id'],
            'sale_item_batch_allocation_id' => $data['sale_item_batch_allocation_id'] ?? null,
            'quantity_returned' => $data['quantity_returned'],
            'reason' => $data['reason'],
            'condition' => $data['condition'],
        ]];
        $hashData = $data;
        unset($hashData['request_id']);
        $payloadHash = hash('sha256', json_encode($hashData, JSON_THROW_ON_ERROR));

        try {
            return DB::transaction(function () use ($data, $items, $payloadHash) {
            // One invoice lock serializes returns against every allocation on it.
            $sale = Sale::whereKey($data['sale_id'])->lockForUpdate()->firstOrFail();
            $previousRequest = MedicineReturnRequest::where('request_id', $data['request_id'])
                ->lockForUpdate()->first();
            if ($previousRequest) {
                if ((int) $previousRequest->sale_id !== (int) $sale->id
                    || $previousRequest->payload_hash !== $payloadHash
                    || (int) $previousRequest->pharmacist_id !== (int) Auth::id()) {
                    throw ValidationException::withMessages(['request_id' => 'This request ID was used with different return details.']);
                }
                return ['returns' => $previousRequest->returns()
                    ->with(['allocation.batch', 'performer'])->get(), 'replayed' => true];
            }

            $lineIds = array_unique(array_map(fn ($item) => (int) $item['sale_item_id'], $items));
            sort($lineIds, SORT_NUMERIC);
            $lines = [];
            $allocationsByLine = [];
            foreach ($lineIds as $lineId) {
                $line = SaleItem::whereKey($lineId)->lockForUpdate()->first();
                if (!$line || (int) $line->sale_id !== (int) $sale->id) {
                    throw ValidationException::withMessages(['items' => 'Invalid sale item for this sale.']);
                }
                $allocationsByLine[$lineId] = $line->batchAllocations()->orderBy('id')->get();
                if ($allocationsByLine[$lineId]->isEmpty()) {
                    throw ValidationException::withMessages(['items' => 'This sale item has no saved batch allocation.']);
                }
                if ($line->medicineReturns()->whereNull('sale_item_batch_allocation_id')->exists()) {
                    throw ValidationException::withMessages(['items' => 'Historical returns for this line require reconciliation before batch returns.']);
                }
                $lines[$lineId] = $line;
            }

            $resolved = [];
            $requested = [];
            foreach ($items as $index => $item) {
                $lineId = (int) $item['sale_item_id'];
                $lineAllocations = $allocationsByLine[$lineId];
                $allocationId = $item['sale_item_batch_allocation_id'] ?? null;
                if ($allocationId === null) {
                    if ($lineAllocations->count() !== 1) {
                        throw ValidationException::withMessages(["items.$index.sale_item_batch_allocation_id" => 'Choose the original sale batch allocation.']);
                    }
                    $allocationId = $lineAllocations->first()->id;
                }
                $allocationId = (int) $allocationId;
                if (!$lineAllocations->contains('id', $allocationId)) {
                    throw ValidationException::withMessages(["items.$index.sale_item_batch_allocation_id" => 'Allocation does not belong to this invoice line.']);
                }
                $resolved[] = ['item' => $item, 'allocation_id' => $allocationId, 'line_id' => $lineId];
                $requested[$allocationId] = ($requested[$allocationId] ?? 0) + (int) $item['quantity_returned'];
            }
            ksort($requested, SORT_NUMERIC);

            $allocations = [];
            foreach ($requested as $allocationId => $quantity) {
                $allocation = SaleItemBatchAllocation::whereKey($allocationId)->lockForUpdate()->firstOrFail();
                $returned = (int) $allocation->medicineReturns()->sum('quantity_returned');
                if ($quantity > (int) $allocation->quantity - $returned) {
                    throw ValidationException::withMessages(['items' => 'Return quantity exceeds the quantity sold from its batch allocation.']);
                }
                $allocations[$allocationId] = $allocation;
            }

            $medicineIds = array_unique(array_map(fn ($line) => (int) $line->medicine_id, $lines));
            sort($medicineIds, SORT_NUMERIC);
            foreach ($medicineIds as $medicineId) {
                if (!Medicine::whereKey($medicineId)->lockForUpdate()->first()) {
                    throw ValidationException::withMessages(['items' => 'Medicine for sale item no longer exists.']);
                }
            }
            $batchIds = array_unique(array_map(fn ($allocation) => (int) $allocation->batch_id, $allocations));
            sort($batchIds, SORT_NUMERIC);
            $batches = [];
            foreach ($batchIds as $batchId) {
                $batches[$batchId] = MedicineBatch::whereKey($batchId)->lockForUpdate()->firstOrFail();
            }

            $request = MedicineReturnRequest::create([
                'request_id' => $data['request_id'], 'sale_id' => $sale->id,
                'pharmacist_id' => Auth::id(), 'payload_hash' => $payloadHash,
            ]);
            $created = [];
            foreach ($resolved as $entry) {
                $item = $entry['item'];
                $allocation = $allocations[$entry['allocation_id']];
                $batch = $batches[$allocation->batch_id];
                if ((int) $batch->medicine_id !== (int) $lines[$entry['line_id']]->medicine_id) {
                    throw ValidationException::withMessages(['items' => 'Saved allocation has an invalid medicine batch.']);
                }
                $sellable = $item['condition'] === 'restockable'
                    && $batch->status === 'available'
                    && $batch->expiration_date->toDateString() >= now()->toDateString();
                $restocked = $sellable ? (int) $item['quantity_returned'] : 0;
                $return = MedicineReturn::create([
                    'sale_id' => $sale->id,
                    'sale_item_id' => $entry['line_id'],
                    'sale_item_batch_allocation_id' => $allocation->id,
                    'return_request_id' => $request->id,
                    'quantity_returned' => (int) $item['quantity_returned'],
                    'quantity_restocked' => $restocked,
                    'reason' => $item['reason'],
                    'condition' => $item['condition'],
                    'performed_by' => Auth::id(),
                    'returned_at' => now(),
                ]);
                $created[] = $return;
                $this->ledger->record($batch, $restocked > 0 ? 'return_restock' : 'return_unsellable',
                    $restocked, 'medicine_return', $return->id, Auth::id(), $item['reason'],
                    $restocked > 0 ? 0 : (int) $item['quantity_returned']);
            }
            return ['returns' => $request->returns()->with(['allocation.batch', 'performer'])->get(), 'replayed' => false];
            }, 3);
        } catch (QueryException $e) {
            // A simultaneous use of the same UUID against a different invoice
            // can reach the unique index without sharing the invoice row lock.
            if (MedicineReturnRequest::where('request_id', $data['request_id'])->exists()) {
                throw ValidationException::withMessages(['request_id' => 'This request ID was already used.']);
            }
            throw $e;
        }
    }
}
