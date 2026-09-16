<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    protected $guarded=['id'];
    protected $casts = ['total_price' => 'decimal:2'];

    public function pharmacist() {
        return $this->belongsTo(Pharmacist::class);
    }

    public function salesItems() {
        return $this->hasMany(SaleItem::class);
    }

}
