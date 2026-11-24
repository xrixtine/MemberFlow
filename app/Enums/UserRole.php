<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Account roles — Administrator / Member architecture (final since R6).
 *
 * Exactly two roles can sign in: Admin and Member (a member account also needs a
 * valid link to its member record, see User::canSignIn).
 *
 * Historical values, kept ONLY so old rows stay valid and history stays accurate:
 *  - Staff        the retired staff role. No account can sign in with it, no invitation
 *                 creates it; it remains on old accepted invitations and audit entries.
 *  - LegacyStaff  retired staff accounts (memberflow:retire-staff). Never signs in; the row
 *                 is kept because task history, invitations and audit events reference it.
 *
 * canSignIn() is the single switch the login request and the session middleware use.
 */
enum UserRole: string
{
    use HasOptions;

    case Admin = 'admin';
    case Member = 'member';
    /** @deprecated Retired role (R6). Historical value only; cannot sign in or be issued. */
    case Staff = 'staff';
    case LegacyStaff = 'legacy_staff';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::Member => 'Member',
            self::Staff => 'Staff (retired role)',
            self::LegacyStaff => 'Former staff (retired)',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Admin => 'charcoal',
            self::Member => 'green',
            self::Staff => 'lime',
            self::LegacyStaff => 'gray',
        };
    }

    /** Whether an account with this role may authenticate right now. */
    public function canSignIn(): bool
    {
        return match ($this) {
            self::Admin, self::Member => true,   // a member account also needs a valid member link (User::canSignIn)
            self::Staff => false,                // retired role (R6)
            self::LegacyStaff => false,          // historical value only — never signs in
        };
    }

    /** Only member accounts work on assigned tasks (My Tasks). */
    public function worksOnTasks(): bool
    {
        return $this === self::Member;
    }

    /** Whether this role must be linked to exactly one member record (mirrors a DB CHECK constraint). */
    public function requiresMemberLink(): bool
    {
        return $this === self::Member;
    }

    /** @return list<string> stored values of roles that may authenticate */
    public static function signInValues(): array
    {
        return array_values(array_map(
            fn (self $role) => $role->value,
            array_filter(self::cases(), fn (self $role) => $role->canSignIn()),
        ));
    }
}
