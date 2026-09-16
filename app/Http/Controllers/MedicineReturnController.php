<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReturnMedicineRequest;
use App\Repositories\Interfaces\MedicineReturnRepositoryInterface;
use App\Models\SaleItem;
use App\Models\Medicine;
use App\Models\Sale;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\DB;

class MedicineReturnController extends Controller
{
    protected $returnRepo;

    public function __construct(MedicineReturnRepositoryInterface $returnRepo)
    {
        $this->returnRepo = $returnRepo;
    }

    public function index()
    {
        Gate::authorize('manage-pharmacy');
        return response()->json($this->returnRepo->getAll());
    }

    public function store(ReturnMedicineRequest $request)
    {
       $data = $request->validated();
        $data['request_id'] = strtolower($data['request_id']);
        Gate::authorize('process-return', Sale::findOrFail($data['sale_id']));
        try {
            $result = $this->returnRepo->store($data);
            return response()->json([
                'message' => $result['replayed'] ? 'Return request already processed' : 'Medicine returned successfully',
                'request_id' => $data['request_id'],
                'replayed' => $result['replayed'],
                'data' => $result['returns'],
            ], $result['replayed'] ? 200 : 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['message' => 'Return could not be completed'], 500);
        }
    }

    public function showBySale($saleId)
    {
        Gate::authorize('process-return', Sale::findOrFail($saleId));
        return response()->json($this->returnRepo->getBySaleId($saleId));
    }
}
