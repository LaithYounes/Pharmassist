<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // The original stock_movements table was a separate, batch-less legacy log.
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('movement_id')->nullable()->change();
            $table->foreignId('pharmacist_id')->nullable()->change();
            $table->foreignId('batch_id')->nullable()->constrained('medicine_batches')->restrictOnDelete();
            $table->string('type', 32)->nullable();
            $table->integer('quantity_delta')->nullable();
            $table->unsignedInteger('quantity_before')->nullable();
            $table->unsignedInteger('quantity_after')->nullable();
            $table->string('source_type', 64)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('reason', 2000)->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->decimal('unit_purchase_cost_at_movement', 14, 2)->nullable();
            $table->unique(['source_type', 'source_id'], 'stock_movements_source_unique');
            $table->index(['batch_id', 'id'], 'stock_movements_batch_order');
        });
        Schema::table('sale_item_batch_allocations', function (Blueprint $table) {
            $table->decimal('unit_purchase_cost_at_sale', 14, 2)->nullable();
        });
        Schema::table('medicine_batches', function (Blueprint $table) {
            $table->decimal('unit_purchase_cost', 14, 2)->nullable()->change();
        });
        Schema::table('sales', function (Blueprint $table) {
            $table->uuid('request_id')->nullable()->unique();
            $table->string('request_hash', 64)->nullable();
        });
        foreach (['medicines' => ['price'], 'sale_items' => ['price'],
            'sales' => ['total_price'], 'purchase_items' => ['price'],
            'pharmacists' => ['salary']] as $tableName => $columns) {
            Schema::table($tableName, function (Blueprint $table) use ($columns) {
                foreach ($columns as $column) {
                    $table->decimal($column, 14, 2)->change();
                }
            });
        }
        // A pre-existing batch has a known current physical balance, but no
        // reconstructible older events. Anchor it once as an opening balance.
        foreach (DB::table('medicine_batches')->where('available_quantity', '>', 0)->get() as $batch) {
            DB::table('stock_movements')->insert([
                'medicine_id' => $batch->medicine_id, 'batch_id' => $batch->id,
                'quantity' => $batch->available_quantity, 'type' => 'opening',
                'quantity_delta' => $batch->available_quantity, 'quantity_before' => 0,
                'quantity_after' => $batch->available_quantity,
                'source_type' => 'migration_batch_baseline', 'source_id' => $batch->id,
                'reason' => 'Migration baseline; earlier events are not reconstructed',
                'occurred_at' => $batch->created_at ?? now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach (DB::table('medicine_batches')->distinct()->pluck('medicine_id') as $medicineId) {
            DB::table('medicines')->where('id', $medicineId)->update([
                'quantity_in_stock' => DB::table('medicine_batches')->where('medicine_id', $medicineId)
                    ->sum('available_quantity'),
            ]);
        }
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER medicines_batch_total_guard BEFORE UPDATE OF quantity_in_stock ON medicines
                WHEN EXISTS (SELECT 1 FROM medicine_batches WHERE medicine_id = OLD.id)
                AND NEW.quantity_in_stock <> (SELECT COALESCE(SUM(available_quantity), 0)
                    FROM medicine_batches WHERE medicine_id = OLD.id)
                BEGIN SELECT RAISE(ABORT, 'catalog stock must equal batch total'); END");
            DB::unprepared("CREATE TRIGGER medicine_batches_sync_total AFTER UPDATE OF available_quantity ON medicine_batches
                WHEN NEW.available_quantity <> OLD.available_quantity BEGIN
                UPDATE medicines SET quantity_in_stock =
                    (SELECT COALESCE(SUM(available_quantity), 0) FROM medicine_batches WHERE medicine_id = NEW.medicine_id)
                    WHERE id = NEW.medicine_id; END");
            DB::unprepared("CREATE TRIGGER stock_movements_batch_valid BEFORE INSERT ON stock_movements
                WHEN NEW.batch_id IS NOT NULL AND (
                    NEW.source_type IS NULL OR NEW.source_type = '' OR NEW.source_id IS NULL
                    OR NEW.quantity <= 0 OR NEW.quantity_before < 0 OR NEW.quantity_after < 0
                    OR NEW.medicine_id <> (SELECT medicine_id FROM medicine_batches WHERE id = NEW.batch_id)
                    OR NEW.type NOT IN ('opening', 'receipt', 'sale', 'return_restock',
                        'return_unsellable', 'damage', 'adjustment_positive', 'adjustment_negative')
                    OR (NEW.quantity_delta = 0 AND NEW.type <> 'return_unsellable')
                    OR (NEW.quantity_delta <> 0 AND NEW.quantity <> ABS(NEW.quantity_delta))
                    OR (NEW.type IN ('opening', 'receipt', 'return_restock', 'adjustment_positive')
                        AND NEW.quantity_delta <= 0)
                    OR (NEW.type IN ('sale', 'damage', 'adjustment_negative')
                        AND NEW.quantity_delta >= 0)
                    OR NEW.quantity_after <> NEW.quantity_before + NEW.quantity_delta
                    OR (NEW.source_type <> 'batch_initial' AND NEW.quantity_before <>
                        (SELECT available_quantity FROM medicine_batches WHERE id = NEW.batch_id))
                    OR (NEW.source_type = 'batch_initial' AND NEW.quantity_before <> 0)
                ) BEGIN SELECT RAISE(ABORT, 'invalid batch ledger movement'); END");
            DB::unprepared("CREATE TRIGGER stock_movements_batch_apply AFTER INSERT ON stock_movements
                WHEN NEW.batch_id IS NOT NULL AND NEW.source_type <> 'batch_initial'
                    AND NEW.quantity_delta <> 0
                BEGIN UPDATE medicine_batches SET available_quantity = NEW.quantity_after,
                    updated_at = CURRENT_TIMESTAMP WHERE id = NEW.batch_id; END");
            DB::unprepared("CREATE TRIGGER medicine_batches_opening AFTER INSERT ON medicine_batches
                WHEN NEW.available_quantity > 0 BEGIN
                INSERT INTO stock_movements (medicine_id, batch_id, quantity, type, quantity_delta,
                    quantity_before, quantity_after, source_type, source_id, occurred_at,
                    unit_purchase_cost_at_movement, reason, created_at, updated_at)
                VALUES (NEW.medicine_id, NEW.id, NEW.available_quantity, 'opening', NEW.available_quantity,
                    0, NEW.available_quantity, 'batch_initial', NEW.id, CURRENT_TIMESTAMP,
                    NEW.unit_purchase_cost, 'Initial batch quantity', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP);
                UPDATE medicines SET quantity_in_stock =
                    (SELECT COALESCE(SUM(available_quantity), 0) FROM medicine_batches WHERE medicine_id = NEW.medicine_id)
                    WHERE id = NEW.medicine_id;
                END");
            DB::unprepared("CREATE TRIGGER medicine_batches_ledger_guard BEFORE UPDATE OF available_quantity ON medicine_batches
                WHEN NEW.available_quantity <> OLD.available_quantity AND NOT EXISTS (
                    SELECT 1 FROM stock_movements sm WHERE sm.batch_id = OLD.id
                    AND sm.quantity_before = OLD.available_quantity
                    AND sm.quantity_after = NEW.available_quantity
                    AND sm.id = (SELECT MAX(id) FROM stock_movements WHERE batch_id = OLD.id))
                BEGIN SELECT RAISE(ABORT, 'batch quantity requires matching movement'); END");
            DB::unprepared("CREATE TRIGGER medicine_batches_valid_insert BEFORE INSERT ON medicine_batches
                WHEN NEW.available_quantity < 0 OR NEW.unit_purchase_cost < 0
                BEGIN SELECT RAISE(ABORT, 'invalid batch quantity or cost'); END");
            DB::unprepared("CREATE TRIGGER medicine_batches_valid_update BEFORE UPDATE ON medicine_batches
                WHEN NEW.available_quantity < 0 OR NEW.unit_purchase_cost < 0
                BEGIN SELECT RAISE(ABORT, 'invalid batch quantity or cost'); END");
            DB::unprepared("CREATE TRIGGER stock_movements_no_update BEFORE UPDATE ON stock_movements
                WHEN OLD.batch_id IS NOT NULL BEGIN SELECT RAISE(ABORT, 'batch ledger is append only'); END");
            DB::unprepared("CREATE TRIGGER stock_movements_no_delete BEFORE DELETE ON stock_movements
                WHEN OLD.batch_id IS NOT NULL BEGIN SELECT RAISE(ABORT, 'batch ledger is append only'); END");
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION reject_batch_ledger_change() RETURNS trigger AS $$
                BEGIN IF OLD.batch_id IS NOT NULL THEN RAISE EXCEPTION 'batch ledger is append only'; END IF;
                RETURN OLD; END; $$ LANGUAGE plpgsql");
            DB::unprepared('CREATE TRIGGER stock_movements_no_change BEFORE UPDATE OR DELETE ON stock_movements FOR EACH ROW EXECUTE FUNCTION reject_batch_ledger_change()');
        } elseif (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER stock_movements_no_update BEFORE UPDATE ON stock_movements
                FOR EACH ROW BEGIN IF OLD.batch_id IS NOT NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'batch ledger is append only'; END IF; END");
            DB::unprepared("CREATE TRIGGER stock_movements_no_delete BEFORE DELETE ON stock_movements
                FOR EACH ROW BEGIN IF OLD.batch_id IS NOT NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'batch ledger is append only'; END IF; END");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS medicines_batch_total_guard');
            DB::unprepared('DROP TRIGGER IF EXISTS medicine_batches_sync_total');
            DB::unprepared('DROP TRIGGER IF EXISTS stock_movements_batch_apply');
            DB::unprepared('DROP TRIGGER IF EXISTS stock_movements_batch_valid');
            DB::unprepared('DROP TRIGGER IF EXISTS medicine_batches_opening');
            DB::unprepared('DROP TRIGGER IF EXISTS medicine_batches_ledger_guard');
            DB::unprepared('DROP TRIGGER IF EXISTS medicine_batches_valid_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS medicine_batches_valid_update');
            DB::unprepared('DROP TRIGGER IF EXISTS stock_movements_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS stock_movements_no_delete');
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS stock_movements_no_change ON stock_movements');
            DB::unprepared('DROP FUNCTION IF EXISTS reject_batch_ledger_change()');
        } elseif (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS stock_movements_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS stock_movements_no_delete');
        }
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropUnique('stock_movements_source_unique');
            $table->dropIndex('stock_movements_batch_order');
            $table->dropForeign(['batch_id']);
            $table->dropColumn(['batch_id', 'type', 'quantity_delta', 'quantity_before',
                'quantity_after', 'source_type', 'source_id', 'reason', 'occurred_at', 'unit_purchase_cost_at_movement']);
        });
        Schema::table('sale_item_batch_allocations', fn (Blueprint $table) => $table->dropColumn('unit_purchase_cost_at_sale'));
        Schema::table('sales', fn (Blueprint $table) => $table->dropColumn(['request_id', 'request_hash']));
    }
};
