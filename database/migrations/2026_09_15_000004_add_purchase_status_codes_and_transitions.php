<?php

use App\Enums\PurchaseStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('statuses', function (Blueprint $table) {
            $table->string('code')->nullable()->unique();
        });

        foreach (PurchaseStatus::cases() as $status) {
            DB::table('statuses')->insert([
                'name' => $status->name, 'code' => $status->value,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        Schema::create('purchase_status_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained('purchases');
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->unsignedBigInteger('actor_id');
            $table->string('actor_name');
            $table->timestamp('transitioned_at');
            $table->text('reason')->nullable();
            $table->index(['purchase_id', 'transitioned_at']);
        });

        $driver = DB::getDriverName();
        if ($driver === 'sqlite') {
            DB::statement("CREATE TRIGGER purchase_transitions_no_update BEFORE UPDATE ON purchase_status_transitions BEGIN SELECT RAISE(ABORT, 'Purchase transitions are immutable'); END");
            DB::statement("CREATE TRIGGER purchase_transitions_no_delete BEFORE DELETE ON purchase_status_transitions BEGIN SELECT RAISE(ABORT, 'Purchase transitions are immutable'); END");
        } elseif ($driver === 'mysql') {
            DB::statement("CREATE TRIGGER purchase_transitions_no_update BEFORE UPDATE ON purchase_status_transitions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase transitions are immutable'");
            DB::statement("CREATE TRIGGER purchase_transitions_no_delete BEFORE DELETE ON purchase_status_transitions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase transitions are immutable'");
        } elseif ($driver === 'pgsql') {
            DB::statement("CREATE FUNCTION prevent_purchase_transition_change() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Purchase transitions are immutable'; END; $$");
            DB::statement('CREATE TRIGGER purchase_transitions_no_update BEFORE UPDATE ON purchase_status_transitions FOR EACH ROW EXECUTE FUNCTION prevent_purchase_transition_change()');
            DB::statement('CREATE TRIGGER purchase_transitions_no_delete BEFORE DELETE ON purchase_status_transitions FOR EACH ROW EXECUTE FUNCTION prevent_purchase_transition_change()');
        }
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS purchase_transitions_no_update'.(DB::getDriverName() === 'pgsql' ? ' ON purchase_status_transitions' : ''));
        DB::statement('DROP TRIGGER IF EXISTS purchase_transitions_no_delete'.(DB::getDriverName() === 'pgsql' ? ' ON purchase_status_transitions' : ''));
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS prevent_purchase_transition_change()');
        }
        Schema::dropIfExists('purchase_status_transitions');
        Schema::table('statuses', fn (Blueprint $table) => $table->dropColumn('code'));
    }
};
