<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medicine_return_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_id')->unique();
            $table->foreignId('sale_id')->constrained('sales')->restrictOnDelete();
            $table->foreignId('pharmacist_id')->constrained('pharmacists')->restrictOnDelete();
            $table->string('payload_hash', 64);
            $table->timestamps();
        });

        Schema::table('medicine_returns', function (Blueprint $table) {
            // Historical returns have no saved allocation and cannot be backfilled safely.
            $table->foreignId('sale_item_batch_allocation_id')->nullable()
                ->constrained('sale_item_batch_allocations')->restrictOnDelete();
            $table->foreignId('return_request_id')->nullable()
                ->constrained('medicine_return_requests')->restrictOnDelete();
            $table->foreignId('performed_by')->nullable()->constrained('pharmacists')->restrictOnDelete();
            $table->string('reason', 500)->nullable();
            $table->string('condition', 20)->nullable();
            $table->unsignedInteger('quantity_restocked')->default(0);
            $table->index('sale_item_batch_allocation_id', 'medicine_returns_allocation_index');
        });
    }

    public function down(): void
    {
        Schema::table('medicine_returns', function (Blueprint $table) {
            $table->dropForeign(['sale_item_batch_allocation_id']);
            $table->dropForeign(['return_request_id']);
            $table->dropForeign(['performed_by']);
            $table->dropIndex('medicine_returns_allocation_index');
            $table->dropColumn(['sale_item_batch_allocation_id', 'return_request_id', 'performed_by', 'reason', 'condition', 'quantity_restocked']);
        });
        Schema::dropIfExists('medicine_return_requests');
    }
};
