<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Manufacturer;
use App\Models\Medicine;
use Illuminate\Database\Seeder;

class SyrianMedicineSeeder extends Seeder
{
    public function run(): void
    {
        $productionDate = now()->subMonths(6)->toDateString();
        $catalogExpiry = now()->addYears(2)->endOfMonth()->toDateString();

        foreach (SyrianMedicineCatalog::medicines() as $entry) {
            $manufacturer = Manufacturer::where('company_name', $entry['manufacturer'])->firstOrFail();
            $medicine = Medicine::firstOrCreate(['name' => $entry['name']], [
                'manufacturer_id' => $manufacturer->id,
                'prescription' => 'Not verified for Syria',
                'production_Date' => $productionDate,
                'expiration_Date' => $catalogExpiry,
                'quantity_in_stock' => 0,
                'minimum_quantity' => $entry['minimum_quantity'],
                'price' => $entry['price'],
                'sci_name' => $entry['composition'],
            ]);

            $categoryIds = Category::whereIn('category_name', $entry['categories'])->pluck('id')->all();
            if (count($categoryIds) !== count($entry['categories'])) {
                throw new \LogicException("Missing category for {$entry['name']}.");
            }
            $medicine->categories()->syncWithoutDetaching($categoryIds);
        }
    }
}
