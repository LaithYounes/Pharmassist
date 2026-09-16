<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement("CREATE TABLE medicine_batches (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                medicine_id INTEGER NOT NULL REFERENCES medicines(id) ON DELETE CASCADE,
                batch_number VARCHAR(64) NOT NULL,
                expiration_date DATE NOT NULL,
                available_quantity INTEGER NOT NULL CHECK (available_quantity >= 0),
                unit_purchase_cost DECIMAL(12,2) NOT NULL CHECK (unit_purchase_cost >= 0),
                status VARCHAR(20) NOT NULL CHECK (status IN ('available', 'quarantined', 'expired')),
                created_at DATETIME, updated_at DATETIME
            )");
            DB::statement('CREATE UNIQUE INDEX medicine_batches_medicine_batch_unique ON medicine_batches (medicine_id, batch_number)');
            DB::statement('CREATE INDEX medicine_batches_status_expiry_index ON medicine_batches (status, expiration_date)');

            return;
        }

        Schema::create('medicine_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medicine_id')->constrained('medicines')->cascadeOnDelete();
            $table->string('batch_number', 64);
            $table->date('expiration_date');
            $table->integer('available_quantity');
            $table->decimal('unit_purchase_cost', 12, 2);
            $table->string('status', 20);
            $table->timestamps();
            $table->unique(['medicine_id', 'batch_number'], 'medicine_batches_medicine_batch_unique');
            $table->index(['status', 'expiration_date'], 'medicine_batches_status_expiry_index');
        });

        DB::statement('ALTER TABLE medicine_batches ADD CONSTRAINT medicine_batches_quantity_check CHECK (available_quantity >= 0)');
        DB::statement('ALTER TABLE medicine_batches ADD CONSTRAINT medicine_batches_cost_check CHECK (unit_purchase_cost >= 0)');
        DB::statement("ALTER TABLE medicine_batches ADD CONSTRAINT medicine_batches_status_check CHECK (status IN ('available', 'quarantined', 'expired'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('medicine_batches');
    }
};
