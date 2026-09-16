<?php

namespace App\Http\Controllers;

use App\Http\Requests\MakeSupplyOrderRequest;
use App\Models\PurchaseItem;
use Illuminate\Http\Request;
use App\Repositories\PurchaseItemRepository;
use App\Repositories\Interfaces\PurchaseItemsRepositoryInterface;
use App\Models\Purchase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
class PurchaseItemController extends Controller
{
    protected $purchaseItemRepository;

    public function __construct(PurchaseItemsRepositoryInterface $PurchaseRepository)
    {
        $this->purchaseItemRepository = $PurchaseRepository;
    }
    public function index()
    {
        //
    }

    public function create()
    {
        //
    }

    public function store(Request $request)
    {
        //
    }


    public function show(PurchaseItem $purchaseItem)
    {
        //
    }

    public function edit(PurchaseItem $purchaseItem)
    {
        //
    }

    public function update(Request $request, PurchaseItem $purchaseItem)
    {
        //
    }

    public function destroy(PurchaseItem $purchaseItem)
    {
        //
    }

   public function MakeSupplyOrder(MakeSupplyOrderRequest $request)
    {
        Gate::authorize('pharmacy-work');
        $validated = $request->validated();

        $items = $validated['items'];

        $saleRepresentativeId = $validated['sale_representative_id'];

        $response = $this->purchaseItemRepository->MakeSupplyOrder([
            'items' => $items,
            'sale_representative_id' => $saleRepresentativeId,
        ]);


        return $response;
    }

    public function importPricedOrder(Request $request, ?Purchase $purchase = null)
    {
        Gate::authorize('manage-pharmacy');
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls|max:5120',
            'purchase_id' => 'sometimes|integer|min:1',
        ]);

        $result = $this->purchaseItemRepository->ImportPricedSupplyOrder(
            $request->file('file')->getRealPath(), $purchase?->id ?? ($request->integer('purchase_id') ?: null)
        );
        $code = $result['status'] ? 200 : ($result['http_status'] ?? 422);
        unset($result['http_status']);
        return response()->json($result, $code);
    }
}
