<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\Pharmacist;
use App\Models\Sale;

class GetPharmacistSales extends JsonResource
{
    
    public function toArray(Request $request): array
    {
          $pharmacist=Pharmacist::find($this->pharmacist_id);

            return [
                   'sale_id' => $this->id,
                   'pharmacist' => $pharmacist? $pharmacist->first_name . ' ' . $pharmacist->last_name: null,
                   'sale_date' => $this->sale_date,
                   'total_price' => $this->total_price,
                   'items' => $this->salesItems ? $this->salesItems->map(function ($item) {
                        return [
                        'Medicine_id'=>$item->medicine_id,
                        'medicine_name' => optional($item->medicine)->name,
                        'quantity' => $item->quantity,
                        'sale_item_id' => $item->id,
                        'price' => $item->price,
                        'batches' => $item->batchAllocations->map(fn ($allocation) => [
                            'sale_item_batch_allocation_id' => $allocation->id,
                            'batch_id' => $allocation->batch_id,
                            'batch_number' => $allocation->batch?->batch_number,
                            'quantity' => $allocation->quantity,
                        ])->values(),
    ];
})->values() : collect([]), ];

    }
}
