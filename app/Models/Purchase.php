<?php

namespace App\Models;

use App\Enums\PurchaseStatus;
use Illuminate\Database\Eloquent\Model;

class Purchase extends Model
{
    protected $guarded=['id'];

    public function status() {
        return $this->belongsTo(Status::class);
    }

    public function statusCode(): ?PurchaseStatus {
        return PurchaseStatus::tryFrom($this->status?->code ?? '');
    }

    public function statusTransitions() {
        return $this->hasMany(PurchaseStatusTransition::class)->orderBy('id');
    }

    public function pharmacist() {
        return $this->belongsTo(Pharmacist::class);
    }

    public function salesRepresentative() {
        return $this->belongsTo(SaleRepresentative::class);
    }

    public function warehouse() {
        return $this->belongsTo(Warehouse::class);
    }

    public function purchaseItems() {
        return $this->hasMany(PurchaseItem::class);
    }

    public function receipt() {
        return $this->hasOne(PurchaseReceipt::class);
    }

}
