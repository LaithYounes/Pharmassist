<?php

namespace Database\Seeders;

use App\Models\SaleRepresentative;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;

class DemoSupplierSeeder extends Seeder
{
    public function run(): void
    {
        // Fictional contacts for exercising the supply workflow. They are not
        // employees or representatives of the real manufacturers in the catalog.
        foreach ([
            ['city' => 'Damascus', 'warehouse_phone' => 'DEMO-WH-001',
                'contact_phone' => 'DEMO-CONTACT-001', 'email' => 'supplier-damascus@example.test'],
            ['city' => 'Aleppo', 'warehouse_phone' => 'DEMO-WH-002',
                'contact_phone' => 'DEMO-CONTACT-002', 'email' => 'supplier-aleppo@example.test'],
        ] as $entry) {
            $warehouse = Warehouse::firstOrCreate(
                ['name' => "DEMO {$entry['city']} warehouse"],
                ['location' => $entry['city'], 'phone' => $entry['warehouse_phone']]
            );
            SaleRepresentative::firstOrCreate(
                ['name' => "DEMO supplier contact - {$entry['city']}"],
                [
                    'phone' => $entry['contact_phone'],
                    'email' => $entry['email'],
                    'warehouse_id' => $warehouse->id,
                ]
            );
        }
    }
}
