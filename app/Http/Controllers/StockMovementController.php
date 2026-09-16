<?php
namespace App\Http\Controllers;

use App\Models\MedicineBatch;
use App\Models\StockMovement;
use App\Services\BatchStockLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class StockMovementController extends Controller
{
    public function index(MedicineBatch $batch)
    {
        Gate::authorize('manage-pharmacy');
        $movements = $batch->stockMovements()->orderBy('id')->get();
        $balance = 0;
        $consistent = true;
        foreach ($movements as $movement) {
            if ($movement->quantity_before !== $balance) $consistent = false;
            $balance += $movement->quantity_delta;
            if ($movement->quantity_after !== $balance) $consistent = false;
        }
        return response()->json([
            'batch_id' => $batch->id, 'available_quantity' => $batch->available_quantity,
            'ledger_balance' => $balance, 'consistent' => $consistent && $balance === $batch->available_quantity,
            'movements' => $movements,
        ]);
    }

    public function adjust(Request $request, MedicineBatch $batch, BatchStockLedger $ledger)
    {
        return $this->change($request, $batch, $ledger, 'adjustment');
    }

    public function damage(Request $request, MedicineBatch $batch, BatchStockLedger $ledger)
    {
        return $this->change($request, $batch, $ledger, 'damage');
    }

    private function change(Request $request, MedicineBatch $batch, BatchStockLedger $ledger, string $kind)
    {
        Gate::authorize('manage-pharmacy');
        $data = $request->validate([
            'request_id' => 'required|uuid', 'quantity_delta' => $kind === 'damage'
                ? 'required|integer|max:-1' : 'required|integer|not_in:0',
            'reason' => 'required|string|max:2000',
        ]);
        $result = DB::transaction(function () use ($batch, $data, $request, $ledger, $kind) {
            $existing = DB::table('stock_adjustments')->where('request_id', $data['request_id'])->first();
            if ($existing) {
                if ((int) $existing->batch_id !== (int) $batch->id
                    || (int) $existing->quantity_delta !== (int) $data['quantity_delta']
                    || $existing->kind !== $kind
                    || $existing->reason !== $data['reason']
                    || (int) $existing->pharmacist_id !== (int) $request->user()->id) {
                    throw ValidationException::withMessages(['request_id' => 'Request ID was used with other adjustment details.']);
                }
                return ['replayed' => true, 'movement' => StockMovement::where('source_type', 'stock_adjustment')
                    ->where('source_id', $existing->id)->firstOrFail()];
            }
            $locked = MedicineBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            if ((int) $locked->available_quantity + (int) $data['quantity_delta'] < 0) {
                throw ValidationException::withMessages(['quantity_delta' => 'Adjustment would make batch quantity negative.']);
            }
            $id = DB::table('stock_adjustments')->insertGetId([
                'request_id' => $data['request_id'], 'batch_id' => $batch->id,
                'pharmacist_id' => $request->user()->id, 'quantity_delta' => $data['quantity_delta'],
                'kind' => $kind,
                'reason' => $data['reason'], 'created_at' => now(), 'updated_at' => now(),
            ]);
            return ['replayed' => false, 'movement' => $ledger->record($locked,
                $kind === 'damage' ? 'damage' : ($data['quantity_delta'] > 0 ? 'adjustment_positive' : 'adjustment_negative'),
                (int) $data['quantity_delta'], 'stock_adjustment', $id,
                $request->user()->id, $data['reason'])];
        }, 3);
        return response()->json($result, $result['replayed'] ? 200 : 201);
    }
}
