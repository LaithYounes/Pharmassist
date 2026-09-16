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
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class RejectedStockOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
    }

    private function pharmacist(): Pharmacist
    {
        $pharmacist = Pharmacist::create([
            'first_name' => 'Test', 'last_name' => 'Pharmacist',
            'username' => 'rejected-stock-test', 'password' => Hash::make('password'),
            'phone' => '123', 'employment_date' => now(), 'salary' => 100,
            'is_admin' => true,
        ]);
        Sanctum::actingAs($pharmacist);
        return $pharmacist;
    }

    private function medicine(string $name, int $stock): Medicine
    {
        $manufacturer = Manufacturer::create([
            'company_name' => 'Test Maker', 'location' => 'City', 'phone' => '111',
            'email' => 'maker@example.test', 'website' => 'example.test',
        ]);
        return Medicine::create([
            'name' => $name, 'manufacturer_id' => $manufacturer->id,
            'prescription' => 'No', 'production_Date' => '2025-01-01',
            'expiration_Date' => '2028-01-01', 'quantity_in_stock' => $stock,
            'minimum_quantity' => 1, 'price' => 10, 'sci_name' => $name,
        ]);
    }

    public function test_sale_above_available_stock_is_rejected_without_writes(): void
    {
        $this->pharmacist();
        $medicine = $this->medicine('Limited', 3);
        $before = (int) $medicine->quantity_in_stock;

        $this->postJson('/api/SellMedicine', [
            'items' => [['medicine_id' => $medicine->id, 'quantity' => 4]],
        ])->assertStatus(409)->assertJsonPath('status', false)
            ->assertJsonPath('message', 'Insufficient stock for medicine '.$medicine->id);

        $this->assertSame($before, (int) $medicine->fresh()->quantity_in_stock);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_items', 0);
    }

    public function test_duplicate_sale_lines_are_checked_as_one_total_before_any_write(): void
    {
        // The repository accepts duplicate lines but aggregates their quantities for stock validation.
        $this->pharmacist();
        $medicine = $this->medicine('Repeated', 5);
        $before = (int) $medicine->quantity_in_stock;

        $this->postJson('/api/SellMedicine', ['items' => [
            ['medicine_id' => $medicine->id, 'quantity' => 3],
            ['medicine_id' => $medicine->id, 'quantity' => 3],
        ]])->assertStatus(409)->assertJsonPath('status', false)
            ->assertJsonPath('message', 'Insufficient stock for medicine '.$medicine->id);

        $this->assertSame($before, (int) $medicine->fresh()->quantity_in_stock);
        $this->assertGreaterThanOrEqual(0, (int) $medicine->fresh()->quantity_in_stock);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_items', 0);
    }

    public function test_return_above_sold_quantity_is_rejected_with_and_without_previous_return(): void
    {
        $pharmacist = $this->pharmacist();
        $medicine = $this->medicine('Sold', 8);
        $sale = Sale::create([
            'pharmacist_id' => $pharmacist->id, 'sale_date' => now(), 'total_price' => 30,
        ]);
        $line = SaleItem::create([
            'sale_id' => $sale->id, 'medicine_id' => $medicine->id,
            'quantity' => 3, 'price' => 10,
        ]);
        $batch = MedicineBatch::create([
            'medicine_id' => $medicine->id, 'batch_number' => 'SOLD',
            'expiration_date' => '2028-01-01', 'available_quantity' => 8,
            'unit_purchase_cost' => 1, 'status' => 'available',
        ]);
        $allocation = $line->batchAllocations()->create(['batch_id' => $batch->id, 'quantity' => 3]);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('sale_items', 1);
        $before = (int) $medicine->quantity_in_stock;

        $this->postJson('/api/ReturnMedicine', [
            'request_id' => (string) Str::uuid(), 'sale_id' => $sale->id,
            'sale_item_id' => $line->id, 'quantity_returned' => 4,
            'reason' => 'Returned', 'condition' => 'restockable',
        ])->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertDatabaseCount('medicine_returns', 0);
        $this->assertSame($before, (int) $medicine->fresh()->quantity_in_stock);

        MedicineReturn::create([
            'sale_id' => $sale->id, 'sale_item_id' => $line->id,
            'sale_item_batch_allocation_id' => $allocation->id,
            'quantity_returned' => 1, 'returned_at' => now(),
        ]);
        $this->assertDatabaseCount('medicine_returns', 1);
        $this->postJson('/api/ReturnMedicine', [
            'request_id' => (string) Str::uuid(), 'sale_id' => $sale->id,
            'sale_item_id' => $line->id, 'quantity_returned' => 3,
            'reason' => 'Returned', 'condition' => 'restockable',
        ])->assertUnprocessable()->assertJsonValidationErrors('items');

        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('sale_items', 1);
        $this->assertDatabaseCount('medicine_returns', 1);
        $this->assertDatabaseHas('medicine_returns', [
            'sale_item_id' => $line->id, 'quantity_returned' => 1,
        ]);
        $this->assertSame($before, (int) $medicine->fresh()->quantity_in_stock);
    }

    public function test_invalid_priced_supply_file_leaves_order_prices_and_stock_unchanged(): void
    {
        $pharmacist = $this->pharmacist();
        $medicine = $this->medicine('Ordered', 5);
        $warehouse = Warehouse::create(['name' => 'Main', 'location' => 'City', 'phone' => '111']);
        $representative = SaleRepresentative::create([
            'name' => 'Supplier', 'phone' => '222', 'email' => 'supplier@example.test',
            'warehouse_id' => $warehouse->id,
        ]);
        $requestedId = Status::idFor(PurchaseStatus::Requested);
        $purchase = Purchase::create([
            'pharmacist_id' => $pharmacist->id,
            'sale_representative_id' => $representative->id,
            'warehouse_id' => $warehouse->id, 'purchase_date' => now(),
            'status_id' => $requestedId,
        ]);
        $item = PurchaseItem::create([
            'purchase_id' => $purchase->id, 'medicine_id' => $medicine->id,
            'quantity' => 2, 'price' => 0,
        ]);
        $beforeStock = (int) $medicine->quantity_in_stock;
        $beforeMedicinePrice = (float) $medicine->price;
        $beforeItemPrice = (float) $item->price;
        $beforeStatus = (int) $purchase->status_id;

        // Valid XLSX container, but its quantity disagrees with the requested line.
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray([
            ['Purchase_id', 'Medicine_id', 'Medicine Name', 'Quantity', 'Price'],
            [$purchase->id, $medicine->id, $medicine->name, 3, 30],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'invalid_supply_');
        try {
            (new Xlsx($spreadsheet))->save($path);
            $this->post('/api/ImportPricedSuppOrder', [
                'file' => new UploadedFile(
                    $path, 'invalid-supply.xlsx',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    null, true
                ),
            ])->assertUnprocessable()->assertJsonPath('status', false)
                ->assertJsonPath('message', 'Invalid purchase item or medicine.');
        } finally {
            $spreadsheet->disconnectWorksheets();
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->assertDatabaseCount('purchases', 1);
        $this->assertDatabaseCount('purchase_items', 1);
        $this->assertSame($beforeStatus, (int) $purchase->fresh()->status_id);
        $this->assertSame($beforeItemPrice, (float) $item->fresh()->price);
        $this->assertSame($beforeStock, (int) $medicine->fresh()->quantity_in_stock);
        $this->assertSame($beforeMedicinePrice, (float) $medicine->fresh()->price);
    }
}
