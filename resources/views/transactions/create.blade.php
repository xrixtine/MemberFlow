@php use App\Enums\TransactionType; @endphp
<x-layouts.app title="New transaction">
    <x-page-header eyebrow="Transactions" title="New transaction" :subtitle="'Step 2 of 2 — '.$member->fullName().' · '.$member->membership_no">
        <a href="{{ route('admin.transactions.create') }}" class="btn-secondary"><x-icon name="arrow-left" class="size-4"/> Choose another member</a>
    </x-page-header>

    <div class="grid gap-6 xl:grid-cols-3">
        <section class="card xl:col-span-2" aria-labelledby="txn-form-heading">
            <h2 id="txn-form-heading" class="card-title">Transaction type</h2>
            <p class="card-subtitle">The fee and validity of the chosen level are recorded on the transaction and will not change later.</p>

            {{-- Type tabs (GET links, no JavaScript needed) --}}
            <div class="mt-5 grid gap-3 sm:grid-cols-2" role="tablist">
                @foreach ([['type' => TransactionType::Application, 'blocker' => $applicationBlocker], ['type' => TransactionType::Renewal, 'blocker' => $renewalBlocker]] as $tab)
                    <a href="{{ route('admin.transactions.create', ['member' => $member->id, 'type' => $tab['type']->value]) }}" role="tab"
                       aria-selected="{{ $type === $tab['type'] ? 'true' : 'false' }}"
                       @class(['rounded-2xl border p-4 text-sm',
                               'border-charcoal bg-charcoal text-white' => $type === $tab['type'],
                               'border-line bg-white hover:border-lime-deep' => $type !== $tab['type']])>
                        <span class="flex items-center justify-between font-bold">
                            {{ $tab['type']->label() }}
                            @if ($tab['blocker'] === null)<span class="badge-green">Eligible</span>@else<span class="badge-gray">Not available</span>@endif
                        </span>
                        <span @class(['mt-1 block', 'text-sidebar-text' => $type === $tab['type'], 'text-muted' => $type !== $tab['type']])>
                            {{ $tab['blocker'] ?? ($tab['type'] === TransactionType::Application
                                ? 'First membership at the member\'s current level.'
                                : 'Extends the membership; renewal opens '.$windowDays.' days before expiry.') }}
                        </span>
                    </a>
                @endforeach
            </div>

            @php $blocker = $type === TransactionType::Application ? $applicationBlocker : $renewalBlocker; @endphp

            @if ($blocker !== null)
                <div class="alert-warning mt-6" role="alert">
                    <x-icon name="alert" class="mt-0.5 size-4 shrink-0"/>
                    <p>{{ $blocker }}</p>
                </div>
            @else
                <form method="POST" action="{{ route('admin.transactions.store') }}" class="mt-6 space-y-5">
                    @csrf
                    <input type="hidden" name="member_id" value="{{ $member->id }}">
                    <input type="hidden" name="type" value="{{ $type->value }}">

                    @if ($type === TransactionType::Renewal)
                        <div>
                            <label for="membership_level_id" class="form-label">Renewal level<span class="text-red-600" aria-hidden="true"> *</span></label>
                            <select id="membership_level_id" name="membership_level_id" required data-level-select="level-preview"
                                    @class(['form-input', 'form-input-invalid' => $errors->has('membership_level_id')])>
                                @foreach ($levels as $level)
                                    <option value="{{ $level->id }}" @selected((int) old('membership_level_id', $member->level->is_active ? $member->membership_level_id : $levels->first()?->id) === $level->id)
                                            data-name="{{ $level->name }}" data-fee="{{ $level->formattedFee() }}"
                                            data-validity="{{ $level->validityLabel() }}" data-benefits='@json($level->benefitList())'>
                                        {{ $level->name }} — {{ $level->formattedFee() }} / {{ $level->validityLabel() }}
                                    </option>
                                @endforeach
                            </select>
                            @error('membership_level_id')<p class="form-error">{{ $message }}</p>@enderror
                            <p class="form-hint">Renewing at a different level changes the member's level when the renewal is approved.</p>
                        </div>
                        @php $previewLevel = $levels->firstWhere('id', (int) old('membership_level_id', $member->membership_level_id)) ?? $levels->first(); @endphp
                    @else
                        @php $previewLevel = $member->level; @endphp
                        <p class="text-sm">Level: <strong>{{ $member->level->name }}</strong> (the member's current level; edit the member to change it while the application is unpaid).</p>
                    @endif

                    <div id="level-preview" class="rounded-2xl bg-charcoal p-5 text-white" aria-live="polite">
                        <p class="text-xs font-bold uppercase tracking-wider text-sidebar-text">Amount due</p>
                        <p class="mt-1 text-lg font-bold" data-preview="name">{{ $previewLevel?->name }}</p>
                        <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
                            <div><dt class="text-sidebar-text">Fee</dt><dd class="text-xl font-bold text-lime" data-preview="fee">{{ $previewLevel?->formattedFee() }}</dd></div>
                            <div><dt class="text-sidebar-text">Validity</dt><dd class="text-xl font-bold" data-preview="validity">{{ $previewLevel?->validityLabel() }}</dd></div>
                        </dl>
                        <ul class="mt-3 list-inside list-disc space-y-1 text-sm text-sidebar-text" data-preview="benefits">
                            @foreach ($previewLevel?->benefitList() ?? [] as $benefit)<li>{{ $benefit }}</li>@endforeach
                        </ul>
                    </div>

                    <x-form.textarea name="remarks" label="Remarks" rows="3" maxlength="1000" hint="Optional internal note."/>

                    <button type="submit" class="btn-primary" data-loading="Creating…">Create {{ strtolower($type->label()) }}</button>
                </form>
            @endif
        </section>

        <aside class="card" aria-labelledby="member-summary-heading">
            <h2 id="member-summary-heading" class="card-title">Member</h2>
            <dl class="mt-4 space-y-3 text-sm">
                <div><dt class="text-xs font-bold uppercase text-muted">Name</dt><dd><a class="hover:underline" href="{{ route('admin.members.show', $member) }}">{{ $member->fullName() }}</a></dd></div>
                <div><dt class="text-xs font-bold uppercase text-muted">Status</dt><dd><x-badge :status="$member->status"/></dd></div>
                <div><dt class="text-xs font-bold uppercase text-muted">Category · Level</dt><dd>{{ $member->category->name }} · {{ $member->level->name }}</dd></div>
                <div><dt class="text-xs font-bold uppercase text-muted">Valid until</dt><dd>{{ $member->membership_end?->format('F j, Y') ?? 'Not yet active' }}</dd></div>
            </dl>
        </aside>
    </div>
</x-layouts.app>
