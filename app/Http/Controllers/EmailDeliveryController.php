<?php

namespace App\Http\Controllers;

use App\Enums\EmailDeliveryStatus;
use App\Exceptions\EmailDeliveryException;
use App\Models\EmailDelivery;
use App\Notifications\TestEmail;
use App\Services\EmailDeliveryTracker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Administrator Email log: every tracked email with its honest status, plus a
 * "send test email to me" action and a safe retry for failed deliveries.
 */
class EmailDeliveryController extends Controller
{
    public function __construct(private readonly EmailDeliveryTracker $tracker) {}

    public function index(Request $request): View
    {
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'status' => EmailDeliveryStatus::tryFrom((string) $request->query('status')),
            'type' => array_key_exists((string) $request->query('type'), EmailDelivery::TYPE_LABELS) ? (string) $request->query('type') : null,
            'stale' => $request->boolean('stale'),
        ];

        $deliveries = EmailDelivery::query()
            ->with(['related' => fn ($morph) => $morph->withoutGlobalScopes()])
            ->when($filters['q'] !== '', fn ($q) => $q->where('recipient_email', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $filters['q']).'%'))
            ->when($filters['status'], fn ($q, $status) => $q->where('status', $status->value))
            ->when($filters['type'], fn ($q, $type) => $q->where('notification', $type))
            ->when($filters['stale'], fn ($q) => $q->stale())
            ->latest()
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        $counts = EmailDelivery::query()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        return view('email-deliveries.index', [
            'deliveries' => $deliveries,
            'filters' => $filters,
            'hasFilters' => $filters['q'] !== '' || $filters['status'] || $filters['type'] || $filters['stale'],
            'counts' => $counts,
            'staleCount' => EmailDelivery::stale()->count(),
            'pendingJobs' => DB::table('jobs')->count(),
            'failedJobs' => DB::table('failed_jobs')->count(),
            'mailer' => $this->tracker->currentMailer(),
            'logMode' => $this->tracker->isLogMode(),
            'missingCredentials' => $this->tracker->smtpMissingCredentials(),
            'fromAddress' => config('mail.from.address'),
            'smtpHost' => config('mail.mailers.smtp.host'),
            'smtpPort' => config('mail.mailers.smtp.port'),
        ]);
    }

    /** Send a test email to the signed-in administrator's own address only. */
    public function sendTest(Request $request): RedirectResponse
    {
        $admin = $request->user();
        $delivery = $this->tracker->send($admin, new TestEmail, $admin);

        $message = $this->tracker->describe($delivery, 'The test email');
        if ($delivery->status === EmailDeliveryStatus::Skipped) {
            $message .= ' Change your profile email to a real inbox you control to test delivery.';
        }

        return back()->with($delivery->status === EmailDeliveryStatus::Skipped || $this->tracker->isLogMode() ? 'warning' : 'success', $message);
    }

    public function retry(EmailDelivery $delivery): RedirectResponse
    {
        try {
            $new = $this->tracker->retry($delivery);
        } catch (EmailDeliveryException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Retry created. '.$this->tracker->describe($new, 'The email'));
    }
}
