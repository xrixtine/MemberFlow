<?php

namespace App\Http\Controllers;

use App\Enums\TransactionStatus;
use App\Models\Payment;
use App\Services\MemberPortalService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Member portal pages (role:member). Data always comes from the signed-in
 * account's own member record; there is no member id in any of these URLs.
 * The only id in a URL is a payment's, and PaymentPolicy::view checks ownership.
 * Every page is read-only: members never create, pay, approve or change anything here.
 */
class MemberPortalController extends Controller
{
    public function __construct(private readonly MemberPortalService $portal) {}

    /** /my/membership — profile, status, validity, verification, renewal eligibility and history. */
    public function membership(Request $request): View
    {
        $member = $this->portal->memberFor($request->user());

        return view('member.membership', [
            'member' => $member,
            'validity' => $this->portal->validity($member),
            'application' => $this->portal->applicationStatus($member),
            'renewal' => $this->portal->renewal($member),
            // The member's OWN coordinates, shown (with weather there) only while consent is on file.
            'showLocation' => $member->hasLocation() && $member->location_consent_at !== null,
            'transactions' => $this->portal->transactions($member)
                ->with(MemberPortalService::withPayments())
                ->latest()->latest('id')->get(),
        ]);
    }

    /** /my/membership/print — the member's OWN printable membership record. */
    public function print(Request $request): View
    {
        $member = $this->portal->memberFor($request->user());
        $member->load(['transactions' => fn ($q) => $q->with('level')->latest()->latest('id')]);

        return view('members.print', ['member' => $member]);
    }

    /** /my/payments — charges, valid collections, balances and every payment (voided ones marked). */
    public function payments(Request $request): View
    {
        $member = $this->portal->memberFor($request->user());

        return view('member.payments', [
            'member' => $member,
            'summary' => $this->portal->paymentSummary($member),
            'charges' => $this->portal->transactions($member)->with('level:id,name')
                ->where('status', '!=', TransactionStatus::Cancelled->value)->latest()->latest('id')->get(),
            'payments' => $this->portal->payments($member)
                ->with('transaction:id,reference_no,type,member_id')
                ->latest('paid_at')->latest('id')->paginate(15),
        ]);
    }

    /** /my/payments/{payment}/receipt — printable receipt; 403 unless the payment is the member's own. */
    public function receipt(Payment $payment): View
    {
        $this->authorize('view', $payment);

        $payment->load(['receiver', 'voider', 'transaction.member', 'transaction.level']);

        return view('payments.receipt', compact('payment'));
    }
}
