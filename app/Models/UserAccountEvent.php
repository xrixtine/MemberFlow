<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit entry for a user account. Never edited or deleted.
 */
class UserAccountEvent extends Model
{
    public const ACTIVATED = 'activated';       // invitation accepted, account created

    public const DEACTIVATED = 'deactivated';

    public const REACTIVATED = 'reactivated';

    public const PROMOTED = 'promoted';          // role staff → admin

    public const DETAILS_UPDATED = 'details_updated';

    public const EMAIL_CHANGE_REQUESTED = 'email_change_requested'; // membership email changed; awaiting the member's confirmation

    public const EMAIL_CHANGED = 'email_changed';                   // sign-in email changed after confirmation

    public const ROLE_CHANGED = 'role_changed';                     // R6 staff retirement / member linkage (and its reversal)

    public const ADMIN_CREATED = 'admin_created';                   // memberflow:create-admin (CLI)

    public const PASSWORD_CHANGED = 'password_changed';             // memberflow:change-admin-credentials (CLI); never stores the password

    public const LABELS = [
        self::ACTIVATED => 'Account activated',
        self::DEACTIVATED => 'Account deactivated',
        self::REACTIVATED => 'Account reactivated',
        self::PROMOTED => 'Promoted to administrator',
        self::DETAILS_UPDATED => 'Details updated',
        self::EMAIL_CHANGE_REQUESTED => 'Sign-in email change requested',
        self::EMAIL_CHANGED => 'Sign-in email changed',
        self::ROLE_CHANGED => 'Role changed',
        self::ADMIN_CREATED => 'Administrator created (command line)',
        self::PASSWORD_CHANGED => 'Password changed',
    ];

    protected $fillable = ['user_id', 'actor_id', 'type', 'old_value', 'new_value', 'note'];

    public function label(): string
    {
        return self::LABELS[$this->type] ?? ucfirst(str_replace('_', ' ', $this->type));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
