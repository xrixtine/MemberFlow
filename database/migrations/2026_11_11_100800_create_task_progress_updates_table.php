<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Task activity log (created, assigned, status/progress changes, notes,
     * completion, reopen, archive/restore). Never cascaded away.
     */
    public function up(): void
    {
        Schema::create('task_progress_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('type', 20);
            $table->string('old_status', 20)->nullable();
            $table->string('new_status', 20)->nullable();
            $table->unsignedTinyInteger('old_progress')->nullable();
            $table->unsignedTinyInteger('new_progress')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['task_id', 'created_at']);
        });

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE task_progress_updates ADD CONSTRAINT chk_tpu_progress CHECK ((old_progress IS NULL OR old_progress <= 100) AND (new_progress IS NULL OR new_progress <= 100))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('task_progress_updates');
    }
};
