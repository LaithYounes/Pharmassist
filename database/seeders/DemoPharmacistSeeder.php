<?php

namespace Database\Seeders;

use App\Models\Pharmacist;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoPharmacistSeeder extends Seeder
{
    public const USERNAME = 'demo_pharmacist';
    public const PASSWORD = 'DemoPharmacist2026!';

    public function run(): void
    {
        if (!app()->environment('local', 'testing')) {
            return;
        }

        Pharmacist::updateOrCreate(['username' => self::USERNAME], [
            'first_name' => 'Demo',
            'last_name' => 'Pharmacist',
            'password' => Hash::make(self::PASSWORD),
            'phone' => 'DEMO-PHONE-001',
            'employment_date' => now(),
            'salary' => '0.00',
            'is_admin' => false,
        ]);
    }
}
