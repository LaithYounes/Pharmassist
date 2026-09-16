<?php

namespace Tests\Feature;

use App\Enums\PurchaseStatus;
use App\Models\Manufacturer;
use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\Pharmacist;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\SaleRepresentative;
use App\Models\Status;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class PurchaseLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function actor(string $name, bool $manager = false): Pharmacist
    {
        return Pharmacist::create([
            'first_name' => $name, 'last_name' => 'Tester', 'username' => $name,
            'password' => Hash::make('password123'), 'phone' => $name.'-phone',
            'employment_date' => now(), 'salary' => 1000, 'is_admin' => $manager,
        ]);
    }

    private function setupOrder(Pharmacist $owner): array
    {
        $warehouse = Warehouse::create(['name' => 'Main', 'location' => 'City', 'phone' => '111']);
        $rep = SaleRepresentative::create([
            'name' => 'Supplier', 'phone' => '222', 'email' => 'supplier@example.test',
            'warehouse_id' => $warehouse->id,
        ]);
        $maker = Manufacturer::create([
            'company_name' => 'Maker', 'location' => 'City', 'phone' => '333',
            'email' => 'maker@example.test', 'website' => 'example.test',
        ]);
        $medicine = Medicine::create([
            'name' => 'Test medicine', 'manufacturer_id' => $maker->id,
            'prescription' => 'No', 'production_Date' => '2025-01-01',
            'expiration_Date' => '2028-01-01', 'quantity_in_stock' => 0,
            'minimum_quantity' => 1, 'price' => 9, 'sci_name' => 'Test medicine',
        ]);
        $purchase = Purchase::create([
            'pharmacist_id' => $owner->id, 'sale_representative_id' => $rep->id,
            'warehouse_id' => $warehouse->id, 'purchase_date' => now(),
            'status_id' => Status::idFor(PurchaseStatus::Requested),
        ]);
        $item = PurchaseItem::create([
            'purchase_id' => $purchase->id, 'medicine_id' => $medicine->id,
            'quantity' => 2, 'price' => 0,
        ]);
        return [$purchase, $item, $medicine, $rep];
    }

    private function price(Purchase $purchase, Medicine $medicine): void
    {
        $sheet = new Spreadsheet();
        $sheet->getActiveSheet()->fromArray([
            ['Purchase_id', 'Medicine_id', 'Medicine Name', 'Quantity', 'Price'],
            [$purchase->id, $medicine->id, $medicine->name, 2, 12],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'priced_');
        (new Xlsx($sheet))->save($path);
        try {
            $this->post('/api/ImportPricedSuppOrder', [
                'file' => new UploadedFile($path, 'priced.xlsx',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
            ])->assertOk()->assertJsonPath('status', true);
        } finally {
            unlink($path);
        }
    }

    private function assertState(Purchase $purchase, PurchaseStatus $status, int $transitions): void
    {
        $this->assertSame($status, $purchase->fresh()->statusCode());
        $this->assertCount($transitions, $purchase->statusTransitions()->get());
    }

    private function uploadRows(Purchase $purchase, array $rows, ?int $target = null)
    {
        $sheet = new Spreadsheet();
        $sheet->getActiveSheet()->fromArray(array_merge([
            ['Purchase_id', 'Medicine_id', 'Medicine Name', 'Quantity', 'Price'],
        ], $rows));
        $path = tempnam(sys_get_temp_dir(), 'price_');
        (new Xlsx($sheet))->save($path);
        try {
            return $this->post('/api/purchases/'.($target ?? $purchase->id).'/price', [
                'file' => new UploadedFile($path, 'price.xlsx',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
            ]);
        } finally {
            unlink($path);
        }
    }

    public function test_price_file_rejects_foreign_missing_extra_duplicate_quantity_and_price_without_partial_effects(): void
    {
        $admin = $this->actor('admin', true);
        [$purchase, $first, $medicine] = $this->setupOrder($admin);
        $other = $medicine->replicate();
        $other->name = 'Other medicine';
        $other->save();
        $second = PurchaseItem::create([
            'purchase_id' => $purchase->id, 'medicine_id' => $other->id, 'quantity' => 3, 'price' => 0,
        ]);
        [$foreign] = $this->setupOrder($admin);
        Sanctum::actingAs($admin);
        $one = [$purchase->id, $medicine->id, $medicine->name, 2, 12];
        $two = [$purchase->id, $other->id, $other->name, 3, 15];
        $bad = [
            [[$foreign->id, $medicine->id, $medicine->name, 2, 12], $two],
            [$one],
            [$one, $two, [$purchase->id, 999999, 'Unknown', 1, 2]],
            [$one, $two, $two],
            [$one, [$purchase->id, $other->id, $other->name, 4, 15]],
            [$one, [$purchase->id, $other->id, $other->name, 3, 0]],
            [$one, [$purchase->id, $other->id, $other->name, 3, 'abc']],
            [$one, [$purchase->id, $other->id, $other->name, 3, 10]], // Cannot split into exact cents.
        ];
        foreach ($bad as $rows) {
            $this->uploadRows($purchase, $rows)->assertUnprocessable();
            $this->assertState($purchase, PurchaseStatus::Requested, 0);
            $this->assertEquals(0, $first->fresh()->price);
            $this->assertEquals(0, $second->fresh()->price);
            $this->assertDatabaseCount('medicine_batches', 0);
            $this->assertSame(0, (int) $medicine->fresh()->quantity_in_stock);
            $this->assertSame(0, (int) $other->fresh()->quantity_in_stock);
        }
        $this->uploadRows($purchase, [$one, $two])->assertOk();
        $this->assertEquals(6, $first->fresh()->price);
        $this->assertEquals(5, $second->fresh()->price);
        $this->assertState($purchase, PurchaseStatus::Priced, 1);
        $this->uploadRows($purchase, [$one, $two])->assertUnprocessable();
        $this->assertState($purchase, PurchaseStatus::Priced, 1);
        $this->assertDatabaseCount('medicine_batches', 0);
        $this->assertEquals(9, $medicine->fresh()->price);
        $this->assertSame(0, (int) $medicine->fresh()->quantity_in_stock);
    }

    public function test_receipt_rejects_unapproved_overflow_foreign_and_repeat_and_accepts_short_delivery(): void
    {
        $owner = $this->actor('owner');
        $admin = $this->actor('admin', true);
        [$purchase, $item, $medicine] = $this->setupOrder($owner);
        $url = "/api/purchases/{$purchase->id}/receive";
        $line = ['purchase_item_id' => $item->id, 'medicine_id' => $medicine->id,
            'batch_number' => 'A-1', 'expiration_date' => '2028-01-01', 'quantity_received' => 1];
        Sanctum::actingAs($owner);
        $this->postJson($url, ['batches' => [$line]])->assertStatus(409);
        $this->assertDatabaseCount('purchase_receipts', 0);
        $this->assertSame(0, (int) $medicine->fresh()->quantity_in_stock);
        Sanctum::actingAs($admin);
        $this->price($purchase, $medicine);
        $this->postJson($url, ['batches' => [$line]])->assertStatus(409);
        $this->postJson("/api/purchases/{$purchase->id}/approve")->assertOk();
        $this->uploadRows($purchase, [[$purchase->id, $medicine->id, $medicine->name, 2, 12]])->assertUnprocessable();
        $this->assertEquals(6, $item->fresh()->price);
        $this->assertSame(0, (int) $medicine->fresh()->quantity_in_stock);
        Sanctum::actingAs($owner);
        $this->postJson($url, ['batches' => [array_replace($line, ['purchase_item_id' => 999999])]])->assertUnprocessable();
        $this->postJson($url, ['batches' => [array_replace($line, ['quantity_received' => 3])]])->assertUnprocessable();
        $this->assertState($purchase, PurchaseStatus::Approved, 2);
        $this->assertDatabaseCount('medicine_batches', 0);
        $this->assertDatabaseCount('purchase_receipts', 0);
        $this->postJson($url, ['batches' => [$line]])->assertCreated()
            ->assertJsonPath('items.0.quantity_requested', 2)
            ->assertJsonPath('items.0.quantity_received', 1)
            ->assertJsonPath('items.0.quantity_short', 1);
        $this->assertState($purchase, PurchaseStatus::Received, 3);
        $this->assertEquals(6, $item->fresh()->price);
        $this->assertEquals(9, $medicine->fresh()->price);
        $this->assertSame(1, (int) $medicine->fresh()->quantity_in_stock);
        $this->assertSame(1, (int) MedicineBatch::firstOrFail()->available_quantity);
        $this->assertDatabaseHas('purchase_receipt_lines', ['quantity_received' => 1, 'quantity_before' => 0, 'quantity_after' => 1]);
        $this->getJson("/api/purchases/{$purchase->id}/lifecycle")
            ->assertJsonPath('items.0.quantity_received', 1)
            ->assertJsonPath('items.0.unit_purchase_cost', '6.00')
            ->assertJsonPath('items.0.catalog_sale_price', '9.00');
        $this->postJson($url, ['batches' => [$line]])->assertStatus(409);
        Sanctum::actingAs($admin);
        $this->uploadRows($purchase, [[$purchase->id, $medicine->id, $medicine->name, 2, 12]])->assertUnprocessable();
        $this->assertDatabaseCount('purchase_receipts', 1);
        $this->assertDatabaseCount('purchase_receipt_lines', 1);
        $this->assertSame(1, (int) $medicine->fresh()->quantity_in_stock);
    }

    public function test_receipt_can_increase_matching_existing_batch_and_rejects_conflicting_batch(): void
    {
        $admin = $this->actor('admin', true);
        [$purchase, $item, $medicine] = $this->setupOrder($admin);
        Sanctum::actingAs($admin);
        $this->price($purchase, $medicine);
        $this->postJson("/api/purchases/{$purchase->id}/approve")->assertOk();
        $batch = MedicineBatch::create([
            'medicine_id' => $medicine->id, 'batch_number' => 'KNOWN',
            'expiration_date' => '2028-01-01', 'available_quantity' => 2,
            'unit_purchase_cost' => 6, 'status' => 'available',
        ]);
        $medicine->update(['quantity_in_stock' => 2]);
        $url = "/api/purchases/{$purchase->id}/receive";
        $line = ['purchase_item_id' => $item->id, 'batch_number' => 'KNOWN',
            'expiration_date' => '2029-01-01', 'quantity_received' => 1];
        $this->postJson($url, ['batches' => [$line]])->assertUnprocessable();
        $this->assertState($purchase, PurchaseStatus::Approved, 2);
        $this->assertDatabaseCount('purchase_receipts', 0);
        $this->assertSame(2, (int) $batch->fresh()->available_quantity);
        $this->assertSame(2, (int) $medicine->fresh()->quantity_in_stock);
        $line['expiration_date'] = '2028-01-01';
        $this->postJson($url, ['batches' => [$line]])->assertCreated();
        $this->assertDatabaseCount('medicine_batches', 1);
        $this->assertSame(3, (int) $batch->fresh()->available_quantity);
        $this->assertSame(3, (int) $medicine->fresh()->quantity_in_stock);
        $this->assertDatabaseHas('purchase_receipt_lines', [
            'medicine_batch_id' => $batch->id, 'quantity_before' => 2, 'quantity_after' => 3,
        ]);
    }

    public function test_receipt_rolls_back_all_batches_and_stock_when_later_line_fails(): void
    {
        $admin = $this->actor('admin', true);
        [$purchase, $item, $medicine] = $this->setupOrder($admin);
        $other = $medicine->replicate();
        $other->name = 'Another';
        $other->save();
        $second = PurchaseItem::create(['purchase_id' => $purchase->id, 'medicine_id' => $other->id,
            'quantity' => 2, 'price' => 0]);
        Sanctum::actingAs($admin);
        $this->uploadRows($purchase, [
            [$purchase->id, $medicine->id, $medicine->name, 2, 12],
            [$purchase->id, $other->id, $other->name, 2, 8],
        ])->assertOk();
        $this->postJson("/api/purchases/{$purchase->id}/approve")->assertOk();
        $lines = [
            ['purchase_item_id' => $item->id, 'batch_number' => 'FIRST', 'expiration_date' => '2028-01-01', 'quantity_received' => 1],
            ['purchase_item_id' => $second->id, 'batch_number' => 'SECOND', 'expiration_date' => '2028-01-01', 'quantity_received' => 1],
        ];
        MedicineBatch::created(function ($batch) use ($other) {
            if ((int) $batch->medicine_id === (int) $other->id) {
                throw new \RuntimeException('injected receipt failure');
            }
        });
        $this->postJson("/api/purchases/{$purchase->id}/receive", ['batches' => $lines])->assertStatus(500);
        $this->assertState($purchase, PurchaseStatus::Approved, 2);
        $this->assertEquals(6, $item->fresh()->price);
        $this->assertEquals(4, $second->fresh()->price);
        $this->assertDatabaseCount('medicine_batches', 0);
        $this->assertDatabaseCount('purchase_receipts', 0);
        $this->assertDatabaseCount('purchase_receipt_lines', 0);
        $this->assertSame(0, (int) $medicine->fresh()->quantity_in_stock);
        $this->assertSame(0, (int) $other->fresh()->quantity_in_stock);
        MedicineBatch::flushEventListeners();
        $this->postJson("/api/purchases/{$purchase->id}/receive", ['batches' => $lines])->assertCreated();
        $this->assertState($purchase, PurchaseStatus::Received, 3);
        $this->assertDatabaseCount('purchase_receipt_lines', 2);
        $this->assertSame(1, (int) $medicine->fresh()->quantity_in_stock);
        $this->assertSame(1, (int) $other->fresh()->quantity_in_stock);
    }

    public function test_creation_pricing_approval_and_history_are_atomic_and_role_checked(): void
    {
        Mail::fake();
        $owner = $this->actor('owner');
        $manager = $this->actor('manager', true);
        [$purchase, $item, $medicine, $rep] = $this->setupOrder($owner);
        $item->delete();
        $purchase->delete();

        Sanctum::actingAs($owner);
        $this->postJson('/api/SupplyRequest', [
            'sale_representative_id' => $rep->id,
            'items' => [['medicine_name' => $medicine->name, 'quantity' => 2]],
            'status_id' => Status::idFor(PurchaseStatus::Received),
        ])->assertUnprocessable();
        $this->assertDatabaseCount('purchase_status_transitions', 0);

        $this->postJson('/api/SupplyRequest', [
            'sale_representative_id' => $rep->id,
            'items' => [['medicine_name' => $medicine->name, 'quantity' => 2]],
        ])->assertOk();
        $purchase = Purchase::latest('id')->firstOrFail();
        $this->assertState($purchase, PurchaseStatus::Requested, 1);
        $creation = $purchase->statusTransitions()->firstOrFail();
        $this->assertNull($creation->from_status);
        $this->assertSame('requested', $creation->to_status);
        $this->assertSame($owner->id, $creation->actor_id);
        $this->assertSame('owner Tester', $creation->actor_name);
        $this->assertNotNull($creation->transitioned_at);
        $owner->update(['first_name' => 'renamed']);
        $this->assertSame('owner Tester', $creation->fresh()->actor_name);
        try {
            $creation->update(['actor_name' => 'tampered']);
            $this->fail('Transition history must refuse updates.');
        } catch (\LogicException $exception) {
            $this->assertSame('owner Tester', $creation->fresh()->actor_name);
        }
        try {
            $creation->delete();
            $this->fail('Transition history must refuse deletion.');
        } catch (\LogicException $exception) {
            $this->assertDatabaseHas('purchase_status_transitions', ['id' => $creation->id]);
        }
        try {
            DB::table('purchase_status_transitions')->where('id', $creation->id)
                ->update(['actor_name' => 'tampered']);
            $this->fail('Database must refuse transition updates.');
        } catch (\Illuminate\Database\QueryException $exception) {
            $this->assertSame('owner Tester', $creation->fresh()->actor_name);
        }
        try {
            DB::table('purchase_status_transitions')->where('id', $creation->id)->delete();
            $this->fail('Database must refuse transition deletion.');
        } catch (\Illuminate\Database\QueryException $exception) {
            $this->assertDatabaseHas('purchase_status_transitions', ['id' => $creation->id]);
        }

        $this->postJson("/api/purchases/{$purchase->id}/approve")->assertForbidden();
        $this->getJson("/api/purchases/{$purchase->id}/lifecycle")
            ->assertOk()->assertJsonPath('status', 'requested');
        Sanctum::actingAs($manager);
        $this->postJson("/api/purchases/{$purchase->id}/approve")->assertStatus(409);
        $this->assertState($purchase, PurchaseStatus::Requested, 1);
        $this->price($purchase, $medicine);
        $this->assertState($purchase, PurchaseStatus::Priced, 2);
        $this->assertSame(0, (int) $medicine->fresh()->quantity_in_stock);
        $this->assertEquals(9, $medicine->fresh()->price);
        $this->assertEquals(6, $purchase->purchaseItems()->firstOrFail()->price);
        $this->postJson("/api/purchases/{$purchase->id}/approve", ['status' => 'received'])
            ->assertUnprocessable();
        $this->assertState($purchase, PurchaseStatus::Priced, 2);
        $this->postJson("/api/purchases/{$purchase->id}/approve")
            ->assertOk()->assertJsonPath('status', 'approved');
        $this->assertState($purchase, PurchaseStatus::Approved, 3);
        $this->postJson("/api/purchases/{$purchase->id}/approve")->assertStatus(409);
        $this->postJson("/api/purchases/{$purchase->id}/reject", ['reason' => 'Late'])
            ->assertStatus(409);
        $this->assertState($purchase, PurchaseStatus::Approved, 3);
        $this->getJson("/api/purchases/{$purchase->id}/lifecycle")
            ->assertOk()->assertJsonCount(3, 'transitions')
            ->assertJsonPath('transitions.2.actor_name', 'manager Tester')
            ->assertJsonPath('transitions.2.from', 'priced');
        $this->postJson("/api/purchases/{$purchase->id}/receive", ['status' => 'received'])
            ->assertUnprocessable();
    }

    public function test_rejection_and_cancellation_are_final_and_reasons_are_required(): void
    {
        $owner = $this->actor('owner');
        $manager = $this->actor('manager', true);
        [$purchase, , $medicine] = $this->setupOrder($owner);
        Sanctum::actingAs($manager);
        $this->price($purchase, $medicine);
        $this->postJson("/api/purchases/{$purchase->id}/reject", ['reason' => '  Too expensive  '])
            ->assertOk()->assertJsonPath('status', 'rejected');
        $this->assertState($purchase, PurchaseStatus::Rejected, 2);
        $this->assertSame('Too expensive', $purchase->statusTransitions()->get()->last()->reason);
        $this->postJson("/api/purchases/{$purchase->id}/reject", ['reason' => 'Again'])
            ->assertStatus(409);
        $this->postJson("/api/purchases/{$purchase->id}/cancel", ['reason' => 'Again'])
            ->assertStatus(409);
        $this->assertState($purchase, PurchaseStatus::Rejected, 2);

        [$other] = $this->setupOrder($owner);
        Sanctum::actingAs($owner);
        $this->postJson("/api/purchases/{$other->id}/cancel")->assertUnprocessable();
        $this->assertState($other, PurchaseStatus::Requested, 0);
        $this->postJson("/api/purchases/{$other->id}/cancel", ['reason' => '  No longer needed  '])
            ->assertOk()->assertJsonPath('status', 'cancelled');
        $this->assertState($other, PurchaseStatus::Cancelled, 1);
        $this->assertSame('No longer needed', $other->statusTransitions()->first()->reason);
        $this->postJson("/api/purchases/{$other->id}/cancel", ['reason' => 'Again'])
            ->assertStatus(409);
        $this->assertState($other, PurchaseStatus::Cancelled, 1);
    }

    public function test_only_owner_can_cancel_requested_order_and_manager_can_cancel_approved_order(): void
    {
        $owner = $this->actor('owner');
        $stranger = $this->actor('stranger');
        $manager = $this->actor('manager', true);
        [$purchase, , $medicine] = $this->setupOrder($owner);
        $url = "/api/purchases/{$purchase->id}";
        $this->getJson($url.'/lifecycle')->assertUnauthorized();
        $this->postJson($url.'/approve')->assertUnauthorized();
        $this->postJson($url.'/reject', ['reason' => 'No'])->assertUnauthorized();
        $this->postJson($url.'/cancel', ['reason' => 'No'])->assertUnauthorized();
        $this->assertState($purchase, PurchaseStatus::Requested, 0);

        Sanctum::actingAs($stranger);
        $this->getJson($url.'/lifecycle')->assertForbidden();
        $this->postJson($url.'/cancel', ['reason' => 'No'])->assertForbidden();
        $this->postJson($url.'/reject', ['reason' => 'No'])->assertForbidden();
        $this->assertState($purchase, PurchaseStatus::Requested, 0);

        Sanctum::actingAs($manager);
        $this->price($purchase, $medicine);
        Sanctum::actingAs($owner);
        $this->postJson($url.'/cancel', ['reason' => 'No'])->assertStatus(409);
        $this->assertState($purchase, PurchaseStatus::Priced, 1);
        Sanctum::actingAs($manager);
        $this->postJson($url.'/approve')->assertOk();
        $this->postJson($url.'/cancel', ['reason' => 'Supplier cannot deliver'])->assertOk();
        $this->assertState($purchase, PurchaseStatus::Cancelled, 3);
        $this->assertSame('approved', $purchase->statusTransitions()->get()->last()->from_status);
    }
}
