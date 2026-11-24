<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePublicApplicationRequest;
use App\Models\MembershipCategory;
use App\Models\MembershipLevel;
use App\Services\PublicApplicationService;
use App\Support\FormTimer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Public membership application form (no login). Creates an ONLINE, pending
 * member and application through the same MembershipService as walk-ins.
 */
class ApplicationController extends Controller
{
    public function create(): View
    {
        return view('public.apply', [
            'categories' => MembershipCategory::where('is_active', true)->orderBy('name')->get(),
            'levels' => MembershipLevel::active()->ordered()->get(),
            'formToken' => FormTimer::issue(),
        ]);
    }

    public function store(StorePublicApplicationRequest $request, PublicApplicationService $applications): RedirectResponse
    {
        $applications->submit($request->validated(), $request->locationConsent());

        // Identical response for new and already-registered emails (enumeration-safe).
        // Nothing identifying is put in the URL or the session.
        return redirect()->route('apply.submitted')->with('application_submitted', true);
    }

    /** Session-flash-only confirmation page; visiting it directly goes back to the form. */
    public function submitted(Request $request): View|RedirectResponse
    {
        if (! $request->session()->get('application_submitted')) {
            return redirect()->route('apply.create');
        }

        return view('public.submitted', [
            'ttlHours' => (int) config('memberflow.verification_ttl_hours', 48),
        ]);
    }
}
