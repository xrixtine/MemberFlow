<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Revision R3 — member account activation.
 *
 * An online applicant receives their member-account invitation automatically,
 * right after verifying their email. No administrator issued it, so
 * user_invitations.invited_by must allow NULL ("issued by MemberFlow") instead
 * of attributing the invitation to an administrator who did not act.
 *
 * Relaxes one NOT NULL only. The foreign key (RESTRICT) is kept, no row is
 * changed, and a CHECK keeps every STAFF invitation attributable to an admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE `user_invitations` MODIFY `invited_by` BIGINT UNSIGNED NULL');

        DB::statement("ALTER TABLE `user_invitations` ADD CONSTRAINT `user_invitations_staff_have_inviter`
            CHECK (`role` <> 'staff' OR `invited_by` IS NOT NULL)");
    }

    /** Refuses while system-issued invitations exist, so no row would violate NOT NULL. */
    public function down(): void
    {
        $systemIssued = DB::table('user_invitations')->whereNull('invited_by')->count();
        if ($systemIssued > 0) {
            throw new RuntimeException("Refusing to roll back: {$systemIssued} invitation(s) were issued automatically (invited_by is NULL).");
        }

        DB::statement('ALTER TABLE `user_invitations` DROP CONSTRAINT `user_invitations_staff_have_inviter`');
        DB::statement('ALTER TABLE `user_invitations` MODIFY `invited_by` BIGINT UNSIGNED NOT NULL');
    }
};
