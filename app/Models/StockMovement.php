<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    protected $guarded=['id'];
    protected $casts = [
        'quantity' => 'integer', 'quantity_delta' => 'integer',
        'quantity_before' => 'integer', 'quantity_after' => 'integer',
        'occurred_at' => 'datetime', 'unit_purchase_cost_at_movement' => 'decimal:2',
    ];

    public function batch() { return $this->belongsTo(MedicineBatch::class, 'batch_id'); }

    public function medicine() {
        return $this->belongsTo(Medicine::class);
    }

    public function movement() {
        return $this->belongsTo(Movement::class);
    }

    public function pharmacist() {
        return $this->belongsTo(Pharmacist::class);
    }

}
