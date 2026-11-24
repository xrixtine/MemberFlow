<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\AccountException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AcceptInvitationRequest;
use App\Services\InvitationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * Public invitation acceptance (account activation). The token in the URL is
 * the only credential; it is single-use, expires after 72 hours and is never
 * written to logs or pages (responses carry no-referrer/no-store headers).
 */
class InvitationAcceptController extends Controller
{
    public function __construct(private readonly InvitationService $invitations) {}

    public function show(Request $request, string $token): Response|RedirectResponse
    {
        // Someone else signed in on this browser must not activate an account in their session.
        if (Auth::check()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->to($request->fullUrl());
        }

        $invitation = $this->invitations->findByToken($token);

        if ($blocker = $this->invitations->acceptanceBlocker($invitation)) {
            return response()->view('auth.invitation-invalid', ['message' => $blocker], $invitation ? 410 : 404);
        }

        return response()->view('auth.invitation-accept', ['invitation' => $invitation, 'token' => $token]);
    }

    public function store(AcceptInvitationRequest $request, string $token): Response|RedirectResponse
    {
        try {
            $user = $this->invitations->accept($token, $request->validated('name'), $request->validated('password'));
        } catch (AccountException $e) {
            return response()->view('auth.invitation-invalid', ['message' => $e->getMessage()], 410);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route($user->homeRoute())
            ->with('success', "Welcome to MemberFlow, {$user->name}! Your ".($user->isMember() ? 'member' : 'staff').' account is now active.');
    }
}
