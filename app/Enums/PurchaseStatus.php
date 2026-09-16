<?php

namespace App\Enums;

enum PurchaseStatus: string
{
    case Requested = 'requested';
    case Priced = 'priced';
    case Approved = 'approved';
    case Received = 'received';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function isFinal(): bool
    {
        return in_array($this, [self::Received, self::Rejected, self::Cancelled], true);
    }
}
