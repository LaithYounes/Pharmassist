<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MedicineReturn extends Model
{
    use HasFactory;
    protected $guarded=['id'];

    protected $casts = [
        'returned_at' => 'datetime',
        'quantity_returned' => 'integer',
        'quantity_restocked' => 'integer',
    ];

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function saleItem()
    {
        return $this->belongsTo(SaleItem::class);
    }

    public function allocation()
    {
        return $this->belongsTo(SaleItemBatchAllocation::class, 'sale_item_batch_allocation_id');
    }

    public function performer()
    {
        return $this->belongsTo(Pharmacist::class, 'performed_by');
    }
}
