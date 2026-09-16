<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Medicine extends Model
{
    protected $guarded=['id'];
    protected $table='medicines';
    protected $casts = ['price' => 'decimal:2', 'quantity_in_stock' => 'integer'];


    public function manufacturer() {
        return $this->belongsTo(Manufacturer::class);
    }

    public function batches() {
        return $this->hasMany(MedicineBatch::class);
    }

    public function salesItems() {
        return $this->hasMany(SaleItem::class);
    }

    public function purchaseItems() {
        return $this->hasMany(PurchaseItem::class);
    }

    public function requestedMedicines() {
        return $this->hasMany(RequestedMedicine::class);
    }

    public function categories() {
        return $this->belongsToMany(Category::class, 'medicine_categories');
    }

    public function notifications() {
        return $this->hasMany(Notification::class);
    }

    public function stockMovements() {
        return $this->hasMany(StockMovement::class);
    }
    public function returns()
    {
        return $this->hasManyThrough(MedicineReturn::class, SaleItem::class);
    }

}
