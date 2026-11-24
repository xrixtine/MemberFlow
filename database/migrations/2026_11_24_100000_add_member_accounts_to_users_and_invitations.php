<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Revision R1 — Administrator / Member architecture (schema only).
 *
 * ADDITIVE ONLY: two nullable columns, their foreign keys/indexes and CHECK
 * constraints. No existing row is updated or deleted; every existing row already
 * satisfies the new constraints (existing users are admin/staff with no member
 * link, existing invitations are staff invitations with no member link), so
 * MariaDB validates them without changes.
 *
 * - users.member_id           one login account per member record (UNIQUE),
 *                             RESTRICT so a linked member can never be hard-deleted.
 * - user_invitations.member_id the member record a "member" invitation activates.
 *
 * Staged role migration: 'staff' stays a valid stored value until the staff
 * accounts are converted (Phase R6); 'legacy_staff' is the non-login value that
 * retired staff accounts will receive. Nothing here converts any account.
 */
return new class extends Migration
{
    public function up(): void
    {
        // UNIQUE index first, so the foreign key reuses it (otherwise MariaDB adds a redundant index).
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('member_id')->nullable()->after('role');
            $table->unique('member_id', 'users_member_id_unique');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('member_id', 'users_member_id_foreign')
                ->references('id')->on('members')->restrictOnDelete()->restrictOnUpdate();
        });

        Schema::table('user_invitations', function (Blueprint $table) {
            $table->foreignId('member_id')->nullable()->after('role')
                ->constrained('members')->restrictOnDelete()->restrictOnUpdate();
        });

        // Only known role values may be stored.
        DB::statement("ALTER TABLE `users` ADD CONSTRAINT `users_role_valid`
            CHECK (`role` IN ('admin', 'staff', 'member', 'legacy_staff'))");

        // A member account MUST point to its member record, and only member accounts may.
        DB::statement("ALTER TABLE `users` ADD CONSTRAINT `users_member_link_matches_role`
            CHECK ((`role` = 'member' AND `member_id` IS NOT NULL) OR (`role` <> 'member' AND `member_id` IS NULL))");

        // Invitations never create administrators (those come from the CLI only).
        DB::statement("ALTER TABLE `user_invitations` ADD CONSTRAINT `user_invitations_role_valid`
            CHECK (`role` IN ('staff', 'member'))");

        // A member invitation MUST name its member record, and only member invitations may.
        DB::statement("ALTER TABLE `user_invitations` ADD CONSTRAINT `user_invitations_member_link_matches_role`
            CHECK ((`role` = 'member' AND `member_id` IS NOT NULL) OR (`role` <> 'member' AND `member_id` IS NULL))");
    }

    /**
     * Rollback removes only what up() added. It refuses to run while any member
     * link exists, so it can never silently discard account links.
     */
    public function down(): void
    {
        $linkedUsers = DB::table('users')->whereNotNull('member_id')->count();
        $linkedInvitations = DB::table('user_invitations')->whereNotNull('member_id')->count();
        if ($linkedUsers + $linkedInvitations > 0) {
            throw new RuntimeException("Refusing to roll back: {$linkedUsers} user(s) and {$linkedInvitations} invitation(s) are linked to members.");
        }
        if (DB::table('users')->whereIn('role', ['member', 'legacy_staff'])->exists()) {
            throw new RuntimeException('Refusing to roll back: member or legacy_staff accounts exist.');
        }

        DB::statement('ALTER TABLE `user_invitations` DROP CONSTRAINT `user_invitations_member_link_matches_role`');
        DB::statement('ALTER TABLE `user_invitations` DROP CONSTRAINT `user_invitations_role_valid`');
        DB::statement('ALTER TABLE `users` DROP CONSTRAINT `users_member_link_matches_role`');
        DB::statement('ALTER TABLE `users` DROP CONSTRAINT `users_role_valid`');

        Schema::table('user_invitations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('member_id');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign('users_member_id_foreign');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_member_id_unique');
            $table->dropColumn('member_id');
        });
    }
};
