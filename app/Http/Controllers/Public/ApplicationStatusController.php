<?php

namespace App\Http\Controllers\Public;

use App\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Services\ApplicantVerificationService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Private application-status page (signed, expiring link from the applicant's email).
 * Shows only: first name, reference, application status, verification status and
 * the amounts. Never the address, phone, birthdate, coordinates or email.
 */
class ApplicationStatusController extends Controller
{
    public function __construct(private readonly ApplicantVerificationService $verification) {}

    public function show(Request $request, string $member, string $hash): Response
    {
        $applicant = $this->verification->findByPublicId($member);

        if ($problem = $this->verification->linkProblem($request, $applicant, $hash)) {
            return response()->view('public.link-invalid', ['reason' => $problem, 'kind' => 'status'],
                $problem === 'expired' ? 410 : 403);
        }

        $transaction = $applicant->transactions()
            ->where('type', TransactionType::Application->value)
            ->latest()->latest('id')
            ->first() ?? $applicant->transactions()->latest()->latest('id')->first();

        return response()->view('public.status', [
            'firstName' => $applicant->first_name,
            'verified' => $applicant->isEmailVerified(),
            'transaction' => $transaction,
        ]);
    }
}
