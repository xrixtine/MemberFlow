<?php

namespace App\Http\Controllers;

use App\Exceptions\AccountException;
use App\Services\MemberAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;

/**
 * Member self-service for a pending sign-in email change (role:member routes).
 *
 * The confirm link is RELATIVE-signed, expires, names the account and is bound
 * to a hash of the new email. It works only for the account it names, and only
 * while that member is signed in, so the new mailbox alone cannot take over the
 * account and the old account cannot be moved to an address nobody confirmed.
 */
class AccountEmailController extends Controller
{
    public function __construct(private readonly MemberAccountService $accounts) {}

    public function confirm(Request $request, string $user, string $hash): Response|RedirectResponse
    {
        $ignore = (array) config('memberflow.signature_ignored_query', []);
        if (! URL::hasCorrectSignature($request, absolute: false, ignoreQuery: $ignore) || ! URL::signatureHasNotExpired($request)) {
            return response()->view('auth.invitation-invalid', ['message' => 'This confirmation link is invalid or has expired. Use "Resend confirmation" on your dashboard to get a new one.'], 403);
        }
        if ((int) $user !== (int) $request->user()->id) {
            return response()->view('auth.invitation-invalid', ['message' => 'This confirmation link belongs to a different account. Sign out and sign in to the account it was sent for.'], 403);
        }

        try {
            $changed = $this->accounts->confirmEmailChange($request->user(), $hash);
        } catch (AccountException $e) {
            return response()->view('auth.invitation-invalid', ['message' => $e->getMessage()], 409);
        }

        return redirect()->route('dashboard')->with('success', $changed
            ? 'Your sign-in email is now '.$request->user()->fresh()->email.'. Use it the next time you sign in.'
            : 'Your sign-in email was already up to date.');
    }

    public function resend(Request $request): RedirectResponse
    {
        try {
            $this->accounts->sendEmailChangeConfirmation($request->user());
        } catch (AccountException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'We sent a new confirmation link to your new email address.');
    }
}
