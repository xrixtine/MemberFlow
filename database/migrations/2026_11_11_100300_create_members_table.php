<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('members', function (Blueprint $table) {
            $table->id();
            // Non-guessable identifier for signed public links (never the id or numbers).
            $table->char('public_id', 26)->unique();
            // Assigned right after insert from the id, e.g. MF-2026-00042.
            $table->string('membership_no', 20)->nullable()->unique();

            $table->string('first_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->string('last_name', 100);
            $table->string('email')->unique();
            $table->string('phone', 30)->nullable();
            $table->date('birthdate')->nullable();
            $table->string('address')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->string('source', 20)->default('walk_in');
            $table->timestamp('email_verified_at')->nullable()->index();
            $table->timestamp('verification_sent_at')->nullable();
            $table->timestamp('location_consent_at')->nullable();

            $table->foreignId('membership_category_id')->constrained('membership_categories')->restrictOnDelete();
            $table->foreignId('membership_level_id')->constrained('membership_levels')->restrictOnDelete();

            $table->string('status', 20)->default('pending')->index();
            $table->date('membership_start')->nullable();
            $table->date('membership_end')->nullable()->index();

            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['last_name', 'first_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('members');
    }
};
