<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Honest email delivery tracking: queued / sent (accepted by SMTP) /
     * logged (NOT delivered) / failed / skipped (fictional recipient).
     */
    public function up(): void
    {
        Schema::create('email_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('notification', 100);
            $table->string('recipient_email');
            $table->nullableMorphs('notifiable');
            $table->nullableMorphs('related');
            $table->string('mailer', 20);
            $table->string('status', 20)->default('queued')->index();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_deliveries');
    }
};
