<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleItemBatchAllocation extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['quantity' => 'integer', 'unit_purchase_cost_at_sale' => 'decimal:2'];

    public function saleItem()
    {
        return $this->belongsTo(SaleItem::class);
    }

    public function batch()
    {
        return $this->belongsTo(MedicineBatch::class, 'batch_id');
    }

    public function medicineReturns()
    {
        return $this->hasMany(MedicineReturn::class, 'sale_item_batch_allocation_id');
    }
}
