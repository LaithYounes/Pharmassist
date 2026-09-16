<?php

namespace Tests\Feature;

use App\Enums\PurchaseStatus;
use App\Models\Manufacturer;
use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\MedicineReturn;
use App\Models\Pharmacist;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleRepresentative;
use App\Models\Status;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class AtomicStockOperationsTest extends TestCase
{
    use RefreshDatabase;

    private function actor(): Pharmacist
    {
        $actor = Pharmacist::create([
            'first_name' => 'Admin', 'last_name' => 'Tester', 'username' => 'stock-admin',
            'password' => Hash::make('password123'), 'phone' => '123',
            'employment_date' => now(), 'salary' => 1000, 'is_admin' => true,
        ]);
        Sanctum::actingAs($actor);
        return $actor;
    }

    private function medicines(): array
    {
        $maker = Manufacturer::create([
            'company_name' => 'Maker', 'location' => 'City', 'phone' => '111',
            'email' => 'maker@example.test', 'website' => 'example.test',
        ]);
        $make = fn ($name, $stock, $price) => Medicine::create([
            'name' => $name, 'manufacturer_id' => $maker->id, 'prescription' => 'No',
            'production_Date' => '2025-01-01', 'expiration_Date' => '2028-01-01',
            'quantity_in_stock' => $stock, 'minimum_quantity' => 1,
            'price' => $price, 'sci_name' => $name,
        ]);
        $first = $make('First', 5, 4);
        $second = $make('Second', 2, 7);
        foreach ([[$first, 5], [$second, 2]] as [$medicine, $quantity]) {
            MedicineBatch::create([
                'medicine_id' => $medicine->id, 'batch_number' => 'TEST-'.$medicine->id,
                'expiration_date' => '2028-01-01', 'available_quantity' => $quantity,
                'unit_purchase_cost' => 1, 'status' => 'available',
            ]);
        }
        return [$first, $second];
    }

    private function pricedFile(Purchase $purchase, array $lines): string
    {
        $sheet = new Spreadsheet();
        $rows = [['Purchase_id', 'Medicine_id', 'Medicine Name', 'Quantity', 'Price']];
        foreach ($lines as [$medicine, $quantity, $price]) {
            $rows[] = [$purchase->id, $medicine->id, $medicine->name, $quantity, $price];
        }
        $sheet->getActiveSheet()->fromArray($rows);
        $path = tempnam(sys_get_temp_dir(), 'priced_');
        (new Xlsx($sheet))->save($path);
        return $path;
    }

    private function import(string $path)
    {
        return $this->post('/api/ImportPricedSuppOrder', [
            'file' => new UploadedFile($path, 'priced.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
        ]);
    }

    public function test_sale_success_totals_duplicates_and_stock_match(): void
    {
        $this->actor();
        [$first, $second] = $this->medicines();
        $this->postJson('/api/SellMedicine', ['items' => [
            ['medicine_id' => $first->id, 'quantity' => 2],
            ['medicine_id' => $second->id, 'quantity' => 1],
            ['medicine_id' => $first->id, 'quantity' => 3],
        ]])->assertOk()->assertJsonPath('status', true);
        $sale = Sale::firstOrFail();
        $this->assertEquals(27, $sale->total_price);
        $this->assertDatabaseCount('sale_items', 3);
        $this->assertEquals(27, $sale->salesItems()->get()->sum(fn ($item) => $item->quantity * $item->price));
        $this->assertSame(0, (int) $first->fresh()->quantity_in_stock);
        $this->assertSame(1, (int) $second->fresh()->quantity_in_stock);
    }

    public function test_sale_rejects_aggregate_overstock_and_rolls_back_failure_after_first_line(): void
    {
        $this->actor();
        [$first, $second] = $this->medicines();
        $this->postJson('/api/SellMedicine', ['items' => [
            ['medicine_id' => $second->id, 'quantity' => 2],
            ['medicine_id' => $second->id, 'quantity' => 1],
        ]])->assertStatus(409);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_items', 0);

        $createdLines = 0;
        SaleItem::created(function () use (&$createdLines) {
            if (++$createdLines === 2) {
                throw new \RuntimeException('injected line failure');
            }
        });
        $this->postJson('/api/SellMedicine', ['items' => [
            ['medicine_id' => $first->id, 'quantity' => 1],
            ['medicine_id' => $second->id, 'quantity' => 1],
        ]])->assertStatus(500)->assertDontSee('injected line failure');
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_items', 0);
        $this->assertDatabaseCount('sale_item_batch_allocations', 0);
        $this->assertSame(5, (int) $first->fresh()->quantity_in_stock);
        $this->assertSame(2, (int) $second->fresh()->quantity_in_stock);
        $this->assertSame(5, (int) $first->batches()->firstOrFail()->available_quantity);
        $this->assertSame(2, (int) $second->batches()->firstOrFail()->available_quantity);
    }

    public function test_return_checks_previous_and_duplicate_quantities_and_rolls_back_created_lines(): void
    {
        $actor = $this->actor();
        [$first, $second] = $this->medicines();
        $sale = Sale::create(['pharmacist_id' => $actor->id, 'sale_date' => now(), 'total_price' => 15]);
        $one = SaleItem::create(['sale_id' => $sale->id, 'medicine_id' => $first->id, 'quantity' => 2, 'price' => 4]);
        $two = SaleItem::create(['sale_id' => $sale->id, 'medicine_id' => $second->id, 'quantity' => 1, 'price' => 7]);
        $oneAllocation = $one->batchAllocations()->create(['batch_id' => $first->batches()->firstOrFail()->id, 'quantity' => 2]);
        $two->batchAllocations()->create(['batch_id' => $second->batches()->firstOrFail()->id, 'quantity' => 1]);
        MedicineReturn::create([
            'sale_id' => $sale->id, 'sale_item_id' => $one->id,
            'sale_item_batch_allocation_id' => $oneAllocation->id,
            'quantity_returned' => 1, 'returned_at' => now(),
        ]);

        $returnItem = fn ($line) => [
            'sale_item_id' => $line->id, 'quantity_returned' => 1,
            'reason' => 'Returned', 'condition' => 'restockable',
        ];

        $this->postJson('/api/ReturnMedicine', ['request_id' => (string) Str::uuid(), 'sale_id' => $sale->id, 'items' => [
            $returnItem($one),
            $returnItem($one),
        ]])->assertUnprocessable();
        $this->assertDatabaseCount('medicine_returns', 1);

        MedicineReturn::created(function ($return) use ($two) {
            if ((int) $return->sale_item_id === (int) $two->id) {
                throw new \RuntimeException('injected return failure');
            }
        });
        $this->postJson('/api/ReturnMedicine', ['request_id' => (string) Str::uuid(), 'sale_id' => $sale->id, 'items' => [
            $returnItem($one),
            $returnItem($two),
        ]])->assertStatus(500)->assertDontSee('injected return failure');
        $this->assertDatabaseCount('medicine_returns', 1);
        $this->assertSame(5, (int) $first->fresh()->quantity_in_stock);
        $this->assertSame(2, (int) $second->fresh()->quantity_in_stock);
        MedicineReturn::flushEventListeners();
        $this->postJson('/api/ReturnMedicine', ['request_id' => (string) Str::uuid(), 'sale_id' => $sale->id, 'items' => [
            $returnItem($one),
            $returnItem($two),
        ]])->assertCreated();
        $this->assertDatabaseCount('medicine_returns', 3);
        $this->assertSame(6, (int) $first->fresh()->quantity_in_stock);
        $this->assertSame(3, (int) $second->fresh()->quantity_in_stock);
    }

    public function test_import_is_complete_once_and_rolls_back_on_later_item_failure(): void
    {
        $actor = $this->actor();
        [$first, $second] = $this->medicines();
        $warehouse = Warehouse::create(['name' => 'Main', 'location' => 'City', 'phone' => '111']);
        $rep = SaleRepresentative::create(['name' => 'Supplier', 'phone' => '222', 'email' => 'supplier@example.test', 'warehouse_id' => $warehouse->id]);
        $purchase = Purchase::create([
            'pharmacist_id' => $actor->id, 'sale_representative_id' => $rep->id,
            'warehouse_id' => $warehouse->id, 'purchase_date' => now(),
            'status_id' => Status::idFor(PurchaseStatus::Requested),
        ]);
        $one = PurchaseItem::create(['purchase_id' => $purchase->id, 'medicine_id' => $first->id, 'quantity' => 2, 'price' => 0]);
        $two = PurchaseItem::create(['purchase_id' => $purchase->id, 'medicine_id' => $second->id, 'quantity' => 1, 'price' => 0]);
        $invalid = $this->pricedFile($purchase, [[$first, 2, 12]]);
        $wrongQuantity = $this->pricedFile($purchase, [[$first, 3, 12], [$second, 1, 7]]);
        $valid = $this->pricedFile($purchase, [[$first, 2, 12], [$second, 1, 7]]);
        try {
            $this->import($invalid)->assertUnprocessable();
            $this->import($wrongQuantity)->assertUnprocessable();
            $this->assertSame(5, (int) $first->fresh()->quantity_in_stock);
            $this->assertEquals(0, $one->fresh()->price);
            $this->assertSame(PurchaseStatus::Requested, $purchase->fresh()->statusCode());

            PurchaseItem::saved(function ($item) use ($two) {
                if ((int) $item->id === (int) $two->id && (float) $item->price > 0) {
                    throw new \RuntimeException('injected supply failure');
                }
            });
            $this->import($valid)->assertStatus(500)->assertDontSee('injected supply failure');
            $this->assertSame(5, (int) $first->fresh()->quantity_in_stock);
            $this->assertSame(2, (int) $second->fresh()->quantity_in_stock);
            $this->assertEquals(0, $one->fresh()->price);
            $this->assertEquals(0, $two->fresh()->price);
            $this->assertSame(PurchaseStatus::Requested, $purchase->fresh()->statusCode());
            PurchaseItem::flushEventListeners();

            $this->import($valid)->assertOk()->assertJsonPath('status', true);
            $this->assertSame(5, (int) $first->fresh()->quantity_in_stock);
            $this->assertSame(2, (int) $second->fresh()->quantity_in_stock);
            $this->assertEquals(6, $one->fresh()->price);
            $this->assertEquals(7, $two->fresh()->price);
            $this->assertEquals(4, $first->fresh()->price);
            $this->assertEquals(7, $second->fresh()->price);
            $this->assertSame(PurchaseStatus::Priced, $purchase->fresh()->statusCode());
            $this->import($valid)->assertUnprocessable();
            $this->assertSame(5, (int) $first->fresh()->quantity_in_stock);
            $this->assertSame(2, (int) $second->fresh()->quantity_in_stock);
        } finally {
            unlink($invalid);
            unlink($wrongQuantity);
            unlink($valid);
        }
    }

    public function test_direct_catalog_stock_decrement_is_rejected_for_batch_managed_medicine(): void
    {
        [$first] = $this->medicines();
        $this->expectException(\Illuminate\Database\QueryException::class);
        Medicine::whereKey($first->id)->where('quantity_in_stock', '>=', 4)
            ->decrement('quantity_in_stock', 4);
    }
}
