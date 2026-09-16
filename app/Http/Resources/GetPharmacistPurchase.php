<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\Pharmacist;

class GetPharmacistPurchase extends JsonResource
{

    public function toArray(Request $request): array
    {
        $pharmacist=Pharmacist::find($this->pharmacist_id);

        return [
        'purchase_id' => $this->id,
        'pharmacist'=>$pharmacist->first_name . ' ' . $pharmacist->last_name,
        'Purchase Date'=>$this->purchase_date,
        'items' => $this->purchaseItems->map(function ($item) {
            $received = (int) ($this->receipt?->lines->where('purchase_item_id', $item->id)->sum('quantity_received') ?? 0);
            return [
                'medicine_name' => optional($item->medicine)->name,
                'quantity' => $item->quantity,
                'price' => $item->price,
                'quantity_requested' => (int) $item->quantity,
                'quantity_received' => $received,
                'quantity_short' => (int) $item->quantity - $received,
                'unit_purchase_cost' => $item->price,
                'catalog_sale_price' => $item->medicine?->price,
            ];
        })->values(), 
    ];










}



}
