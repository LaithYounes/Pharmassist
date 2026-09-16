<?php

namespace App\Http\Controllers;

use App\Http\Requests\SellRequest;
use App\Models\SaleItem;
use App\Models\Sale;
use App\Http\Resources\GetPharmacistSales;
use App\Repositories\Interfaces\SaleItemRepositoryInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SaleItemController extends Controller
{

    protected $SaleItemsRepository;
    public function __construct(SaleItemRepositoryInterface $SaleItemsRepository)
    {
        $this->SaleItemsRepository = $SaleItemsRepository;
    }


    public function Sell(SellRequest $request)
    {
        Gate::authorize('pharmacy-work');

        $result = $this->SaleItemsRepository->Sell($request->validated());
        $code = $result['status'] ? 200 : ($result['http_status'] ?? 409);
        unset($result['http_status']);
        return response()->json($result, $code);

    }

    public function showSale(int $saleId)
    {
        Gate::authorize('pharmacy-work');
        $sale = Sale::with(['pharmacist', 'salesItems.medicine', 'salesItems.batchAllocations.batch'])
            ->findOrFail($saleId);
        if (Gate::denies('manage-pharmacy') && (int) $sale->pharmacist_id !== (int) auth()->id()) {
            abort(403);
        }
        return new GetPharmacistSales($sale);
    }


}
