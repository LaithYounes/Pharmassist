<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MakeSupplyOrderRequest extends FormRequest
{

    public function authorize(): bool
    {
        return true;
    }
public function prepareForValidation(): void
{
    $items = $this->input('items', []);
    foreach ($items as $i => $it) {
        if (!isset($it['medicine_name']) && isset($it['name'])) {
            $items[$i]['medicine_name'] = $it['name']; // map old key -> new key
        }
    }
    $this->merge(['items' => $items]);
}


    public function rules(): array
{
    return [
        'sale_representative_id'   => 'required|integer|exists:sale_representatives,id',
        'items'                    => 'required|array|min:1',
        'items.*.medicine_name'    => 'required|string|max:255',
        'items.*.quantity'         => 'required|integer|min:1',
        'status'                    => 'prohibited',
        'status_id'                 => 'prohibited',
    ];
}
}
