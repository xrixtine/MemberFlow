<?php

namespace App\Models;

use App\Enums\MemberSource;
use App\Enums\MemberStatus;
use App\Enums\TransactionStatus;
use Database\Factories\MemberFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

/**
 * A registered member of the organisation. The record itself is not a login
 * account; a member may have at most one linked User account (see account()).
 */
class Member extends Model
{
    /** @use HasFactory<MemberFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'first_name',
        'middle_name',
        'last_name',
        'email',
        'phone',
        'birthdate',
        'address',
        'latitude',
        'longitude',
        'membership_category_id',
        'membership_level_id',
    ];

    protected function casts(): array
    {
        return [
            'birthdate' => 'date',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'source' => MemberSource::class,
            'status' => MemberStatus::class,
            'email_verified_at' => 'datetime',
            'verification_sent_at' => 'datetime',
            'location_consent_at' => 'datetime',
            'membership_start' => 'date',
            'membership_end' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Member $member): void {
            $member->public_id ??= strtolower((string) Str::ulid());
            $member->source ??= MemberSource::WalkIn;
            $member->status ??= MemberStatus::Pending;
        });
    }

    // ---- Accessors / helpers -------------------------------------------

    public function fullName(): string
    {
        return trim(collect([$this->first_name, $this->middle_name, $this->last_name])->filter()->implode(' '));
    }

    /** "Dela Cruz, Juan P." style for directory listings. */
    public function sortableName(): string
    {
        $middle = $this->middle_name ? ' '.mb_substr($this->middle_name, 0, 1).'.' : '';

        return "{$this->last_name}, {$this->first_name}{$middle}";
    }

    public function isEmailVerified(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function hasLocation(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    // ---- Scopes -------------------------------------------------------

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term): void {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
            $q->where('first_name', 'like', $like)
                ->orWhere('last_name', 'like', $like)
                ->orWhere('middle_name', 'like', $like)
                ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$like])
                ->orWhere('membership_no', 'like', $like)
                ->orWhere('email', 'like', $like);
        });
    }

    public function scopeStatus(Builder $query, MemberStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }

    // ---- Relationships ------------------------------------------------

    public function category(): BelongsTo
    {
        return $this->belongsTo(MembershipCategory::class, 'membership_category_id');
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(MembershipLevel::class, 'membership_level_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(MembershipTransaction::class);
    }

    public function latestTransaction(): HasOne
    {
        return $this->hasOne(MembershipTransaction::class)->latestOfMany();
    }

    /** The current pending/paid transaction, if any (at most one by business rule). */
    public function openTransaction(): HasOne
    {
        return $this->hasOne(MembershipTransaction::class)
            ->whereIn('status', [TransactionStatus::Pending->value, TransactionStatus::Paid->value])
            ->latestOfMany();
    }

    public function payments(): HasManyThrough
    {
        return $this->hasManyThrough(Payment::class, MembershipTransaction::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /** The member's login account, if one has been activated (one-to-one via users.member_id). */
    public function account(): HasOne
    {
        return $this->hasOne(User::class, 'member_id');
    }

    public function hasAccount(): bool
    {
        return $this->account()->exists();
    }

    /** Member-account invitations issued for this member (history kept: pending/accepted/revoked/expired). */
    public function accountInvitations(): HasMany
    {
        return $this->hasMany(UserInvitation::class);
    }
}
