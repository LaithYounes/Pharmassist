<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_id')->unique();
            $table->foreignId('batch_id')->constrained('medicine_batches')->restrictOnDelete();
            $table->foreignId('pharmacist_id')->constrained('pharmacists')->restrictOnDelete();
            $table->integer('quantity_delta');
            $table->string('kind', 20);
            $table->string('reason', 2000);
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('stock_adjustments'); }
};
