<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Services\ApplicantVerificationService;
use App\Services\MemberAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Applicant email verification (signed link) and the public "resend" form.
 */
class ApplicantVerificationController extends Controller
{
    public function __construct(private readonly ApplicantVerificationService $verification) {}

    public function verify(Request $request, string $member, string $hash): Response
    {
        $applicant = $this->verification->findByPublicId($member);

        if ($problem = $this->verification->linkProblem($request, $applicant, $hash)) {
            return response()->view('public.link-invalid', ['reason' => $problem, 'kind' => 'verification'],
                $problem === 'expired' ? 410 : 403);
        }

        $newlyVerified = $this->verification->verify($applicant);

        // R3: an online applicant who has just proved their email is invited to activate a
        // member login. Separate transaction, best effort: never undoes the verification and
        // never approves or pays anything. Repeated clicks do not issue duplicates.
        $accountInvited = $newlyVerified && app(MemberAccountService::class)->inviteAfterVerification($applicant) !== null;

        return response()->view('public.verified', [
            'member' => $applicant,
            'newlyVerified' => $newlyVerified,
            'accountInvited' => $accountInvited,
            'statusUrl' => $this->verification->statusUrl($applicant),
        ]);
    }

    public function resendForm(): Response
    {
        return response()->view('public.resend');
    }

    public function resend(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
        ]);

        $email = strtolower(trim($data['email']));

        // Per-address limit on top of the per-IP route limiter. Exceeding it changes
        // nothing visible: the response is the same generic message.
        $key = 'resend-verification:'.sha1($email);
        if (! RateLimiter::tooManyAttempts($key, 3)) {
            RateLimiter::hit($key, 3600);
            $this->verification->resendForEmail($email);
        }

        return redirect()->route('apply.resend')->with('status',
            'If an unverified online application exists for that address, a new verification link has been sent to it. '
            .'The link is valid for '.$this->verification->verificationTtlHours().' hours. Check your spam folder too.');
    }
}
