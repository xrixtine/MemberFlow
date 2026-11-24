<?php

namespace App\Services;

use App\Enums\MemberSource;
use App\Models\Member;
use App\Notifications\ApplicationAlreadyExists;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Public (online) membership applications.
 *
 * Registration itself is MembershipService::register() — the same code path as a
 * walk-in registration (pending member + pending application transaction, or a
 * "paid" transaction for a zero-fee level). This class only adds what is specific
 * to the public form: the duplicate-email rule, location consent, and the
 * verification email.
 *
 * The caller always shows the same generic response, whichever branch runs.
 */
class PublicApplicationService
{
    /** The only fields a public applicant can set (never status, numbers, amounts or verification). */
    public const FIELDS = [
        'first_name', 'middle_name', 'last_name', 'email', 'phone', 'birthdate', 'address',
        'latitude', 'longitude', 'membership_category_id', 'membership_level_id',
    ];

    public function __construct(
        private readonly MembershipService $memberships,
        private readonly ApplicantVerificationService $verification,
        private readonly EmailDeliveryTracker $tracker,
    ) {}

    /**
     * Submit an application. Returns the new member, or null when the email was
     * already registered (in which case the mailbox owner gets a short notice).
     */
    public function submit(array $data, bool $locationConsent): ?Member
    {
        $data = Arr::only($data, self::FIELDS);
        $data['email'] = strtolower(trim($data['email']));

        // Coordinates are stored only with explicit consent (enforced again in MembershipService,
        // which also records location_consent_at).
        $data['location_consent'] = $locationConsent;

        try {
            $member = DB::transaction(function () use ($data) {
                if (Member::withTrashed()->where('email', $data['email'])->lockForUpdate()->exists()) {
                    return null;
                }

                return $this->memberships->register($data, null, true, MemberSource::Online);
            });
        } catch (UniqueConstraintViolationException) {
            // Two simultaneous submissions with the same email: the other one won.
            $member = null;
        }

        if ($member === null) {
            $this->notifyDuplicate($data['email']);

            return null;
        }

        $this->verification->sendVerification($member, respectCooldown: false);

        return $member;
    }

    /** At most one "already registered" notice per address per day (no mail bombing). */
    private function notifyDuplicate(string $email): void
    {
        $existing = Member::withTrashed()->where('email', $email)->first();
        if (! $existing) {
            return;
        }

        $this->tracker->send(
            Notification::route('mail', $email),
            new ApplicationAlreadyExists,
            $existing,
            'duplicate-application:member:'.$existing->id.':'.now()->format('Y-m-d'),
        );
    }
}
