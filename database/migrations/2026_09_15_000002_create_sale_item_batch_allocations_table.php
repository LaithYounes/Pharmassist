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
            DB::statement('CREATE TABLE sale_item_batch_allocations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                sale_item_id INTEGER NOT NULL REFERENCES sale_items(id) ON DELETE RESTRICT,
                batch_id INTEGER NOT NULL REFERENCES medicine_batches(id) ON DELETE RESTRICT,
                quantity INTEGER NOT NULL CHECK (quantity > 0),
                created_at DATETIME, updated_at DATETIME
            )');
            DB::statement('CREATE UNIQUE INDEX sale_item_batch_allocations_item_batch_unique ON sale_item_batch_allocations (sale_item_id, batch_id)');
            DB::statement('CREATE INDEX sale_item_batch_allocations_batch_index ON sale_item_batch_allocations (batch_id)');
            return;
        }

        Schema::create('sale_item_batch_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_item_id')->constrained('sale_items')->restrictOnDelete();
            $table->foreignId('batch_id')->constrained('medicine_batches')->restrictOnDelete();
            $table->unsignedBigInteger('quantity');
            $table->timestamps();
            $table->unique(['sale_item_id', 'batch_id']);
            $table->index('batch_id');
        });

        DB::statement('ALTER TABLE sale_item_batch_allocations ADD CONSTRAINT sale_item_batch_allocations_quantity_check CHECK (quantity > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_item_batch_allocations');
    }
};
