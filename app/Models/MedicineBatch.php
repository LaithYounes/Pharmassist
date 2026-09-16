<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MedicineBatch extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'expiration_date' => 'date',
        'available_quantity' => 'integer',
        'unit_purchase_cost' => 'decimal:2',
    ];

    public function medicine()
    {
        return $this->belongsTo(Medicine::class);
    }

    public function saleItemAllocations()
    {
        return $this->hasMany(SaleItemBatchAllocation::class, 'batch_id');
    }
    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class, 'batch_id');
    }
}
