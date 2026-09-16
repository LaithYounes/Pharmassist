<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleItem extends Model
{
    protected $guarded=['id'];
    protected $casts = ['quantity' => 'integer', 'price' => 'decimal:2'];

    public function sale() {
        return $this->belongsTo(Sale::class);
    }

    public function medicine() {
        return $this->belongsTo(Medicine::class);
    }
    public function batchAllocations() {
        return $this->hasMany(SaleItemBatchAllocation::class);
    }
    public function medicineReturns()
    {
         return $this->hasMany(MedicineReturn::class);
    }
}
