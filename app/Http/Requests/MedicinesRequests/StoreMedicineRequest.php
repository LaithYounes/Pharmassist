<?php

namespace App\Http\Requests\MedicinesRequests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreMedicineRequest extends FormRequest
{

    public function authorize(): bool
    {
        return Gate::allows('manage-pharmacy');
    }


    public function rules(): array
    {
        return [
            'name'=>'required|string',
            'manufacturer' => 'required|string|exists:manufacturers,company_name',
            'categories' => 'required|array|min:1',
            'categories.*' => 'string|exists:categories,category_name',
            'prescription'=>'string',
            'production_Date'=>'required|Date',
            'expiration_Date'=>'required|date|after_or_equal:production_date',
            'quantity_in_stock'=>'required|integer|in:0',
            'sci_name'=>'required|string',
            'price' => ['required', 'regex:/^(0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/D'],
            'minimum_quantity'=>'required|numeric|min:0'
        ];
    }
}
