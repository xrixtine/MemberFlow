<?php

namespace App\Services;

use App\Enums\MemberSource;
use App\Enums\MemberStatus;
use App\Models\EmailDelivery;
use App\Models\Member;
use App\Notifications\VerifyApplicantEmail;
use App\Support\AppUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * Applicant email verification and the private application-status link.
 *
 * Links are RELATIVE signed URLs (HMAC with APP_KEY over path + query, with an
 * expiry), prefixed with APP_URL only for the email. They are bound to:
 *   - the member's non-guessable public_id (a ULID, never the numeric id), and
 *   - sha1 of the member's current email, so changing the email kills old links.
 * Nothing is stored for a link; it cannot be forged without APP_KEY.
 *
 * Verifying records email_verified_at ONLY. It never pays, approves or changes
 * the membership or transaction status.
 */
class ApplicantVerificationService
{
    public function __construct(private readonly EmailDeliveryTracker $tracker) {}

    // ---- Links ----------------------------------------------------------

    public function verificationUrl(Member $member): string
    {
        return $this->absolute(URL::temporarySignedRoute(
            'apply.verify',
            now()->addHours($this->verificationTtlHours()),
            ['member' => $member->public_id, 'hash' => $this->emailHash($member)],
            absolute: false,
        ));
    }

    public function statusUrl(Member $member): string
    {
        return $this->absolute(URL::temporarySignedRoute(
            'apply.status',
            now()->addDays($this->statusTtlDays()),
            ['member' => $member->public_id, 'hash' => $this->emailHash($member)],
            absolute: false,
        ));
    }

    public function emailHash(Member $member): string
    {
        return sha1(strtolower(trim($member->email)));
    }

    /**
     * Check a signed link. Returns null when valid, otherwise "expired" or "invalid".
     * The member lookup uses the public ULID only (never the numeric id).
     */
    public function linkProblem(Request $request, ?Member $member, string $hash): ?string
    {
        if (! URL::hasCorrectSignature($request, absolute: false)) {
            return 'invalid';
        }
        if (! URL::signatureHasNotExpired($request)) {
            return 'expired';
        }
        if (! $member || ! hash_equals($this->emailHash($member), $hash)) {
            // Correctly signed but the member is gone or the email has changed since.
            return 'invalid';
        }

        return null;
    }

    public function findByPublicId(string $publicId): ?Member
    {
        if (! preg_match('/^[0-9a-hjkmnp-tv-z]{26}$/', $publicId)) {
            return null;
        }

        return Member::where('public_id', $publicId)->first();
    }

    // ---- Sending --------------------------------------------------------

    /**
     * Queue a verification email unless the member is already verified or one was
     * sent within the cooldown. Returns the delivery, or null when nothing was sent.
     */
    public function sendVerification(Member $member, bool $respectCooldown = true): ?EmailDelivery
    {
        return DB::transaction(function () use ($member, $respectCooldown) {
            $locked = Member::whereKey($member->id)->lockForUpdate()->first();

            if (! $locked || $locked->isEmailVerified()) {
                return null;
            }
            if ($respectCooldown && $this->inCooldown($locked)) {
                return null;
            }

            $locked->forceFill(['verification_sent_at' => now()])->save();

            return $this->tracker->send($locked, new VerifyApplicantEmail($locked), $locked);
        });
    }

    public function inCooldown(Member $member): bool
    {
        return $member->verification_sent_at !== null
            && $member->verification_sent_at->gt(now()->subSeconds($this->cooldownSeconds()));
    }

    /**
     * Public "resend verification" — always silent. Sends only to an unverified,
     * non-inactive ONLINE applicant; anything else is ignored without a trace in
     * the response, so the form cannot be used to discover registered emails.
     */
    public function resendForEmail(string $email): void
    {
        $member = Member::where('email', strtolower(trim($email)))->first();

        if ($member
            && $member->source === MemberSource::Online
            && $member->status !== MemberStatus::Inactive
            && ! $member->isEmailVerified()) {
            $this->sendVerification($member);
        }
    }

    // ---- Verifying ------------------------------------------------------

    /** Record the verification (idempotent). Returns true if it was newly verified. */
    public function verify(Member $member): bool
    {
        return DB::transaction(function () use ($member) {
            $locked = Member::whereKey($member->id)->lockForUpdate()->firstOrFail();

            if ($locked->isEmailVerified()) {
                return false;
            }

            // ONLY the timestamp changes — no status, payment or approval side effects.
            $locked->forceFill(['email_verified_at' => now()])->save();
            $member->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }

    // ---- Settings -------------------------------------------------------

    public function verificationTtlHours(): int
    {
        return (int) config('memberflow.verification_ttl_hours', 48);
    }

    public function statusTtlDays(): int
    {
        return (int) config('memberflow.status_link_ttl_days', 30);
    }

    public function cooldownSeconds(): int
    {
        return (int) config('memberflow.verification_resend_cooldown_seconds', 60);
    }

    private function absolute(string $relative): string
    {
        return AppUrl::to($relative);
    }
}
