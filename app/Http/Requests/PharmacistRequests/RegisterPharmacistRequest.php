<?php

namespace App\Http\Requests\PharmacistRequests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class RegisterPharmacistRequest extends FormRequest
{

    public function authorize(): bool
    {
        return Gate::allows('manage-pharmacy');
    }

    public function rules(): array
    {
        return [
            'first_name' => 'required|string|max:255',
            'last_name'  => 'required|string|max:255',
            'username'   => 'required|string|max:255|unique:pharmacists,username',
            'password'   => 'required|string|min:8',
            'phone'      => 'required|string|max:255|unique:pharmacists,phone',
            'salary'     => 'required|numeric|min:0|max:999999.99|decimal:0,2',
        ];
    }
}
