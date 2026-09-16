<?php

namespace App\Services;

use App\Enums\PurchaseStatus;
use App\Models\Pharmacist;
use App\Models\Purchase;
use App\Models\Status;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PurchaseLifecycle
{
    public function recordCreation(Purchase $purchase, Pharmacist $actor): void
    {
        if ($purchase->statusCode() !== PurchaseStatus::Requested) {
            throw new ConflictHttpException('New purchases must be requested.');
        }
        $this->record($purchase, null, PurchaseStatus::Requested, $actor, null);
    }

    public function transition(int $purchaseId, PurchaseStatus $to, Pharmacist $actor, ?string $reason = null): Purchase
    {
        return DB::transaction(function () use ($purchaseId, $to, $actor, $reason) {
            $purchase = Purchase::whereKey($purchaseId)->lockForUpdate()->firstOrFail();
            $this->transitionLocked($purchase, $to, $actor, $reason);
            return $purchase->fresh(['status']);
        }, 3);
    }

    // Must be called within a transaction after locking the purchase row.
    public function transitionLocked(Purchase $purchase, PurchaseStatus $to, Pharmacist $actor, ?string $reason = null): void
    {
        $from = $purchase->statusCode();
        if ($from === null || $from->isFinal() || !$this->allowed($purchase, $from, $to, $actor)) {
            throw new ConflictHttpException('Purchase transition is not allowed.');
        }
        if (in_array($to, [PurchaseStatus::Rejected, PurchaseStatus::Cancelled], true)
            && trim((string) $reason) === '') {
            throw new ConflictHttpException('A reason is required.');
        }

        $changed = Purchase::whereKey($purchase->id)
            ->where('status_id', $purchase->status_id)
            ->update(['status_id' => Status::idFor($to), 'updated_at' => now()]);
        if ($changed !== 1) {
            throw new ConflictHttpException('Purchase was changed concurrently.');
        }
        $purchase->status_id = Status::idFor($to);
        $purchase->unsetRelation('status');
        $this->record($purchase, $from, $to, $actor, $reason);
    }

    private function allowed(Purchase $purchase, PurchaseStatus $from, PurchaseStatus $to, Pharmacist $actor): bool
    {
        if ($to === PurchaseStatus::Cancelled) {
            if ((bool) $actor->is_admin) {
                return in_array($from, [PurchaseStatus::Requested, PurchaseStatus::Priced, PurchaseStatus::Approved], true);
            }
            if ((int) $purchase->pharmacist_id !== (int) $actor->id) {
                throw new AuthorizationException('Only the requesting pharmacist or a manager can cancel this purchase.');
            }
            return $from === PurchaseStatus::Requested;
        }

        if ($to === PurchaseStatus::Received) {
            return $from === PurchaseStatus::Approved
                && ((bool) $actor->is_admin || (int) $purchase->pharmacist_id === (int) $actor->id);
        }

        if (!(bool) $actor->is_admin) {
            throw new AuthorizationException('Only a manager can price or decide a purchase.');
        }

        return match ($to) {
            PurchaseStatus::Priced => $from === PurchaseStatus::Requested,
            PurchaseStatus::Approved, PurchaseStatus::Rejected => $from === PurchaseStatus::Priced,
            PurchaseStatus::Received => false,
            default => false,
        };
    }

    private function record(Purchase $purchase, ?PurchaseStatus $from, PurchaseStatus $to, Pharmacist $actor, ?string $reason): void
    {
        $purchase->statusTransitions()->create([
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'actor_id' => $actor->id,
            'actor_name' => trim($actor->first_name.' '.$actor->last_name),
            'transitioned_at' => now(),
            'reason' => $reason === null ? null : trim($reason),
        ]);
    }
}
