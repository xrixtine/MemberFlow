<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7b (additive only): columns the delivery tracker needs.
 *
 *  - dedupe_key: one notification per business event (e.g. "membership-approved:txn:42").
 *    UNIQUE, so a retried request or a double submit cannot queue the same email twice.
 *  - context: small, NON-sensitive JSON used to rebuild a notification for a manual retry
 *    (never tokens, links or credentials).
 *  - message_id: the Message-ID of a message the mail transport accepted.
 *  - superseded_by_id: set when an administrator retries a failed delivery; the old
 *    queued job (if ever re-run) is then suppressed, so a retry never double-sends.
 *  - last_attempt_at: when the queue worker last tried to send.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_deliveries', function (Blueprint $table) {
            $table->string('dedupe_key', 150)->nullable()->unique()->after('notification');
            $table->json('context')->nullable()->after('related_id');
            $table->string('message_id')->nullable()->after('error_message');
            $table->foreignId('superseded_by_id')->nullable()->after('message_id')
                ->constrained('email_deliveries')->restrictOnDelete();
            $table->timestamp('last_attempt_at')->nullable()->after('queued_at');

            $table->index(['notification', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('email_deliveries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('superseded_by_id');
            $table->dropIndex(['notification', 'status']);
            $table->dropUnique(['dedupe_key']);
            $table->dropColumn(['dedupe_key', 'context', 'message_id', 'last_attempt_at']);
        });
    }
};
