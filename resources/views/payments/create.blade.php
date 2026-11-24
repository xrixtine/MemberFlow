@php use App\Enums\PaymentMethod; use App\Support\Money; @endphp
<x-layouts.app title="Record payment">
    <x-page-header eyebrow="Payments" title="Record payment" :subtitle="'Step 2 of 2 — '.$transaction->reference_no.' · '.$transaction->member->fullName()">
        <a href="{{ route('admin.transactions.show', $transaction) }}" class="btn-secondary"><x-icon name="arrow-left" class="size-4"/> Back to transaction</a>
    </x-page-header>

    <div class="grid gap-6 xl:grid-cols-3">
        <section class="card xl:col-span-2" aria-labelledby="payment-form-heading">
            <h2 id="payment-form-heading" class="card-title">Payment details</h2>

            @if ($blocker)
                <div class="alert-warning mt-5" role="alert">
                    <x-icon name="alert" class="mt-0.5 size-4 shrink-0"/><p>{{ $blocker }}</p>
                </div>
                <a href="{{ route('admin.transactions.show', $transaction) }}" class="btn-secondary mt-5">Open transaction</a>
            @else
                <p class="card-subtitle">The transaction becomes <strong>Paid</strong> automatically when payments cover the full amount.</p>

                @error('membership_transaction_id')
                    <div class="alert-error mt-5" role="alert"><x-icon name="alert" class="mt-0.5 size-4 shrink-0"/><p>{{ $message }}</p></div>
                @enderror
                @error('idempotency_key')
                    <div class="alert-error mt-5" role="alert"><x-icon name="alert" class="mt-0.5 size-4 shrink-0"/><p>{{ $message }}</p></div>
                @enderror

                <form method="POST" action="{{ route('admin.payments.store') }}" class="mt-6 space-y-5" novalidate>
                    @csrf
                    <input type="hidden" name="membership_transaction_id" value="{{ $transaction->id }}">
                    <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">

                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-form.input name="amount" label="Amount (₱)" required inputmode="decimal" autocomplete="off"
                                      :value="Money::toDecimal($transaction->balanceCents())"
                                      data-balance-cents="{{ $transaction->balanceCents() }}" data-balance-preview="balance-preview"
                                      hint="Up to the remaining balance. No commas; at most 2 decimals."/>
                        <x-form.input name="paid_at" type="date" label="Payment date" required
                                      :value="now()->format('Y-m-d')" :max="now()->format('Y-m-d')"/>
                    </div>

                    <fieldset>
                        <legend class="form-label">Payment method<span class="text-red-600" aria-hidden="true"> *</span></legend>
                        <div class="grid gap-3 sm:grid-cols-3">
                            @foreach (PaymentMethod::cases() as $method)
                                <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-line bg-field p-3 text-sm has-[:checked]:border-charcoal has-[:checked]:bg-white">
                                    <input type="radio" name="method" value="{{ $method->value }}" class="accent-[#5d7f24]"
                                        @checked(old('method', PaymentMethod::Cash->value) === $method->value)>
                                    <span class="font-bold">{{ $method->label() }}</span>
                                </label>
                            @endforeach
                        </div>
                        @error('method')<p class="form-error">{{ $message }}</p>@enderror
                    </fieldset>

                    <x-form.input name="external_reference" label="External reference" maxlength="100"
                                  hint="GCash reference or bank transaction number. Optional for cash."/>
                    <x-form.textarea name="notes" label="Remarks" rows="2" maxlength="1000"/>

                    <button type="submit" class="btn-primary" data-loading="Recording…">Record payment</button>
                </form>
            @endif
        </section>

        <aside class="space-y-6">
            <section class="rounded-2xl bg-charcoal p-6 text-white" aria-labelledby="balance-heading" aria-live="polite">
                <p id="balance-heading" class="text-xs font-bold uppercase tracking-wider text-sidebar-text">Balance</p>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-sidebar-text">Amount due</dt><dd>{{ Money::formatDecimal($transaction->amount) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-sidebar-text">Already paid</dt><dd>{{ Money::formatDecimal($transaction->amount_paid) }}</dd></div>
                    <div class="flex justify-between border-t border-white/10 pt-2"><dt class="text-sidebar-text">Remaining</dt><dd class="font-bold">{{ $transaction->formattedBalance() }}</dd></div>
                </dl>
                <div id="balance-preview" class="mt-4 rounded-xl bg-charcoal-soft p-3 text-sm" hidden>
                    <p>After this payment: <strong class="text-lime" data-preview="remaining"></strong></p>
                    <p class="mt-1 text-xs text-sidebar-text" data-preview="status"></p>
                </div>
            </section>

            <section class="card" aria-labelledby="txn-summary-heading">
                <h2 id="txn-summary-heading" class="card-title">Transaction</h2>
                <dl class="mt-4 space-y-3 text-sm">
                    <div><dt class="text-xs font-bold uppercase text-muted">Reference</dt><dd class="font-mono">{{ $transaction->reference_no }}</dd></div>
                    <div><dt class="text-xs font-bold uppercase text-muted">Member</dt><dd>{{ $transaction->member->fullName() }} · <span class="font-mono">{{ $transaction->member->membership_no }}</span></dd></div>
                    <div><dt class="text-xs font-bold uppercase text-muted">Type · Level</dt><dd>{{ $transaction->type->label() }} · {{ $transaction->level->name }}</dd></div>
                    <div><dt class="text-xs font-bold uppercase text-muted">Status</dt><dd><x-badge :status="$transaction->status"/></dd></div>
                </dl>
            </section>
        </aside>
    </div>
</x-layouts.app>
