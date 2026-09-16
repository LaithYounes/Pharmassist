<?php
namespace App\Repositories;

use App\Models\MedicineReturn;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Repositories\Interfaces\ReportRepositoryInterface;
use App\Services\Money;

class ReportRepository implements ReportRepositoryInterface
{
    public function summary(?string $from = null, ?string $to = null): array
    {
        $sales = Sale::with('salesItems.batchAllocations')->when($from,
            fn ($q) => $q->whereDate('sale_date', '>=', $from))->when($to,
            fn ($q) => $q->whereDate('sale_date', '<=', $to))->get();
        $gross = $soldCost = $restockCost = $unsellableReturnLoss = $disposalLoss = $countShortageLoss = 0;
        $unknownUnits = 0;
        foreach ($sales as $sale) {
            $gross = Money::add($gross, Money::cents($sale->total_price));
            if ($sale->salesItems->isEmpty() && Money::cents($sale->total_price) > 0) {
                $unknownUnits++;
            }
            foreach ($sale->salesItems as $line) {
                if ($line->batchAllocations->isEmpty()) {
                    $unknownUnits += (int) $line->quantity;
                }
                foreach ($line->batchAllocations as $allocation) {
                    if ($allocation->unit_purchase_cost_at_sale === null) {
                        $unknownUnits += (int) $allocation->quantity;
                    } else {
                        $soldCost = Money::add($soldCost, Money::multiply(
                            Money::cents($allocation->unit_purchase_cost_at_sale), (int) $allocation->quantity));
                    }
                }
            }
        }
        $returns = MedicineReturn::with('saleItem', 'allocation')->when($from,
            fn ($q) => $q->whereDate('returned_at', '>=', $from))->when($to,
            fn ($q) => $q->whereDate('returned_at', '<=', $to))->get();
        $refund = 0;
        foreach ($returns as $return) {
            if ($return->saleItem?->price !== null) {
                $refund = Money::add($refund, Money::multiply(
                    Money::cents($return->saleItem->price), (int) $return->quantity_returned));
            }
            $cost = $return->allocation?->unit_purchase_cost_at_sale;
            if ($cost === null) {
                $unknownUnits += (int) $return->quantity_returned;
            } else {
                $restockCost = Money::add($restockCost, Money::multiply(
                    Money::cents($cost), (int) $return->quantity_restocked));
                $unsellableReturnLoss = Money::add($unsellableReturnLoss, Money::multiply(
                    Money::cents($cost), (int) $return->quantity_returned - (int) $return->quantity_restocked));
            }
        }
        $disposals = StockMovement::whereIn('type', ['damage', 'adjustment_negative'])->when($from,
            fn ($q) => $q->whereDate('occurred_at', '>=', $from))->when($to,
            fn ($q) => $q->whereDate('occurred_at', '<=', $to))->get();
        foreach ($disposals as $movement) {
            if ($movement->unit_purchase_cost_at_movement === null) {
                $unknownUnits += $movement->quantity;
            } else {
                $loss = Money::multiply(Money::cents($movement->unit_purchase_cost_at_movement), $movement->quantity);
                if ($movement->type === 'damage') {
                    $disposalLoss = Money::add($disposalLoss, $loss);
                } else {
                    $countShortageLoss = Money::add($countShortageLoss, $loss);
                }
            }
        }
        $net = Money::add($gross, -$refund);
        $margin = $unknownUnits === 0 ? Money::decimal(
            Money::add(Money::add(Money::add(Money::add($net, -$soldCost), $restockCost),
                -$disposalLoss), -$countShortageLoss)) : null;
        return [
            'gross_sales' => Money::decimal($gross), 'returns_at_invoice_price' => Money::decimal($refund),
            'net_sales' => Money::decimal($net),
            'sold_batch_cost_known' => Money::decimal($soldCost),
            'restocked_return_cost_reversal_known' => Money::decimal($restockCost),
            'unsellable_return_loss_known' => Money::decimal($unsellableReturnLoss),
            'disposal_loss_known' => Money::decimal($disposalLoss),
            'count_shortage_loss_known' => Money::decimal($countShortageLoss),
            'gross_margin_after_losses' => $margin, 'unknown_cost_units' => $unknownUnits,
            'margin_complete' => $unknownUnits === 0,
            'method' => 'Refund every return at saved invoice price. Restockable returns reverse their saved sold cost; unsellable returns retain that cost as a loss. Disposal and negative count adjustment costs are reported separately and deducted from margin. Unknown cost leaves margin null.',
        ];
    }

    public function getTotalSales() { return $this->summary()['gross_sales']; }
    public function getTotalReturns() { return $this->summary()['returns_at_invoice_price']; }
    public function getNetSales() { return $this->summary()['net_sales']; }
    public function getDailyNetSales($date = null) {
        $date ??= now()->toDateString();
        return $this->summary($date, $date)['net_sales'];
    }
    public function getMonthlyNetSales($year, $month) {
        $from = sprintf('%04d-%02d-01', $year, $month);
        $to = date('Y-m-t', strtotime($from));
        return $this->summary($from, $to)['net_sales'];
    }
}
