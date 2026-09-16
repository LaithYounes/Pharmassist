<?php

namespace Database\Seeders;

use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\StockMovement;
use App\Services\BatchStockLedger;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoMedicineBatchSeeder extends Seeder
{
    public function run(): void
    {
        $expiryDates = [
            now()->addMonths(3)->endOfMonth()->toDateString(),
            now()->addYears(2)->endOfMonth()->toDateString(),
        ];

        foreach (SyrianMedicineCatalog::medicines() as $entry) {
            $medicine = Medicine::where('name', $entry['name'])->firstOrFail();

            foreach ($entry['batches'] as $index => [$quantity, $cost]) {
                $number = "DEMO-{$entry['code']}-".($index === 0 ? 'A' : 'B');
                DB::transaction(function () use ($medicine, $number, $expiryDates, $index, $quantity, $cost) {
                    $batch = MedicineBatch::firstOrCreate(
                        ['medicine_id' => $medicine->id, 'batch_number' => $number],
                        [
                            'expiration_date' => $expiryDates[$index],
                            'available_quantity' => 0,
                            'unit_purchase_cost' => $cost,
                            'status' => 'available',
                        ]
                    );

                    if (StockMovement::where('source_type', 'seed_batch')
                        ->where('source_id', $batch->id)->exists()) {
                        return;
                    }
                    if ($batch->stockMovements()->where('type', 'opening')->exists()
                        || (int) $batch->available_quantity !== 0) {
                        throw new \LogicException("Batch {$number} needs reconciliation before seeding.");
                    }

                    app(BatchStockLedger::class)->record(
                        $batch, 'opening', $quantity, 'seed_batch', $batch->id,
                        null, 'Fictional demo opening quantity'
                    );
                });
            }

            // The ledger service normally keeps this total in sync. This also
            // reconciles a newly seeded medicine if the seeder was interrupted.
            $medicine->update([
                'quantity_in_stock' => $medicine->batches()->sum('available_quantity'),
            ]);
        }
    }
}
