<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_transactions', function (Blueprint $table) {
            $table->id();
            // Official reference, e.g. TXN-20261111-000042 (display only).
            $table->string('reference_no', 30)->nullable()->unique();
            $table->foreignId('member_id')->constrained('members')->restrictOnDelete();
            // Level at the time of the transaction.
            $table->foreignId('membership_level_id')->constrained('membership_levels')->restrictOnDelete();
            $table->string('type', 20);

            // Historical snapshots: later fee/validity changes never rewrite history.
            $table->decimal('amount', 10, 2);
            $table->unsignedSmallInteger('validity_months');
            // Cached SUM of non-voided payments; only changed under a row lock.
            $table->decimal('amount_paid', 10, 2)->default(0);

            $table->string('status', 20)->default('pending')->index();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();

            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('cancellation_reason')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['member_id', 'status']);
            $table->index('created_at');
        });

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE membership_transactions ADD CONSTRAINT chk_txn_amount CHECK (amount >= 0)');
            DB::statement('ALTER TABLE membership_transactions ADD CONSTRAINT chk_txn_amount_paid CHECK (amount_paid >= 0 AND amount_paid <= amount)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_transactions');
    }
};
