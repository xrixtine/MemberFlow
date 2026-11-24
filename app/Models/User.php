<?php

namespace App\Models;

use App\Enums\MemberStatus;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A login account: administrator, or member (linked one-to-one to a Member record
 * through member_id). Retired staff accounts keep the non-login "legacy_staff" role
 * so their history stays attached (see UserRole).
 *
 * Accounts are created only by the initial-admin seeder, `memberflow:create-admin`, or by
 * accepting a member activation invitation, so "role", "is_active" and "member_id" are
 * deliberately not mass assignable.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
        ];
    }

    // ---- Role helpers -------------------------------------------------

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isStaff(): bool
    {
        return $this->role === UserRole::Staff;
    }

    public function isMember(): bool
    {
        return $this->role === UserRole::Member;
    }

    public function isLegacyStaff(): bool
    {
        return $this->role === UserRole::LegacyStaff;
    }

    /** Historical staff account (retired role or legacy_staff): kept for history, never signs in. */
    public function isFormerStaff(): bool
    {
        return $this->isStaff() || $this->isLegacyStaff();
    }

    /**
     * Active account whose role may authenticate. A member account additionally
     * needs a valid member link (see hasValidMemberLink). Checked at login and on
     * every authenticated request (EnsureUserIsActive).
     */
    public function canSignIn(): bool
    {
        if (! $this->is_active || $this->role?->canSignIn() !== true) {
            return false;
        }

        return ! $this->isMember() || $this->hasValidMemberLink();
    }

    /**
     * A member account's link is valid while its member record exists, is not
     * soft-deleted and has not been deactivated by an administrator. Pending and
     * expired memberships still sign in (to see their status and history).
     */
    public function hasValidMemberLink(): bool
    {
        $member = $this->member_id ? $this->member : null;

        return $member !== null && ! $member->trashed() && $member->status !== MemberStatus::Inactive;
    }

    /** Works on assigned tasks (My Tasks area): member accounts only. */
    public function worksOnTasks(): bool
    {
        return $this->role?->worksOnTasks() === true;
    }

    public function hasRole(UserRole|string $role): bool
    {
        $role = $role instanceof UserRole ? $role : UserRole::from($role);

        return $this->role === $role;
    }

    /** Route name of this user's home dashboard. */
    public function homeRoute(): string
    {
        return $this->isAdmin() ? 'admin.dashboard' : 'dashboard';
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->name)) ?: [];
        $initials = '';
        foreach (array_slice($parts, 0, 2) as $part) {
            $initials .= mb_strtoupper(mb_substr($part, 0, 1));
        }

        return $initials ?: '?';
    }

    // ---- Scopes -------------------------------------------------------

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeAdmins(Builder $query): Builder
    {
        return $query->where('role', UserRole::Admin->value);
    }

    public function scopeMembers(Builder $query): Builder
    {
        return $query->where('role', UserRole::Member->value);
    }

    /**
     * Accounts that may receive a NEW task assignment: an active MEMBER account linked
     * to a member record that is not deleted, has status Active and a membership end
     * date of today or later. TaskService::assignmentBlocker() applies the same rule
     * to a single account.
     */
    public function scopeEligibleForTasks(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where('role', UserRole::Member->value)
            ->whereIn('member_id', Member::query()
                ->where('status', MemberStatus::Active->value)
                ->whereDate('membership_end', '>=', today())
                ->select('id'));
    }

    // ---- Relationships ------------------------------------------------

    /** The member record this account belongs to (member accounts only). Kept even if the member is soft-deleted. */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class)->withTrashed();
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function assignedTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assigned_to');
    }

    public function createdTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'created_by');
    }

    public function taskAssignments(): HasMany
    {
        return $this->hasMany(TaskAssignment::class);
    }

    public function progressUpdates(): HasMany
    {
        return $this->hasMany(TaskProgressUpdate::class);
    }

    public function receivedPayments(): HasMany
    {
        return $this->hasMany(Payment::class, 'received_by');
    }

    public function accountEvents(): HasMany
    {
        return $this->hasMany(UserAccountEvent::class)->latest()->latest('id');
    }

    /** The invitation this account was created from (null for the seeded initial admin). */
    public function acceptedInvitation(): HasOne
    {
        return $this->hasOne(UserInvitation::class, 'accepted_user_id');
    }
}
