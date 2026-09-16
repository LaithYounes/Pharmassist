<?php

namespace Tests\Feature;

use App\Models\Manufacturer;
use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\MedicineReturn;
use App\Models\Pharmacist;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BatchMedicineReturnTest extends TestCase
{
    use RefreshDatabase;

    private function setupSale(array $stocks): array
    {
        $actor = Pharmacist::create([
            'first_name' => 'Return', 'last_name' => 'Tester', 'username' => 'return-tester',
            'password' => Hash::make('password'), 'phone' => '123',
            'employment_date' => now(), 'salary' => 100, 'is_admin' => false,
        ]);
        Sanctum::actingAs($actor);
        $maker = Manufacturer::create([
            'company_name' => 'Maker', 'location' => 'City', 'phone' => '111',
            'email' => 'maker@example.test', 'website' => 'example.test',
        ]);
        $medicine = Medicine::create([
            'name' => 'Drug', 'manufacturer_id' => $maker->id, 'prescription' => 'No',
            'production_Date' => '2025-01-01', 'expiration_Date' => '2030-01-01',
            'quantity_in_stock' => array_sum($stocks), 'minimum_quantity' => 1,
            'price' => 10, 'sci_name' => 'Drug',
        ]);
        $batches = [];
        foreach ($stocks as $index => $stock) {
            $batches[] = MedicineBatch::create([
                'medicine_id' => $medicine->id, 'batch_number' => 'B'.($index + 1),
                'expiration_date' => now()->addDays($index + 2)->toDateString(),
                'available_quantity' => $stock, 'unit_purchase_cost' => 1, 'status' => 'available',
            ]);
        }
        $quantity = array_sum($stocks) - 1;
        $this->postJson('/api/SellMedicine', ['items' => [
            ['medicine_id' => $medicine->id, 'quantity' => $quantity],
        ]])->assertOk();
        $sale = Sale::firstOrFail();
        $line = $sale->salesItems()->firstOrFail();
        $allocations = $line->batchAllocations()->orderBy('id')->get();
        return [$actor, $medicine, $batches, $sale, $line, $allocations];
    }

    private function item($line, int $quantity, string $condition = 'restockable', ?int $allocationId = null): array
    {
        $item = [
            'sale_item_id' => $line->id, 'quantity_returned' => $quantity,
            'reason' => 'Customer returned package', 'condition' => $condition,
        ];
        if ($allocationId !== null) {
            $item['sale_item_batch_allocation_id'] = $allocationId;
        }
        return $item;
    }

    private function request($sale, array $items, ?string $id = null): array
    {
        return ['request_id' => $id ?? (string) Str::uuid(), 'sale_id' => $sale->id, 'items' => $items];
    }

    public function test_single_batch_old_item_shape_restock_and_replay(): void
    {
        [$actor, $medicine, $batches, $sale, $line, $allocations] = $this->setupSale([4]);
        $payload = ['request_id' => (string) Str::uuid(), 'sale_id' => $sale->id]
            + $this->item($line, 2);
        $this->postJson('/api/ReturnMedicine', $payload)->assertCreated()
            ->assertJsonPath('replayed', false)
            ->assertJsonPath('data.0.quantity_restocked', 2)
            ->assertJsonPath('data.0.allocation.batch.batch_number', 'B1');
        $this->assertDatabaseHas('medicine_returns', [
            'sale_id' => $sale->id, 'sale_item_id' => $line->id,
            'sale_item_batch_allocation_id' => $allocations[0]->id,
            'quantity_returned' => 2, 'quantity_restocked' => 2,
            'reason' => 'Customer returned package',
            'condition' => 'restockable', 'performed_by' => $actor->id,
        ]);
        $this->assertSame(3, (int) $batches[0]->fresh()->available_quantity);
        $this->assertSame(3, (int) $medicine->fresh()->quantity_in_stock);
        $this->postJson('/api/ReturnMedicine', $payload)->assertOk()
            ->assertJsonPath('replayed', true)
            ->assertJsonPath('data.0.quantity_restocked', 2);
        $upperCaseRetry = $payload;
        $upperCaseRetry['request_id'] = strtoupper($payload['request_id']);
        $this->postJson('/api/ReturnMedicine', $upperCaseRetry)->assertOk()
            ->assertJsonPath('replayed', true);
        $this->assertDatabaseCount('medicine_returns', 1);
        $this->assertSame(3, (int) $batches[0]->fresh()->available_quantity);
        $changed = $payload;
        $changed['quantity_returned'] = 1;
        $this->postJson('/api/ReturnMedicine', $changed)->assertUnprocessable()
            ->assertJsonValidationErrors('request_id');
        $this->assertSame(3, (int) $medicine->fresh()->quantity_in_stock);
    }

    public function test_two_batches_require_explicit_allocation_and_restock_only_original_batch(): void
    {
        [, $medicine, $batches, $sale, $line, $allocations] = $this->setupSale([2, 4]);
        $this->getJson('/api/sales/'.$sale->id)->assertOk()
            ->assertJsonPath('data.items.0.batches.0.sale_item_batch_allocation_id', $allocations[0]->id);
        $this->postJson('/api/ReturnMedicine', $this->request($sale, [$this->item($line, 1)]))
            ->assertUnprocessable()->assertJsonValidationErrors('items.0.sale_item_batch_allocation_id');
        $this->assertDatabaseCount('medicine_returns', 0);
        $this->assertSame(0, (int) $batches[0]->fresh()->available_quantity);
        $this->assertSame(1, (int) $batches[1]->fresh()->available_quantity);

        $this->postJson('/api/ReturnMedicine', $this->request($sale, [
            $this->item($line, 1, 'restockable', $allocations[0]->id),
            $this->item($line, 2, 'restockable', $allocations[1]->id),
        ]))->assertCreated();
        $this->assertDatabaseCount('medicine_returns', 2);
        $this->assertSame(1, (int) $batches[0]->fresh()->available_quantity);
        $this->assertSame(3, (int) $batches[1]->fresh()->available_quantity);
        $this->assertSame(4, (int) $medicine->fresh()->quantity_in_stock);
        $this->getJson('/api/sales/'.$sale->id.'/returns')->assertOk()
            ->assertJsonPath('0.allocation.batch.batch_number', 'B1')
            ->assertJsonPath('1.quantity_restocked', 2);
    }

    public function test_previous_returns_and_duplicate_allocation_lines_are_counted_together(): void
    {
        [, $medicine, $batches, $sale, $line, $allocations] = $this->setupSale([3]);
        $id = $allocations[0]->id;
        $this->postJson('/api/ReturnMedicine', $this->request($sale, [
            $this->item($line, 1, 'restockable', $id),
        ]))->assertCreated();
        $this->postJson('/api/ReturnMedicine', $this->request($sale, [
            $this->item($line, 1, 'restockable', $id),
        ]))->assertCreated();
        $this->assertDatabaseCount('medicine_returns', 2);
        $this->assertSame(3, (int) $batches[0]->fresh()->available_quantity);
        $this->postJson('/api/ReturnMedicine', $this->request($sale, [
            $this->item($line, 1, 'restockable', $id),
        ]))->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->postJson('/api/ReturnMedicine', $this->request($sale, [
            $this->item($line, 1, 'restockable', $id),
            $this->item($line, 1, 'restockable', $id),
        ]))->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertDatabaseCount('medicine_returns', 2);
        $this->assertSame(3, (int) $medicine->fresh()->quantity_in_stock);
    }

    public function test_invalid_invoice_allocation_and_overreturn_leave_all_stock_unchanged(): void
    {
        [, $medicine, $batches, $sale, $line, $allocations] = $this->setupSale([2, 4]);
        $other = Sale::create(['pharmacist_id' => $sale->pharmacist_id, 'sale_date' => now(), 'total_price' => 10]);
        $otherLine = $other->salesItems()->create(['medicine_id' => $medicine->id, 'quantity' => 1, 'price' => 10]);
        $otherAllocation = $otherLine->batchAllocations()->create(['batch_id' => $batches[1]->id, 'quantity' => 1]);
        $this->postJson('/api/ReturnMedicine', $this->request($sale, [
            $this->item($line, 1, 'restockable', $otherAllocation->id),
        ]))->assertUnprocessable();
        $this->postJson('/api/ReturnMedicine', $this->request($sale, [
            $this->item($line, 3, 'restockable', $allocations[0]->id),
        ]))->assertUnprocessable();
        $this->assertDatabaseCount('medicine_returns', 0);
        $this->assertSame(0, (int) $batches[0]->fresh()->available_quantity);
        $this->assertSame(1, (int) $batches[1]->fresh()->available_quantity);
        $this->assertSame(1, (int) $medicine->fresh()->quantity_in_stock);
    }

    public function test_damaged_expired_and_unsellable_original_batches_are_recorded_without_stock(): void
    {
        [, $medicine, $batches, $sale, $line, $allocations] = $this->setupSale([4]);
        $id = $allocations[0]->id;
        $this->postJson('/api/ReturnMedicine', $this->request($sale, [
            $this->item($line, 1, 'damaged', $id),
            $this->item($line, 1, 'expired', $id),
        ]))->assertCreated()->assertJsonPath('data.0.quantity_restocked', 0)
            ->assertJsonPath('data.1.quantity_restocked', 0);
        $batches[0]->update(['status' => 'quarantined']);
        $this->postJson('/api/ReturnMedicine', $this->request($sale, [
            $this->item($line, 1, 'restockable', $id),
        ]))->assertCreated()->assertJsonPath('data.0.quantity_restocked', 0);
        $this->assertDatabaseCount('medicine_returns', 3);
        $this->assertSame(1, (int) $batches[0]->fresh()->available_quantity);
        $this->assertSame(1, (int) $medicine->fresh()->quantity_in_stock);
        $this->assertSame(0, (int) MedicineReturn::sum('quantity_restocked'));
    }

    public function test_expired_original_batch_does_not_restock_and_other_pharmacist_cannot_read_returns(): void
    {
        [, $medicine, $batches, $sale, $line, $allocations] = $this->setupSale([3]);
        $batches[0]->update(['expiration_date' => now()->subDay()->toDateString()]);
        $this->postJson('/api/ReturnMedicine', $this->request($sale, [
            $this->item($line, 1, 'restockable', $allocations[0]->id),
        ]))->assertCreated()->assertJsonPath('data.0.quantity_restocked', 0);
        $this->assertSame(1, (int) $batches[0]->fresh()->available_quantity);
        $this->assertSame(1, (int) $medicine->fresh()->quantity_in_stock);
        $other = Pharmacist::create([
            'first_name' => 'Other', 'last_name' => 'Tester', 'username' => 'other-return-reader',
            'password' => Hash::make('password'), 'phone' => '456',
            'employment_date' => now(), 'salary' => 100, 'is_admin' => false,
        ]);
        Sanctum::actingAs($other);
        $this->getJson('/api/sales/'.$sale->id.'/returns')->assertForbidden();
    }

    public function test_failure_on_later_item_rolls_back_returns_request_and_every_stock_change(): void
    {
        [, $medicine, $batches, $sale, $line, $allocations] = $this->setupSale([2, 4]);
        MedicineReturn::created(function ($return) use ($allocations) {
            if ((int) $return->sale_item_batch_allocation_id === (int) $allocations[1]->id) {
                throw new \RuntimeException('injected failure');
            }
        });
        $payload = $this->request($sale, [
            $this->item($line, 1, 'restockable', $allocations[0]->id),
            $this->item($line, 1, 'restockable', $allocations[1]->id),
        ]);
        try {
            $this->postJson('/api/ReturnMedicine', $payload)->assertStatus(500);
            $this->assertDatabaseCount('medicine_returns', 0);
            $this->assertDatabaseCount('medicine_return_requests', 0);
            $this->assertSame(0, (int) $batches[0]->fresh()->available_quantity);
            $this->assertSame(1, (int) $batches[1]->fresh()->available_quantity);
            $this->assertSame(1, (int) $medicine->fresh()->quantity_in_stock);
        } finally {
            MedicineReturn::flushEventListeners();
        }
        $this->postJson('/api/ReturnMedicine', $payload)->assertCreated();
        $this->assertDatabaseCount('medicine_return_requests', 1);
        $this->assertDatabaseCount('medicine_returns', 2);
        $this->assertSame(1, (int) $batches[0]->fresh()->available_quantity);
        $this->assertSame(2, (int) $batches[1]->fresh()->available_quantity);
        $this->assertSame(3, (int) $medicine->fresh()->quantity_in_stock);
    }
}
