<?php
namespace Tests\Feature;

use App\Enums\PurchaseStatus;
use App\Models\Manufacturer;
use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\Pharmacist;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Sale;
use App\Models\SaleItemBatchAllocation;
use App\Models\SaleRepresentative;
use App\Models\Status;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StockLedgerMoneyTest extends TestCase
{
    use RefreshDatabase;

    private function actor(string $name, bool $admin): Pharmacist
    {
        return Pharmacist::create([
            'first_name' => $name, 'last_name' => 'Tester', 'username' => $name,
            'password' => bcrypt('password123'), 'phone' => $name,
            'employment_date' => now(), 'salary' => '1000.00', 'is_admin' => $admin,
        ]);
    }

    private function medicine(string $price = '0.30'): Medicine
    {
        $maker = Manufacturer::create([
            'company_name' => 'Maker', 'location' => 'City', 'phone' => '111',
            'email' => 'maker@example.test', 'website' => 'example.test',
        ]);
        return Medicine::create([
            'name' => 'Drug', 'manufacturer_id' => $maker->id, 'prescription' => 'No',
            'production_Date' => '2025-01-01', 'expiration_Date' => '2030-01-01',
            'quantity_in_stock' => 0, 'minimum_quantity' => 1,
            'price' => $price, 'sci_name' => 'Drug',
        ]);
    }

    private function batch(Medicine $medicine, string $number, int $quantity, ?string $cost = '0.10'): MedicineBatch
    {
        return MedicineBatch::create([
            'medicine_id' => $medicine->id, 'batch_number' => $number,
            'expiration_date' => now()->addYear()->toDateString(),
            'available_quantity' => $quantity, 'unit_purchase_cost' => $cost,
            'status' => 'available',
        ]);
    }

    private function check(MedicineBatch $batch, int $expected, array $types): void
    {
        $entries = $batch->stockMovements()->orderBy('id')->get();
        $this->assertSame($types, $entries->pluck('type')->all());
        $balance = 0;
        foreach ($entries as $entry) {
            $this->assertSame($balance, $entry->quantity_before);
            $balance += $entry->quantity_delta;
            $this->assertSame($balance, $entry->quantity_after);
            $this->assertNotEmpty($entry->source_type);
            $this->assertGreaterThan(0, $entry->source_id);
        }
        $this->assertSame($expected, $balance);
        $this->assertSame($expected, $batch->fresh()->available_quantity);
    }

    public function test_complete_batch_cycle_replays_safely_and_preserves_historical_money(): void
    {
        $seller = $this->actor('seller', false);
        $admin = $this->actor('admin', true);
        $medicine = $this->medicine();
        $first = $this->batch($medicine, 'A', 2);
        $this->check($first, 2, ['opening']);

        $warehouse = Warehouse::create(['name' => 'Main', 'location' => 'City', 'phone' => '222']);
        $rep = SaleRepresentative::create([
            'name' => 'Supplier', 'phone' => '333', 'email' => 'supplier@example.test',
            'warehouse_id' => $warehouse->id,
        ]);
        $purchase = Purchase::create([
            'pharmacist_id' => $seller->id, 'sale_representative_id' => $rep->id,
            'warehouse_id' => $warehouse->id, 'purchase_date' => now(),
            'status_id' => Status::idFor(PurchaseStatus::Approved),
        ]);
        $item = PurchaseItem::create([
            'purchase_id' => $purchase->id, 'medicine_id' => $medicine->id,
            'quantity' => 4, 'price' => '0.10',
        ]);
        Sanctum::actingAs($seller);
        $this->postJson("/api/purchases/{$purchase->id}/receive", ['batches' => [
            ['purchase_item_id' => $item->id, 'batch_number' => 'A',
                'expiration_date' => $first->expiration_date->toDateString(), 'quantity_received' => 1],
            ['purchase_item_id' => $item->id, 'batch_number' => 'B',
                'expiration_date' => now()->addYears(2)->toDateString(), 'quantity_received' => 3],
        ]])->assertCreated();
        $second = MedicineBatch::where('batch_number', 'B')->firstOrFail();
        $this->check($first, 3, ['opening', 'receipt']);
        $this->check($second, 3, ['receipt']);
        $this->assertSame('0.30', $medicine->fresh()->price);
        $this->assertSame('0.10', $item->fresh()->price);
        $this->getJson("/api/purchases/{$purchase->id}/lifecycle")->assertOk()
            ->assertJsonPath('items.0.unit_purchase_cost', '0.10')
            ->assertJsonPath('items.0.catalog_sale_price', '0.30');

        $saleRequest = ['request_id' => '11111111-1111-4111-8111-111111111111',
            'items' => [['medicine_id' => $medicine->id, 'quantity' => 5]]];
        $saleId = $this->postJson('/api/SellMedicine', $saleRequest)->assertOk()->json('sale_id');
        $this->postJson('/api/SellMedicine', $saleRequest)->assertOk()
            ->assertJsonPath('replayed', true)->assertJsonPath('sale_id', $saleId);
        $this->check($first, 0, ['opening', 'receipt', 'sale']);
        $this->check($second, 1, ['receipt', 'sale']);
        $this->assertSame('1.50', Sale::findOrFail($saleId)->total_price);
        $allocation = Sale::findOrFail($saleId)->salesItems()->firstOrFail()
            ->batchAllocations()->where('batch_id', $second->id)->firstOrFail();
        $this->assertSame('0.10', $allocation->unit_purchase_cost_at_sale);
        $return = ['request_id' => '22222222-2222-4222-8222-222222222222',
            'sale_id' => $saleId, 'sale_item_id' => $allocation->sale_item_id,
            'sale_item_batch_allocation_id' => $allocation->id,
            'quantity_returned' => 1, 'condition' => 'restockable', 'reason' => 'Customer return'];
        $this->postJson('/api/ReturnMedicine', $return)->assertCreated();
        $this->postJson('/api/ReturnMedicine', $return)->assertOk()->assertJsonPath('replayed', true);
        $this->check($second, 2, ['receipt', 'sale', 'return_restock']);
        $damagedReturn = $return;
        $damagedReturn['request_id'] = '77777777-7777-4777-8777-777777777777';
        $damagedReturn['condition'] = 'damaged';
        $damagedReturn['reason'] = 'Opened package';
        $this->postJson('/api/ReturnMedicine', $damagedReturn)->assertCreated();
        $this->check($second, 2, ['receipt', 'sale', 'return_restock', 'return_unsellable']);
        $event = $second->stockMovements()->where('type', 'return_unsellable')->firstOrFail();
        $this->assertSame(0, $event->quantity_delta);
        $this->assertSame(1, $event->quantity);

        Sanctum::actingAs($admin);
        $damage = ['request_id' => '33333333-3333-4333-8333-333333333333',
            'quantity_delta' => -1, 'reason' => 'Broken package'];
        $this->postJson("/api/batches/{$second->id}/damage", $damage)->assertCreated();
        $this->check($second, 1, ['receipt', 'sale', 'return_restock', 'return_unsellable', 'damage']);
        $positive = ['request_id' => '44444444-4444-4444-8444-444444444444',
            'quantity_delta' => 2, 'reason' => 'Counted unopened units'];
        $this->postJson("/api/batches/{$first->id}/adjustments", $positive)->assertCreated();
        $this->postJson("/api/batches/{$first->id}/adjustments", $positive)->assertOk()
            ->assertJsonPath('replayed', true);
        $this->check($first, 2, ['opening', 'receipt', 'sale', 'adjustment_positive']);
        $negative = ['request_id' => '55555555-5555-4555-8555-555555555555',
            'quantity_delta' => -1, 'reason' => 'Count discrepancy'];
        $this->postJson("/api/batches/{$second->id}/adjustments", $negative)->assertCreated();
        $this->check($second, 0, ['receipt', 'sale', 'return_restock', 'return_unsellable', 'damage', 'adjustment_negative']);
        $this->getJson("/api/batches/{$second->id}/movements")
            ->assertOk()->assertJsonPath('consistent', true)->assertJsonPath('ledger_balance', 0);
        $this->assertSame(2, $medicine->fresh()->quantity_in_stock);

        $this->getJson('/api/reports/net-sales')->assertOk()
            ->assertJsonPath('net_sales', '0.90')
            ->assertJsonPath('gross_margin_after_losses', '0.30')
            ->assertJsonPath('unsellable_return_loss_known', '0.10')
            ->assertJsonPath('count_shortage_loss_known', '0.10')
            ->assertJsonPath('disposal_loss_known', '0.10');
        $medicine->update(['price' => '99.99']);
        $second->update(['unit_purchase_cost' => '50.00']);
        $this->getJson('/api/sales/'.$saleId)->assertOk()
            ->assertJsonPath('data.total_price', '1.50')
            ->assertJsonPath('data.items.0.price', '0.30');
        $this->getJson('/api/reports/net-sales')->assertJsonPath('gross_margin_after_losses', '0.30');
    }

    public function test_unauthorized_negative_and_failed_movements_roll_back_and_unknown_cost_hides_margin(): void
    {
        $seller = $this->actor('seller', false);
        $admin = $this->actor('admin', true);
        $medicine = $this->medicine('0.10');
        $batch = $this->batch($medicine, 'A', 1, null);
        Sanctum::actingAs($seller);
        $data = ['request_id' => '66666666-6666-4666-8666-666666666666',
            'quantity_delta' => -2, 'reason' => 'Check'];
        $this->postJson("/api/batches/{$batch->id}/adjustments", $data)->assertForbidden();
        Sanctum::actingAs($admin);
        $this->patchJson("/api/medicines/{$medicine->id}", ['price' => '0.001'])
            ->assertUnprocessable();
        $this->assertSame('0.10', $medicine->fresh()->price);
        $this->postJson("/api/batches/{$batch->id}/adjustments", $data)->assertUnprocessable();
        $this->postJson("/api/batches/{$batch->id}/adjustments", array_diff_key($data, ['reason' => true]))
            ->assertUnprocessable();
        $this->assertDatabaseCount('stock_adjustments', 0);
        $this->check($batch, 1, ['opening']);
        $this->postJson('/api/SellMedicine', ['items' => [
            ['medicine_id' => $medicine->id, 'quantity' => 1],
            ['medicine_id' => $medicine->id, 'quantity' => 1],
        ]])->assertStatus(409);
        $this->check($batch, 1, ['opening']);
        Sanctum::actingAs($seller);
        $this->postJson('/api/SellMedicine', ['items' => [
            ['medicine_id' => $medicine->id, 'quantity' => 1],
        ]])->assertOk();
        Sanctum::actingAs($admin);
        $this->getJson('/api/reports/net-sales')->assertOk()
            ->assertJsonPath('net_sales', '0.10')
            ->assertJsonPath('gross_margin_after_losses', null)
            ->assertJsonPath('margin_complete', false);
        $this->assertSame(10, Money::cents('0.10'));
        $this->assertSame('0.30', Money::decimal(Money::cents('0.10') * 3));
        try {
            Money::add(PHP_INT_MAX, 1);
            $this->fail('Integer overflow must not fall back to float.');
        } catch (\InvalidArgumentException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
    }

    public function test_second_allocation_failure_rolls_back_first_movement_and_decimal_sale_totals_exactly(): void
    {
        $seller = $this->actor('seller', false);
        $medicine = $this->medicine('0.10');
        $first = $this->batch($medicine, 'A', 2, '0.01');
        $second = $this->batch($medicine, 'B', 2, '0.02');
        Sanctum::actingAs($seller);
        $allocations = 0;
        SaleItemBatchAllocation::created(function () use (&$allocations) {
            if (++$allocations === 2) throw new \RuntimeException('injected allocation failure');
        });
        $this->postJson('/api/SellMedicine', ['items' => [
            ['medicine_id' => $medicine->id, 'quantity' => 3],
        ]])->assertStatus(500);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_item_batch_allocations', 0);
        $this->check($first, 2, ['opening']);
        $this->check($second, 2, ['opening']);
        SaleItemBatchAllocation::flushEventListeners();
        $saleId = $this->postJson('/api/SellMedicine', ['items' => [
            ['medicine_id' => $medicine->id, 'quantity' => 3],
        ]])->assertOk()->json('sale_id');
        $this->assertSame('0.30', Sale::findOrFail($saleId)->total_price);
        $this->check($first, 0, ['opening', 'sale']);
        $this->check($second, 1, ['opening', 'sale']);
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('stock_movements')->where('batch_id', $first->id)->where('type', 'sale')
            ->update(['reason' => 'tamper']);
    }
}
