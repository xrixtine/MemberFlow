<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_levels', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();
            $table->decimal('fee', 10, 2)->default(0);
            $table->unsignedSmallInteger('validity_months')->default(12);
            $table->text('benefits')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();
        });

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE membership_levels ADD CONSTRAINT chk_levels_fee CHECK (fee >= 0)');
            DB::statement('ALTER TABLE membership_levels ADD CONSTRAINT chk_levels_validity CHECK (validity_months >= 1)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_levels');
    }
};
