<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReturnMedicineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'request_id' => ['required', 'uuid'],
            'sale_id' => ['required', 'integer', 'exists:sales,id'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.sale_item_id' => ['required_with:items', 'integer'],
            'items.*.sale_item_batch_allocation_id' => ['sometimes', 'integer'],
            'items.*.quantity_returned' => ['required_with:items', 'integer', 'min:1'],
            'items.*.reason' => ['required_with:items', 'string', 'max:500'],
            'items.*.condition' => ['required_with:items', 'in:restockable,damaged,expired'],
            'sale_item_id' => ['required_without:items', 'integer'],
            'sale_item_batch_allocation_id' => ['sometimes', 'integer'],
            'quantity_returned' => ['required_without:items', 'integer', 'min:1'],
            'reason' => ['required_without:items', 'string', 'max:500'],
            'condition' => ['required_without:items', 'in:restockable,damaged,expired'],
        ];
    }

}
