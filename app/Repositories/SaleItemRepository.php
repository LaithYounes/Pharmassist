<?php

namespace App\Repositories;

use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleItemBatchAllocation;
use App\Services\BatchStockLedger;
use App\Services\Money;
use App\Repositories\Interfaces\SaleItemRepositoryInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class SaleItemRepository implements SaleItemRepositoryInterface
{
    public function __construct(private BatchStockLedger $ledger) {}

    public function sell(array $data): array
    {
        try {
            return DB::transaction(function () use ($data) {
                if (!empty($data['request_id'])) {
                    $previous = Sale::where('request_id', $data['request_id'])->lockForUpdate()->first();
                    if ($previous) {
                        if ($previous->request_hash !== hash('sha256', json_encode($data['items']))
                            || (int) $previous->pharmacist_id !== (int) Auth::id()) {
                            return ['status' => false, 'message' => 'Request ID has different sale details'];
                        }
                        return ['status' => true, 'message' => 'Sale already completed',
                            'sale_id' => $previous->id, 'pharmacist_id' => $previous->pharmacist_id,
                            'replayed' => true];
                    }
                }
                $quantities = [];
                foreach ($data['items'] as $item) {
                    $id = (int) $item['medicine_id'];
                    $quantities[$id] = ($quantities[$id] ?? 0) + (int) $item['quantity'];
                }
                ksort($quantities, SORT_NUMERIC);

                $medicines = [];
                $plans = [];
                $saleDate = now();
                $saleDay = $saleDate->toDateString();
                foreach ($quantities as $id => $quantity) {
                    $medicine = Medicine::whereKey($id)->lockForUpdate()->first();
                    if (!$medicine) {
                        return ['status' => false, 'message' => 'Insufficient stock for medicine '.$id];
                    }
                    $medicines[$id] = $medicine;

                    // A batch expiring on the sale date is eligible through that day.
                    // Locking the parent medicine first serializes sales for a medicine;
                    // the conditional batch update below also guards the actual stock.
                    $batches = MedicineBatch::where('medicine_id', $id)
                        ->where('status', 'available')
                        ->whereDate('expiration_date', '>=', $saleDay)
                        ->where('available_quantity', '>', 0)
                        ->orderBy('expiration_date')->orderBy('id')
                        ->lockForUpdate()->get();
                    if ($batches->sum('available_quantity') < $quantity) {
                        return ['status' => false, 'message' => 'Insufficient stock for medicine '.$id];
                    }
                    $remaining = $quantity;
                    foreach ($batches as $batch) {
                        if ($remaining === 0) {
                            break;
                        }
                        $take = min($remaining, (int) $batch->available_quantity);
                        $plans[$id][] = ['batch' => $batch, 'take' => $take, 'remaining' => $take];
                        $remaining -= $take;
                    }
                }

                $total = 0;
                foreach ($data['items'] as $item) {
                    $total = Money::add($total, Money::multiply(
                        Money::cents($medicines[(int) $item['medicine_id']]->price), (int) $item['quantity']));
                }
                if ($total > 99_999_999_999_999) {
                    throw ValidationException::withMessages(['items' => 'Invoice total exceeds monetary column capacity.']);
                }
                $sale = Sale::create([
                    'pharmacist_id' => Auth::id(),
                    'sale_date' => $saleDate,
                    'total_price' => Money::decimal($total),
                    'request_id' => $data['request_id'] ?? null,
                    'request_hash' => isset($data['request_id']) ? hash('sha256', json_encode($data['items'])) : null,
                ]);
                foreach ($data['items'] as $item) {
                    $medicine = $medicines[(int) $item['medicine_id']];
                    $saleItem = SaleItem::create([
                        'sale_id' => $sale->id,
                        'medicine_id' => $medicine->id,
                        'quantity' => (int) $item['quantity'],
                        'price' => $medicine->price,
                    ]);
                    $remaining = (int) $item['quantity'];
                    foreach ($plans[(int) $medicine->id] as &$part) {
                        if ($remaining === 0) {
                            break;
                        }
                        $take = min($remaining, $part['remaining']);
                        if ($take === 0) {
                            continue;
                        }
                        $allocation = SaleItemBatchAllocation::create([
                            'sale_item_id' => $saleItem->id,
                            'batch_id' => $part['batch']->id,
                            'quantity' => $take,
                            'unit_purchase_cost_at_sale' => $part['batch']->unit_purchase_cost,
                        ]);
                        $this->ledger->record($part['batch'], 'sale', -$take,
                            'sale_allocation', $allocation->id, Auth::id());
                        $part['remaining'] -= $take;
                        $remaining -= $take;
                    }
                    unset($part);
                }
                return [
                    'status' => true,
                    'message' => 'Sale operation completed successfully',
                    'sale_id' => $sale->id,
                    'pharmacist_id' => $sale->pharmacist_id,
                ];
            }, 3);
        } catch (ValidationException|\InvalidArgumentException $e) {
            return ['status' => false, 'message' => $e->getMessage(), 'http_status' => 422];
        } catch (Throwable $e) {
            report($e);
            return ['status' => false, 'message' => 'Sale could not be completed', 'http_status' => 500];
        }
    }
}
