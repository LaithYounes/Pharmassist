<?php

namespace Tests\Feature;

use App\Models\Manufacturer;
use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\Pharmacist;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FefoSaleTest extends TestCase
{
    use RefreshDatabase;

    private function actor(string $username = 'seller', bool $admin = false): Pharmacist
    {
        $actor = Pharmacist::create([
            'first_name' => $username, 'last_name' => 'Tester', 'username' => $username,
            'password' => Hash::make('password123'), 'phone' => $username,
            'employment_date' => now(), 'salary' => 1000, 'is_admin' => $admin,
        ]);
        Sanctum::actingAs($actor);
        return $actor;
    }

    private function medicine(int $cachedStock = 999): Medicine
    {
        $maker = Manufacturer::create([
            'company_name' => 'Maker', 'location' => 'City', 'phone' => '111',
            'email' => 'maker@example.test', 'website' => 'example.test',
        ]);
        return Medicine::create([
            'name' => 'Drug', 'manufacturer_id' => $maker->id, 'prescription' => 'No',
            'production_Date' => '2025-01-01', 'expiration_Date' => '2030-01-01',
            'quantity_in_stock' => $cachedStock, 'minimum_quantity' => 1,
            'price' => 12.5, 'sci_name' => 'Drug',
        ]);
    }

    private function batch(Medicine $medicine, string $number, string $expiry, int $quantity, string $status = 'available'): MedicineBatch
    {
        return MedicineBatch::create([
            'medicine_id' => $medicine->id, 'batch_number' => $number,
            'expiration_date' => $expiry, 'available_quantity' => $quantity,
            'unit_purchase_cost' => 2, 'status' => $status,
        ]);
    }

    private function sell(Medicine $medicine, array $quantities, array $extra = [])
    {
        return $this->postJson('/api/SellMedicine', ['items' => array_map(
            fn ($quantity) => ['medicine_id' => $medicine->id, 'quantity' => $quantity] + $extra,
            $quantities
        )]);
    }

    public function test_fefo_tie_breaker_and_expiry_today_are_reflected_on_invoice(): void
    {
        $this->actor();
        $medicine = $this->medicine();
        $later = $this->batch($medicine, 'LATER', now()->addDay()->toDateString(), 2);
        $todayFirst = $this->batch($medicine, 'TODAY-1', now()->toDateString(), 1);
        $todaySecond = $this->batch($medicine, 'TODAY-2', now()->toDateString(), 2);
        $response = $this->sell($medicine, [2], ['batch_id' => $later->id, 'price' => 0]);
        $response->assertOk()->assertJsonPath('status', true);
        $saleId = $response->json('sale_id');
        $this->getJson('/api/sales/'.$saleId)->assertOk()
            ->assertJsonPath('data.items.0.price', '12.50')
            ->assertJsonPath('data.items.0.batches.0.batch_number', 'TODAY-1')
            ->assertJsonPath('data.items.0.batches.0.quantity', 1)
            ->assertJsonPath('data.items.0.batches.1.batch_number', 'TODAY-2');
        $this->assertSame(2, (int) $later->fresh()->available_quantity);
        $this->assertSame(0, (int) $todayFirst->fresh()->available_quantity);
        $this->assertSame(1, (int) $todaySecond->fresh()->available_quantity);
        $this->assertSame(3, (int) $medicine->fresh()->quantity_in_stock);
    }

    public function test_sale_spans_two_batches_and_preserves_allocations_and_price(): void
    {
        $this->actor();
        $medicine = $this->medicine(0);
        $first = $this->batch($medicine, 'A', now()->addDays(2)->toDateString(), 2);
        $second = $this->batch($medicine, 'B', now()->addDays(3)->toDateString(), 4);
        $this->sell($medicine, [5])->assertOk();
        $sale = Sale::firstOrFail();
        $line = $sale->salesItems()->firstOrFail();
        $this->assertEquals(62.5, $sale->total_price);
        $this->assertEquals(12.5, $line->price);
        $this->assertSame([[$first->id, 2], [$second->id, 3]], $line->batchAllocations()
            ->orderBy('id')->get()->map(fn ($a) => [$a->batch_id, $a->quantity])->all());
        $this->assertSame(0, (int) $first->fresh()->available_quantity);
        $this->assertSame(1, (int) $second->fresh()->available_quantity);
        $this->assertSame(1, (int) $medicine->fresh()->quantity_in_stock);
        try {
            $first->delete();
            $this->fail('A historically allocated batch must not be deletable.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertNotNull($first->fresh());
        }
    }

    public function test_expired_and_quarantined_batches_are_not_sellable(): void
    {
        $this->actor();
        $medicine = $this->medicine();
        $expired = $this->batch($medicine, 'OLD', now()->subDay()->toDateString(), 5);
        $quarantined = $this->batch($medicine, 'HELD', now()->addDay()->toDateString(), 5, 'quarantined');
        $valid = $this->batch($medicine, 'OK', now()->addDays(2)->toDateString(), 1);
        $this->sell($medicine, [1])->assertOk();
        $this->assertSame($valid->id, Sale::firstOrFail()->salesItems()->firstOrFail()->batchAllocations()->firstOrFail()->batch_id);
        $this->assertSame(5, (int) $expired->fresh()->available_quantity);
        $this->assertSame(5, (int) $quarantined->fresh()->available_quantity);
        $this->assertSame(10, (int) $medicine->fresh()->quantity_in_stock);
    }

    public function test_insufficient_valid_stock_rejects_everything_even_when_cached_stock_is_high(): void
    {
        $this->actor();
        $medicine = $this->medicine();
        $valid = $this->batch($medicine, 'OK', now()->addDay()->toDateString(), 1);
        $this->batch($medicine, 'OLD', now()->subDay()->toDateString(), 20);
        $this->sell($medicine, [2])->assertStatus(409);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_items', 0);
        $this->assertDatabaseCount('sale_item_batch_allocations', 0);
        $this->assertSame(1, (int) $valid->fresh()->available_quantity);
        $this->assertSame(21, (int) $medicine->fresh()->quantity_in_stock);
    }

    public function test_duplicate_lines_are_aggregated_for_availability_then_allocated_separately(): void
    {
        $this->actor();
        $medicine = $this->medicine();
        $batch = $this->batch($medicine, 'ONE', now()->addDay()->toDateString(), 3);
        $this->sell($medicine, [2, 2])->assertStatus(409);
        $this->assertDatabaseCount('sales', 0);
        $this->assertSame(3, (int) $batch->fresh()->available_quantity);
        $this->sell($medicine, [2, 1])->assertOk();
        $lines = Sale::firstOrFail()->salesItems()->orderBy('id')->get();
        $this->assertSame([2, 1], $lines->pluck('quantity')->map(fn ($q) => (int) $q)->all());
        $this->assertSame([2, 1], $lines->map(fn ($line) => $line->batchAllocations->sum('quantity'))->all());
        $this->assertSame(0, (int) $batch->fresh()->available_quantity);
        $this->assertSame(0, (int) $medicine->fresh()->quantity_in_stock);
    }

    public function test_old_invoice_price_does_not_change_with_medicine_price(): void
    {
        $this->actor();
        $medicine = $this->medicine();
        $this->batch($medicine, 'A', now()->addDay()->toDateString(), 2);
        $saleId = $this->sell($medicine, [1])->assertOk()->json('sale_id');
        $medicine->update(['price' => 99]);
        $this->getJson('/api/sales/'.$saleId)->assertJsonPath('data.items.0.price', '12.50');
        $this->assertEquals(12.5, Sale::findOrFail($saleId)->total_price);
    }

    public function test_invoice_access_is_limited_to_owner_or_admin(): void
    {
        $owner = $this->actor('owner');
        $medicine = $this->medicine();
        $this->batch($medicine, 'A', now()->addDay()->toDateString(), 1);
        $saleId = $this->sell($medicine, [1])->assertOk()->json('sale_id');
        $this->getJson('/api/sales/'.$saleId)->assertOk();
        $this->actor('other');
        $this->getJson('/api/sales/'.$saleId)->assertForbidden();
        $this->actor('admin', true);
        $this->getJson('/api/sales/'.$saleId)->assertOk();
    }

    public function test_batch_managed_stock_cannot_be_overwritten_directly(): void
    {
        $this->actor('admin', true);
        $medicine = $this->medicine(4);
        $this->batch($medicine, 'A', now()->addDay()->toDateString(), 4);
        $this->patchJson('/api/medicines/'.$medicine->id, ['quantity_in_stock' => 100])
            ->assertUnprocessable();
        $this->assertSame(4, (int) $medicine->fresh()->quantity_in_stock);
    }

    public function test_direct_batch_update_is_rejected_without_a_ledger_movement(): void
    {
        // The default :memory: SQLite suite cannot share a database between
        // processes. This asserts the conditional UPDATE used after row locks;
        // it also runs when a locking MySQL/PostgreSQL test database is configured.
        $medicine = $this->medicine(4);
        $batch = $this->batch($medicine, 'A', now()->addDay()->toDateString(), 4);
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('medicine_batches')->where('id', $batch->id)
            ->where('status', 'available')->where('available_quantity', '>=', 3)
            ->decrement('available_quantity', 3);
    }
}
