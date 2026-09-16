<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseReceipt extends Model
{
    protected $guarded = ['id'];

    public function lines()
    {
        return $this->hasMany(PurchaseReceiptLine::class);
    }
}
