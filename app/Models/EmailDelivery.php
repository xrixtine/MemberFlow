<?php

namespace App\Models;

use App\Enums\EmailDeliveryStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One outgoing email and its honest delivery status (see EmailDeliveryStatus).
 * Never stores credentials, tokens or links.
 */
class EmailDelivery extends Model
{
    /** Queued for longer than this many minutes ⇒ the queue worker is probably not running. */
    public const STALE_AFTER_MINUTES = 5;

    protected $fillable = [
        'notification',
        'dedupe_key',
        'recipient_email',
        'notifiable_type',
        'notifiable_id',
        'related_type',
        'related_id',
        'context',
        'mailer',
        'status',
        'attempts',
        'error_message',
        'message_id',
        'superseded_by_id',
        'queued_at',
        'last_attempt_at',
        'sent_at',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => EmailDeliveryStatus::class,
            'context' => 'array',
            'attempts' => 'integer',
            'queued_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    // ---- Helpers --------------------------------------------------------

    public function isStale(): bool
    {
        return $this->status === EmailDeliveryStatus::Queued
            && $this->queued_at !== null
            && $this->queued_at->lt(now()->subMinutes(self::STALE_AFTER_MINUTES));
    }

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->notification] ?? $this->notification;
    }

    public const TYPE_LABELS = [
        'VerifyApplicantEmail' => 'Applicant email verification',
        'ApplicationAlreadyExists' => 'Duplicate application notice',
        'MembershipApplicationReceived' => 'Application received',
        'MembershipRenewalSubmitted' => 'Renewal submitted',
        'MembershipApproved' => 'Membership approved',
        'MembershipTransactionCancelled' => 'Transaction cancelled',
        'StaffInvitation' => 'Staff invitation',
        'MemberAccountInvitation' => 'Member account activation',
        'ConfirmAccountEmailChange' => 'Sign-in email change confirmation',
        'TaskAssignedNotification' => 'Task assigned',
        'TaskReassignedAway' => 'Task reassigned away',
        'TaskStatusNotification' => 'Task completed / on hold',
        'TestEmail' => 'Test email',
    ];

    // ---- Scopes -------------------------------------------------------

    public function scopeStale(Builder $query): Builder
    {
        return $query->where('status', EmailDeliveryStatus::Queued->value)
            ->where('queued_at', '<', now()->subMinutes(self::STALE_AFTER_MINUTES));
    }

    public function scopeFailedUnresolved(Builder $query): Builder
    {
        return $query->where('status', EmailDeliveryStatus::Failed->value)->whereNull('superseded_by_id');
    }

    // ---- Relationships ------------------------------------------------

    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }

    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }
}
