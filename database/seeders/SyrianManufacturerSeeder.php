<?php

namespace Database\Seeders;

use App\Models\Manufacturer;
use Illuminate\Database\Seeder;

class SyrianManufacturerSeeder extends Seeder
{
    public function run(): void
    {
        foreach (SyrianMedicineCatalog::manufacturers() as $company) {
            Manufacturer::firstOrCreate(
                ['company_name' => $company['company_name']],
                $company
            );
        }
    }
}
