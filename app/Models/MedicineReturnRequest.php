<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MedicineReturnRequest extends Model
{
    protected $guarded = ['id'];

    public function returns()
    {
        return $this->hasMany(MedicineReturn::class, 'return_request_id');
    }
}
