<?php

namespace App\Http\Requests\MedicinesRequests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateMedicineRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Gate::allows('manage-pharmacy');
    }


    public function rules(): array
    {
        return [
            'name' => 'sometimes|string',
            'manufacturer_id' => 'sometimes|exists:manufacturers,id',
            'category_id' => 'sometimes|exists:categories,id',
            'prescription' => 'sometimes|nullable|string',
            'production_date' => 'sometimes|date',
            'expiration_date' => 'sometimes|date|after_or_equal:production_date',
            'quantity_in_stock' => 'prohibited',
            'barcode' => 'sometimes|string',
            'sci_name' => 'sometimes|string',
            'price' => ['sometimes', 'regex:/^(0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/D'],
            'minimum_quantity'=>'sometimes|numeric|min:0'
        ];
    }
}
