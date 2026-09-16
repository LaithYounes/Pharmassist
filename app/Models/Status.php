<?php

namespace App\Models;

use App\Enums\PurchaseStatus;
use Illuminate\Database\Eloquent\Model;

class Status extends Model
{
    protected $guarded=['id'];

    public static function idFor(PurchaseStatus $status): int
    {
        return (int) static::where('code', $status->value)->firstOrFail()->id;
    }

}
