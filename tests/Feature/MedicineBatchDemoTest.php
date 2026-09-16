<?php

namespace Tests\Feature;

use App\Models\Manufacturer;
use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\Pharmacist;
use App\Models\StockMovement;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoAdministratorSeeder;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\DemoPharmacistSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MedicineBatchDemoTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_reset_refuses_the_test_memory_database(): void
    {
        $this->assertSame(1, Artisan::call('demo:reset'));
        $this->assertSame(0, Medicine::count());
    }

    public function test_one_medicine_can_have_distinct_batches_and_batch_numbers_are_unique_per_medicine(): void
    {
        $first = Manufacturer::create(['company_name' => 'Test A', 'location' => 'Test', 'phone' => 'Test', 'email' => 'test@example.invalid', 'website' => 'https://example.invalid']);
        $medicine = Medicine::create(['name' => 'Test medicine', 'manufacturer_id' => $first->id, 'prescription' => 'Not required', 'production_Date' => '2026-01-01', 'expiration_Date' => '2028-01-01', 'quantity_in_stock' => 0, 'minimum_quantity' => 0, 'price' => 1, 'sci_name' => 'Test']);
        $other = Medicine::create(['name' => 'Other medicine', 'manufacturer_id' => $first->id, 'prescription' => 'Not required', 'production_Date' => '2026-01-01', 'expiration_Date' => '2028-01-01', 'quantity_in_stock' => 0, 'minimum_quantity' => 0, 'price' => 1, 'sci_name' => 'Test']);

        foreach (['B-001', 'B-002'] as $number) {
            $medicine->batches()->create(['batch_number' => $number, 'expiration_date' => '2028-01-01', 'available_quantity' => 5, 'unit_purchase_cost' => '2.50', 'status' => 'available']);
        }
        $other->batches()->create(['batch_number' => 'B-001', 'expiration_date' => '2028-01-01', 'available_quantity' => 1, 'unit_purchase_cost' => '2.50', 'status' => 'available']);

        $this->assertCount(2, $medicine->batches);
        $this->assertTrue($medicine->batches->every(fn (MedicineBatch $batch) => $batch->medicine->is($medicine)));
        $this->expectException(QueryException::class);
        $medicine->batches()->create(['batch_number' => 'B-001', 'expiration_date' => '2028-01-01', 'available_quantity' => 1, 'unit_purchase_cost' => '2.50', 'status' => 'available']);
    }

    public function test_database_rejects_negative_batch_quantity_and_cost(): void
    {
        $this->seed(DatabaseSeeder::class);
        $id = Medicine::firstOrFail()->id;
        foreach ([['available_quantity' => -1, 'unit_purchase_cost' => '1.00'], ['available_quantity' => 1, 'unit_purchase_cost' => '-1.00']] as $bad) {
            try {
                DB::table('medicine_batches')->insert($bad + ['medicine_id' => $id, 'batch_number' => uniqid('BAD-'), 'expiration_date' => '2028-01-01', 'status' => 'available']);
                $this->fail('Invalid batch was accepted');
            } catch (QueryException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }

    public function test_demo_seeders_are_repeatable_and_stock_matches_related_batches(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(3, Manufacturer::count());
        $this->assertSame(12, Medicine::count());
        $this->assertSame(24, MedicineBatch::count());
        $this->assertSame(2, Pharmacist::count());
        $admin = Pharmacist::where('username', DemoAdministratorSeeder::USERNAME)->firstOrFail();
        $regular = Pharmacist::where('username', DemoPharmacistSeeder::USERNAME)->firstOrFail();
        $this->assertTrue((bool) $admin->is_admin);
        $this->assertFalse((bool) $regular->is_admin);
        $this->assertTrue(Hash::check(DemoAdministratorSeeder::PASSWORD, $admin->password));
        $this->assertTrue(Hash::check(DemoPharmacistSeeder::PASSWORD, $regular->password));
        $this->assertSame(429, (int) MedicineBatch::sum('available_quantity'));
        $this->assertSame(429, (int) Medicine::sum('quantity_in_stock'));
        $this->assertSame(24, StockMovement::where('type', 'opening')->count());
        $this->assertSame(24, StockMovement::where('source_type', 'seed_batch')->count());
        $this->assertNotNull(Medicine::where('name', 'Unadol 500 mg film-coated tablets')->first());
        $this->assertNotNull(Medicine::where('name', 'Asiapirin 81 mg enteric-coated tablets')->first());
        $this->assertNotNull(Medicine::where('name', 'Zildensyr 90 mg tablets')->first());
        foreach (Medicine::with(['batches', 'manufacturer', 'categories'])->get() as $medicine) {
            $this->assertCount(2, $medicine->batches);
            $this->assertNotNull($medicine->manufacturer);
            $this->assertNotEmpty($medicine->categories);
            $this->assertSame((int) $medicine->quantity_in_stock, $medicine->batches->sum('available_quantity'));
        }
    }

    public function test_seeded_demo_accounts_can_log_in_with_the_postman_credentials(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = $this->postJson('/api/Login', [
            'username' => DemoAdministratorSeeder::USERNAME,
            'password' => DemoAdministratorSeeder::PASSWORD,
        ])->assertOk()->assertJsonPath('Is_admin', true);
        $this->withToken($admin->json('token'))
            ->getJson('/api/reports/net-sales')->assertOk();
    }

    public function test_seeded_regular_pharmacist_cannot_open_manager_reports(): void
    {
        $this->seed(DatabaseSeeder::class);

        $regular = $this->postJson('/api/Login', [
            'username' => DemoPharmacistSeeder::USERNAME,
            'password' => DemoPharmacistSeeder::PASSWORD,
        ])->assertOk()->assertJsonPath('Is_admin', false);
        $this->withToken($regular->json('token'))
            ->getJson('/api/reports/net-sales')->assertForbidden();
    }

    public function test_catalog_only_seeder_keeps_known_demo_accounts_out_of_the_deployment(): void
    {
        $this->seed(DemoCatalogSeeder::class);

        $this->assertSame(3, Manufacturer::count());
        $this->assertSame(12, Medicine::count());
        $this->assertSame(24, MedicineBatch::count());
        $this->assertSame(0, Pharmacist::count());
    }
}
