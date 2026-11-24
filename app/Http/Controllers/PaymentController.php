<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMethod;
use App\Enums\TransactionStatus;
use App\Exceptions\PaymentException;
use App\Http\Requests\StorePaymentRequest;
use App\Http\Requests\VoidPaymentRequest;
use App\Models\MembershipTransaction;
use App\Models\Payment;
use App\Services\PaymentService;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Payment recording, history, details, receipts and voiding (admin-only).
 * There is no edit or delete: payments are financial records.
 */
class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Payment::class);

        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'method' => PaymentMethod::tryFrom((string) $request->query('method')),
            'state' => in_array($request->query('state'), ['valid', 'voided'], true) ? $request->query('state') : null,
            'from' => $this->date($request->query('from')),
            'to' => $this->date($request->query('to')),
        ];

        $query = Payment::query()
            ->when($filters['q'] !== '', function ($query) use ($filters) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $filters['q']).'%';
                $query->where(fn ($q) => $q
                    ->where('receipt_no', 'like', $like)
                    ->orWhere('external_reference', 'like', $like)
                    ->orWhereHas('transaction', fn ($t) => $t->where('reference_no', 'like', $like)
                        ->orWhereHas('member', fn ($m) => $m->search($filters['q']))));
            })
            ->when($filters['method'], fn ($q, $m) => $q->where('method', $m->value))
            ->when($filters['state'] === 'valid', fn ($q) => $q->whereNull('voided_at'))
            ->when($filters['state'] === 'voided', fn ($q) => $q->whereNotNull('voided_at'))
            ->when($filters['from'], fn ($q, $d) => $q->whereDate('paid_at', '>=', $d))
            ->when($filters['to'], fn ($q, $d) => $q->whereDate('paid_at', '<=', $d));

        $totalCents = Money::fromDecimal((clone $query)->whereNull('voided_at')->sum('amount'));

        $payments = $query
            ->with(['transaction.member', 'receiver'])
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('payments.index', [
            'payments' => $payments,
            'filters' => $filters,
            'totalCents' => $totalCents,
            'hasFilters' => collect($filters)->filter(fn ($v) => $v !== null && $v !== '')->isNotEmpty(),
        ]);
    }

    /** Step 1: choose a transaction with a balance. Step 2: the payment form. */
    public function create(Request $request): View
    {
        $this->authorize('create', Payment::class);

        $transaction = $request->filled('transaction')
            ? MembershipTransaction::with(['member', 'level', 'validPayments'])->find((int) $request->query('transaction'))
            : null;

        if (! $transaction) {
            $q = trim((string) $request->query('q', ''));
            $open = MembershipTransaction::query()
                ->with(['member', 'level'])
                ->where('status', TransactionStatus::Pending->value)
                ->whereColumn('amount_paid', '<', 'amount')
                ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                    ->where('reference_no', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%')
                    ->orWhereHas('member', fn ($m) => $m->search($q))))
                ->latest()
                ->limit(25)
                ->get();

            return view('payments.pick-transaction', compact('q', 'open'));
        }

        return view('payments.create', [
            'transaction' => $transaction,
            'blocker' => $this->payments->paymentBlocker($transaction),
            // A fresh key per rendered form: resubmitting this form cannot record twice.
            'idempotencyKey' => old('idempotency_key', (string) Str::uuid()),
        ]);
    }

    public function store(StorePaymentRequest $request): RedirectResponse
    {
        $transaction = $request->transaction();

        try {
            $result = $this->payments->record($transaction, $request->paymentData(), $request->user());
        } catch (PaymentException $e) {
            return back()->withInput()->withErrors([$e->field => $e->getMessage()]);
        }

        $payment = $result->payment;

        if ($result->duplicate) {
            return redirect()->route('admin.payments.show', $payment)
                ->with('warning', "This payment was already recorded as {$payment->receipt_no}. No duplicate was created.");
        }

        $transaction->refresh();
        $message = "Payment {$payment->receipt_no} of {$payment->formattedAmount()} recorded. ";
        $message .= $transaction->status === TransactionStatus::Paid
            ? "{$transaction->reference_no} is now fully paid and ready for approval."
            : "Remaining balance: {$transaction->formattedBalance()}.";

        return redirect()->route('admin.payments.show', $payment)->with('success', $message);
    }

    public function show(Payment $payment): View
    {
        $this->authorize('view', $payment);

        $payment->load(['receiver', 'voider', 'transaction.member', 'transaction.level',
            'transaction.payments' => fn ($q) => $q->with('receiver')->orderBy('paid_at')->orderBy('id')]);

        return view('payments.show', [
            'payment' => $payment,
            'voidBlocker' => $this->payments->voidBlocker($payment, $payment->transaction),
        ]);
    }

    public function receipt(Payment $payment): View
    {
        $this->authorize('view', $payment);

        $payment->load(['receiver', 'voider', 'transaction.member', 'transaction.level']);

        return view('payments.receipt', compact('payment'));
    }

    public function void(VoidPaymentRequest $request, Payment $payment): RedirectResponse
    {
        try {
            $this->payments->void($payment, $request->user(), $request->validated('void_reason'));
        } catch (PaymentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $transaction = $payment->transaction()->first();

        return redirect()->route('admin.payments.show', $payment)->with('success',
            "Payment {$payment->receipt_no} was voided. {$transaction->reference_no} now has a balance of "
            ."{$transaction->formattedBalance()} ({$transaction->status->label()}).");
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('!Y-m-d', $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
