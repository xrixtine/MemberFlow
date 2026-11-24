<?php

namespace App\Http\Controllers;

use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Administrator reports. Period-based sections use [from, to] (inclusive days);
 * snapshot sections (current member/transaction/task status) are "as of now".
 * Print-friendly through the browser's print dialog (navigation is hidden in print).
 */
class ReportController extends Controller
{
    public const MAX_DAYS = 1100; // ~3 years

    public function index(Request $request, ReportService $reports): View
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ], [
            'to.after_or_equal' => 'The end date must be on or after the start date.',
        ]);

        $from = isset($data['from']) ? CarbonImmutable::parse($data['from']) : CarbonImmutable::today()->startOfMonth();
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to']) : CarbonImmutable::today();

        if ($from->gt($to)) {
            throw ValidationException::withMessages(['from' => 'The start date must be on or before the end date.']);
        }
        if ($from->diffInDays($to) > self::MAX_DAYS) {
            throw ValidationException::withMessages(['from' => 'Choose a period of at most 3 years.']);
        }

        $today = CarbonImmutable::today();

        return view('reports.index', [
            'from' => $from,
            'to' => $to,
            'presets' => [
                'This month' => [$today->startOfMonth(), $today],
                'Last month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()],
                'Last 30 days' => [$today->subDays(29), $today],
                'This year' => [$today->startOfYear(), $today],
            ],
            'memberStatus' => $reports->memberStatusCounts(),
            'memberSources' => $reports->memberSources(),
            'byCategory' => $reports->membersBy('category'),
            'byLevel' => $reports->membersBy('level'),
            'transactionStatus' => $reports->transactionStatusCounts(),
            'opened' => $reports->transactionsOpened($from, $to),
            'payments' => $reports->payments($from, $to),
            'outstanding' => $reports->outstandingTotals(),
            'outstandingList' => $reports->outstandingList(25),
            'tasks' => $reports->tasks($from, $to),
            'workload' => $reports->staffWorkload($from, $to),
            'generatedAt' => now(),
        ]);
    }
}
