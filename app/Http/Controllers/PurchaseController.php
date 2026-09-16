<?php

namespace App\Http\Controllers;

use App\Enums\PurchaseStatus;
use App\Models\Purchase;
use App\Services\PurchaseLifecycle;
use App\Services\PurchaseReceiptService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PurchaseController extends Controller
{
    public function lifecycle(Request $request, Purchase $purchase)
    {
        Gate::authorize('pharmacy-work');
        Gate::authorize('view-purchase', $purchase);

        $purchase->load(['status', 'statusTransitions', 'purchaseItems.medicine', 'receipt.lines']);
        return response()->json([
            'purchase_id' => $purchase->id,
            'status' => $purchase->statusCode()?->value,
            'items' => $purchase->purchaseItems->map(function ($item) use ($purchase) {
                $received = $purchase->receipt?->lines->where('purchase_item_id', $item->id)->sum('quantity_received') ?? 0;
                return [
                    'purchase_item_id' => $item->id, 'medicine_id' => $item->medicine_id,
                    'quantity_requested' => (int) $item->quantity, 'quantity_received' => (int) $received,
                    'quantity_short' => (int) $item->quantity - (int) $received,
                    'unit_purchase_cost' => $item->price,
                    'catalog_sale_price' => $item->medicine?->price,
                ];
            }),
            'transitions' => $purchase->statusTransitions->map(fn ($transition) => [
                'from' => $transition->from_status,
                'to' => $transition->to_status,
                'actor_id' => $transition->actor_id,
                'actor_name' => $transition->actor_name,
                'transitioned_at' => $transition->transitioned_at,
                'reason' => $transition->reason,
            ]),
        ]);
    }

    public function approve(Request $request, Purchase $purchase, PurchaseLifecycle $lifecycle)
    {
        Gate::authorize('manage-pharmacy');
        $request->validate(['status' => 'prohibited', 'status_id' => 'prohibited']);
        return $this->change($purchase, PurchaseStatus::Approved, $request, $lifecycle);
    }

    public function receive(Request $request, Purchase $purchase, PurchaseReceiptService $receipts)
    {
        Gate::authorize('pharmacy-work');
        Gate::authorize('view-purchase', $purchase);
        $data = $request->validate([
            'status' => 'prohibited', 'status_id' => 'prohibited',
            'batches' => 'required|array|min:1',
            'batches.*.purchase_item_id' => 'required|integer|min:1',
            'batches.*.medicine_id' => 'sometimes|integer|min:1',
            'batches.*.batch_number' => 'required|string|max:64',
            'batches.*.expiration_date' => 'required|date_format:Y-m-d|after:today',
            'batches.*.quantity_received' => 'required|integer|min:1',
        ]);
        return response()->json($receipts->receive($purchase->id, $request->user(), $data['batches']), 201);
    }

    public function reject(Request $request, Purchase $purchase, PurchaseLifecycle $lifecycle)
    {
        Gate::authorize('manage-pharmacy');
        $request->validate([
            'reason' => 'required|string|max:2000',
            'status' => 'prohibited', 'status_id' => 'prohibited',
        ]);
        return $this->change($purchase, PurchaseStatus::Rejected, $request, $lifecycle);
    }

    public function cancel(Request $request, Purchase $purchase, PurchaseLifecycle $lifecycle)
    {
        Gate::authorize('pharmacy-work');
        $request->validate([
            'reason' => 'required|string|max:2000',
            'status' => 'prohibited', 'status_id' => 'prohibited',
        ]);
        return $this->change($purchase, PurchaseStatus::Cancelled, $request, $lifecycle);
    }

    private function change(Purchase $purchase, PurchaseStatus $to, Request $request, PurchaseLifecycle $lifecycle)
    {
        $updated = $lifecycle->transition($purchase->id, $to, $request->user(), $request->input('reason'));
        return response()->json(['purchase_id' => $updated->id, 'status' => $updated->statusCode()?->value]);
    }
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Purchase $purchase)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Purchase $purchase)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Purchase $purchase)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Purchase $purchase)
    {
        //
    }
}
