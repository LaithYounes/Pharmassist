<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class PurchaseStatusTransition extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];
    protected $casts = ['transitioned_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Purchase transitions are immutable.'));
        static::deleting(fn () => throw new LogicException('Purchase transitions are immutable.'));
    }
}
