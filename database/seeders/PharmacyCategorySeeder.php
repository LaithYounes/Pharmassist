<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class PharmacyCategorySeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            'Analgesics & Antipyretics',
            'Anti-Infectives',
            'Antidiabetics',
            'Non-Steroidal Anti-Inflammatories',
            'Antiplatelets',
            'Cardiovascular',
            'Lipid-Lowering',
        ] as $name) {
            Category::firstOrCreate(['category_name' => $name]);
        }
    }
}
