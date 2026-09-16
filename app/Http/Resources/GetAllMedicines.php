<?php

namespace App\Http\Resources;

use App\Models\Manufacturer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GetAllMedicines extends JsonResource
{

    public function toArray(Request $request): array
    {
          $categories = [];
        foreach ($this->categories as $category) {
            $categories[] = [
                 $category->category_name,
            ];
        }
        $manufacturer=Manufacturer::find($this->manufacturer_id);

        return [
            'id'                => $this->id,
            'name'              => $this->name,
            'manufacturer'      => $manufacturer->company_name ?? null,
            'prescription'      => $this->prescription,
            'production_Date'   => $this->production_Date,
            'expiration_Date'   => $this->expiration_Date,
            'quantity_in_stock' => $this->batches()->sum('available_quantity'),
            'sellable_quantity' => $this->batches()->where('status', 'available')
                ->whereDate('expiration_date', '>=', today()->toDateString())
                ->sum('available_quantity'),
            'minimum_quantity'  => $this->minimum_quantity,
            'price'             => $this->price,
            'sci_name'          => $this->sci_name,
            'categories'        => $categories,
        ];
    }
}
