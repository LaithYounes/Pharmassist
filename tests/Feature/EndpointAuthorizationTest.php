<?php

namespace Tests\Feature;

use App\Enums\PurchaseStatus;
use App\Models\Category;
use App\Models\Manufacturer;
use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\Pharmacist;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleRepresentative;
use App\Models\Status;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Laravel\Telescope\Telescope;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class EndpointAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function pharmacist(string $name, bool $admin = false): Pharmacist
    {
        return Pharmacist::create([
            'first_name' => $name, 'last_name' => 'Tester', 'username' => $name,
            'password' => Hash::make('password123'), 'phone' => $name.'-phone',
            'employment_date' => now(), 'salary' => 1000, 'is_admin' => $admin,
        ]);
    }

    private function medicine(): Medicine
    {
        $manufacturer = Manufacturer::create([
            'company_name' => 'Maker', 'location' => 'City', 'phone' => '111',
            'email' => 'maker@example.test', 'website' => 'example.test',
        ]);
        Category::create(['category_name' => 'Pain']);

        $medicine = Medicine::create([
            'name' => 'Medicine', 'manufacturer_id' => $manufacturer->id,
            'prescription' => 'No', 'production_Date' => '2025-01-01',
            'expiration_Date' => '2028-01-01', 'quantity_in_stock' => 10,
            'minimum_quantity' => 1, 'price' => 5, 'sci_name' => 'Medicine',
        ]);
        MedicineBatch::create([
            'medicine_id' => $medicine->id, 'batch_number' => 'TEST',
            'expiration_date' => '2028-01-01', 'available_quantity' => 10,
            'unit_purchase_cost' => 1, 'status' => 'available',
        ]);
        return $medicine;
    }

    private function sale(Pharmacist $owner, Medicine $medicine): array
    {
        $sale = Sale::create([
            'pharmacist_id' => $owner->id, 'sale_date' => now(), 'total_price' => 5,
        ]);
        $item = SaleItem::create([
            'sale_id' => $sale->id, 'medicine_id' => $medicine->id,
            'quantity' => 1, 'price' => 5,
        ]);
        $item->batchAllocations()->create([
            'batch_id' => $medicine->batches()->firstOrFail()->id, 'quantity' => 1,
        ]);

        return [$sale, $item];
    }

    private function protectedRequests(Medicine $medicine, Pharmacist $target, Sale $sale, SaleItem $item): array
    {
        return [
            ['POST', '/api/medicines', ['name' => 'Other']],
            ['PUT', '/api/medicines/'.$medicine->id, ['price' => 999]],
            ['PATCH', '/api/medicines/'.$medicine->id, ['price' => 999]],
            ['DELETE', '/api/medicines/'.$medicine->id, []],
            ['POST', '/api/RegisterPharmasict', ['username' => 'attacker']],
            ['PUT', '/api/UpdatePharmacist/'.$target->id, ['salary' => 999]],
            ['DELETE', '/api/DeletePharmacist/'.$target->id, []],
            ['POST', '/api/ImportPricedSuppOrder', []],
            ['GET', '/api/getAllPharmacists', []],
            ['GET', '/api/reports/net-sales', []],
            ['GET', '/api/reports/daily', []],
            ['GET', '/api/reports/monthly/2026/9', []],
            ['POST', '/api/SellMedicine', ['items' => [['medicine_id' => $medicine->id, 'quantity' => 1]]]],
            ['POST', '/api/SupplyRequest', []],
            ['POST', '/api/ReturnMedicine', ['sale_id' => $sale->id, 'sale_item_id' => $item->id, 'quantity_returned' => 1]],
            ['GET', '/api/GetPharmacistSales', []],
            ['GET', '/api/sales/'.$sale->id, []],
            ['GET', '/api/GetPharmacistPurchase', []],
            ['GET', '/api/PharmacistProfile', []],
            ['GET', '/api/GetAllContacts', []],
            ['GET', '/api/SalesRep', []],
        ];
    }

    private function assertNoMutation(Medicine $medicine, int $pharmacists, int $sales): void
    {
        $this->assertDatabaseCount('pharmacists', $pharmacists);
        $this->assertDatabaseCount('sales', $sales);
        $this->assertDatabaseCount('medicine_returns', 0);
        $this->assertSame(10, (int) $medicine->fresh()->quantity_in_stock);
        $this->assertEquals(5, $medicine->fresh()->price);
    }

    public function test_guest_gets_401_for_every_protected_api_operation_without_mutation(): void
    {
        $medicine = $this->medicine();
        $owner = $this->pharmacist('owner');
        [$sale, $item] = $this->sale($owner, $medicine);

        foreach ($this->protectedRequests($medicine, $owner, $sale, $item) as [$method, $path, $payload]) {
            $this->json($method, $path, $payload)->assertUnauthorized();
            $this->assertNoMutation($medicine, 1, 1);
        }

        $this->getJson('/api/medicines')->assertOk();
        $this->getJson('/api/medicines/'.$medicine->id)->assertOk();
        $this->getJson('/api/Search/Medicine')->assertOk();
        $this->getJson('/api/GetByCategoryName/All')->assertOk();
        $this->getJson('/api/GetAllCategories')->assertOk();
    }

    public function test_regular_pharmacist_gets_403_for_management_operations_without_mutation(): void
    {
        $medicine = $this->medicine();
        $regular = $this->pharmacist('regular');
        $admin = $this->pharmacist('admin', true);
        [$sale, $item] = $this->sale($admin, $medicine);
        Sanctum::actingAs($regular);

        foreach (array_slice($this->protectedRequests($medicine, $admin, $sale, $item), 0, 12) as [$method, $path, $payload]) {
            $this->json($method, $path, $payload)->assertForbidden();
            $this->assertNoMutation($medicine, 2, 1);
        }

        $this->postJson('/api/ReturnMedicine', [
            'request_id' => (string) Str::uuid(), 'sale_id' => $sale->id,
            'sale_item_id' => $item->id, 'quantity_returned' => 1,
            'reason' => 'Returned', 'condition' => 'restockable',
        ])->assertForbidden();
        $this->assertNoMutation($medicine, 2, 1);
    }

    public function test_pharmacist_can_sell_return_owned_sale_and_read_only_own_sales(): void
    {
        $medicine = $this->medicine();
        $regular = $this->pharmacist('regular');
        $other = $this->pharmacist('other');
        $this->sale($other, $medicine);
        Sanctum::actingAs($regular);

        $this->postJson('/api/SellMedicine', [
            'items' => [['medicine_id' => $medicine->id, 'quantity' => 1]],
        ])->assertOk()->assertJsonPath('status', true);
        $this->assertSame(9, (int) $medicine->fresh()->quantity_in_stock);

        $ownSale = Sale::where('pharmacist_id', $regular->id)->firstOrFail();
        $ownItem = $ownSale->salesItems()->firstOrFail();
        $this->postJson('/api/ReturnMedicine', [
            'request_id' => (string) Str::uuid(), 'sale_id' => $ownSale->id,
            'sale_item_id' => $ownItem->id, 'quantity_returned' => 1,
            'reason' => 'Returned', 'condition' => 'restockable',
        ])->assertCreated();
        $this->assertSame(10, (int) $medicine->fresh()->quantity_in_stock);
        $this->assertDatabaseCount('medicine_returns', 1);

        $this->getJson('/api/GetPharmacistSales')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/GetPharmacistPurchase')->assertOk();
        $this->getJson('/api/PharmacistProfile')->assertOk()->assertJsonPath('data.ID', $regular->id);
        $this->getJson('/api/GetAllContacts')->assertOk();
        $this->getJson('/api/SalesRep')->assertOk();
    }

    public function test_pharmacist_and_admin_can_request_supply(): void
    {
        $medicine = $this->medicine();
        $warehouse = Warehouse::create(['name' => 'Main', 'location' => 'City', 'phone' => '111']);
        $representative = SaleRepresentative::create([
            'name' => 'Supplier', 'phone' => '222', 'email' => 'supplier@example.test',
            'warehouse_id' => $warehouse->id,
        ]);
        Status::create(['name' => 'Requested']);
        Mail::fake();
        Storage::fake('public');

        $regular = $this->pharmacist('regular');
        $administrator = $this->pharmacist('admin', true);
        foreach ([$regular, $administrator] as $actor) {
            Sanctum::actingAs($actor);
            $this->postJson('/api/SupplyRequest', [
                'sale_representative_id' => $representative->id,
                'items' => [['medicine_name' => $medicine->name, 'quantity' => 1]],
            ])->assertOk();
        }

        $this->assertDatabaseCount('purchases', 2);
        $this->assertSame(10, (int) $medicine->fresh()->quantity_in_stock);
        Sanctum::actingAs($regular);
        $this->getJson('/api/GetPharmacistPurchase')->assertOk()->assertJsonCount(1, 'data');
        Sanctum::actingAs($administrator);
        $this->getJson('/api/GetPharmacistPurchase')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_admin_can_manage_medicines_accounts_and_read_management_data(): void
    {
        $medicine = $this->medicine();
        $admin = $this->pharmacist('admin', true);
        $regular = $this->pharmacist('regular');
        Sanctum::actingAs($admin);

        $this->postJson('/api/medicines', [
            'name' => 'New', 'manufacturer' => 'Maker', 'categories' => ['Pain'],
            'prescription' => 'No', 'production_Date' => '2025-01-01',
            'expiration_Date' => '2028-01-01', 'quantity_in_stock' => 0,
            'minimum_quantity' => 1, 'price' => 5, 'sci_name' => 'New',
        ])->assertOk();
        $newMedicine = Medicine::where('name', 'New')->firstOrFail();
        $this->patchJson('/api/medicines/'.$medicine->id, ['price' => 7])->assertOk();
        $this->assertEquals(7, $medicine->fresh()->price);
        $this->deleteJson('/api/medicines/'.$newMedicine->id)->assertOk();

        $this->putJson('/api/UpdatePharmacist/'.$regular->id, ['salary' => 2000])->assertOk();
        $this->assertEquals(2000, $regular->fresh()->salary);
        $this->getJson('/api/getAllPharmacists')->assertOk();
        $this->getJson('/api/reports/net-sales')->assertOk();
        $this->getJson('/api/reports/daily')->assertOk();
        $this->getJson('/api/reports/monthly/2026/9')->assertOk();
        $this->postJson('/api/ImportPricedSuppOrder', [])->assertUnprocessable();
        $this->deleteJson('/api/DeletePharmacist/'.$regular->id)->assertOk();
        $this->assertDatabaseMissing('pharmacists', ['id' => $regular->id]);
    }

    public function test_dashboard_main_and_child_require_admin_web_session(): void
    {
        foreach (['/dashboard', '/dashboard/top-manufacturers'] as $path) {
            $this->get($path)->assertRedirect('/dashboard/login');
        }

        $regular = $this->pharmacist('regular');
        $this->post('/dashboard/login', ['username' => 'regular', 'password' => 'password123'])
            ->assertRedirect('/dashboard/top-manufacturers');
        foreach (['/dashboard', '/dashboard/top-manufacturers'] as $path) {
            $this->get($path)->assertForbidden();
        }

        $this->post('/dashboard/logout')->assertRedirect('/dashboard/login');
        $this->pharmacist('admin', true);
        $this->post('/dashboard/login', ['username' => 'admin', 'password' => 'password123'])
            ->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk();
        $this->get('/dashboard/top-manufacturers')->assertOk();
    }

    public function test_admin_can_import_a_priced_order_while_regular_pharmacist_cannot(): void
    {
        $medicine = $this->medicine();
        $regular = $this->pharmacist('regular');
        $admin = $this->pharmacist('admin', true);
        $warehouse = Warehouse::create(['name' => 'Main', 'location' => 'City', 'phone' => '111']);
        $representative = SaleRepresentative::create([
            'name' => 'Supplier', 'phone' => '222', 'email' => 'supplier@example.test',
            'warehouse_id' => $warehouse->id,
        ]);
        $purchase = Purchase::create([
            'pharmacist_id' => $regular->id, 'sale_representative_id' => $representative->id,
            'warehouse_id' => $warehouse->id, 'purchase_date' => now(),
            'status_id' => Status::idFor(PurchaseStatus::Requested),
        ]);
        $item = PurchaseItem::create([
            'purchase_id' => $purchase->id, 'medicine_id' => $medicine->id,
            'quantity' => 2, 'price' => 0,
        ]);

        $sheet = new Spreadsheet();
        $sheet->getActiveSheet()->fromArray([['Purchase_id', 'Medicine_id', 'Medicine Name', 'Quantity', 'Price'], [$purchase->id, $medicine->id, $medicine->name, 2, 12]]);
        $path = tempnam(sys_get_temp_dir(), 'priced_');
        (new Xlsx($sheet))->save($path);
        Storage::fake('local');

        try {
            Sanctum::actingAs($regular);
            $this->post('/api/ImportPricedSuppOrder', [
                'file' => new UploadedFile($path, 'priced.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
            ])->assertForbidden();
            $this->assertSame(10, (int) $medicine->fresh()->quantity_in_stock);
            $this->assertEquals(0, $item->fresh()->price);

            Sanctum::actingAs($admin);
            $this->post('/api/ImportPricedSuppOrder', [
                'file' => new UploadedFile($path, 'priced.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
            ])->assertOk()->assertJsonPath('status', true);
            $this->assertSame(10, (int) $medicine->fresh()->quantity_in_stock);
            $this->assertEquals(6, $item->fresh()->price);
        } finally {
            unlink($path);
        }
    }

    public function test_admin_can_return_another_pharmacists_sale(): void
    {
        $medicine = $this->medicine();
        $regular = $this->pharmacist('regular');
        $admin = $this->pharmacist('admin', true);
        [$sale, $item] = $this->sale($regular, $medicine);
        Sanctum::actingAs($admin);

        $this->postJson('/api/ReturnMedicine', [
            'request_id' => (string) Str::uuid(), 'sale_id' => $sale->id,
            'sale_item_id' => $item->id, 'quantity_returned' => 1,
            'reason' => 'Returned', 'condition' => 'restockable',
        ])->assertCreated();
        $this->assertSame(11, (int) $medicine->fresh()->quantity_in_stock);
    }

    public function test_telescope_authorization_uses_the_same_admin_identity_when_enabled(): void
    {
        foreach ([null, $this->pharmacist('regular'), $this->pharmacist('admin', true)] as $index => $actor) {
            $request = Request::create('/telescope');
            $request->setUserResolver(fn ($guard = null) => $guard === 'web' ? $actor : null);
            $this->assertSame($index === 2, Telescope::check($request));
        }
    }
}
