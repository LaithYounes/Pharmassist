<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DemoCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SyrianManufacturerSeeder::class,
            PharmacyCategorySeeder::class,
            SyrianMedicineSeeder::class,
            DemoMedicineBatchSeeder::class,
            DemoSupplierSeeder::class,
        ]);
    }
}
