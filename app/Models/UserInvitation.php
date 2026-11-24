<?php

namespace App\Models;

use App\Enums\InvitationStatus;
use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Account invitation: member-account activation (role = member, member_id names the
 * member record). Old staff invitations are kept as history only. Only token_hash
 * (SHA-256) is stored; the raw token is emailed once and never persisted.
 * member_id is deliberately not mass assignable.
 */
class UserInvitation extends Model
{
    protected $fillable = [
        'email',
        'name',
        'role',
        'token_hash',
        'status',
        'expires_at',
        'invited_by',
        'send_count',
        'last_sent_at',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'status' => InvitationStatus::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_sent_at' => 'datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Effective status: a pending invitation past its expiry counts as expired. */
    public function effectiveStatus(): InvitationStatus
    {
        if ($this->status === InvitationStatus::Pending && $this->expires_at->isPast()) {
            return InvitationStatus::Expired;
        }

        return $this->status;
    }

    public function isUsable(): bool
    {
        return $this->effectiveStatus() === InvitationStatus::Pending;
    }

    /** Member record a member-account invitation activates (null for staff invitations). */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class)->withTrashed();
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function acceptedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_user_id');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }
}
