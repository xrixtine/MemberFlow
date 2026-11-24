<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bug fix (found in Phase 7b): with explicit_defaults_for_timestamp = OFF (the
 * MariaDB 10.4 default), the FIRST non-nullable TIMESTAMP column of a table silently
 * gets "DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP". As a result:
 *
 *   - task_assignments.assigned_at was overwritten whenever the assignment was
 *     closed (unassigned_at set) — corrupting the assignment history;
 *   - user_invitations.expires_at was overwritten whenever the invitation row was
 *     updated (accepted / revoked / expired).
 *
 * This redefines both columns WITHOUT "ON UPDATE". Column definition change only:
 * no rows are removed and existing values are kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE `task_assignments` MODIFY `assigned_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
        DB::statement('ALTER TABLE `user_invitations` MODIFY `expires_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
    }

    public function down(): void
    {
        // Intentionally not restoring the buggy ON UPDATE behaviour.
    }
};
