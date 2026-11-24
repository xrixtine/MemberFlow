<div class="card max-w-2xl space-y-5">
    <x-form.input name="name" label="Level name" :value="$level->name" required maxlength="50" placeholder="e.g. Gold"/>
    <div class="grid gap-5 sm:grid-cols-3">
        <x-form.input name="fee" label="Fee (₱)" :value="$level->fee" required inputmode="decimal" placeholder="1500.00"
                      hint="No commas; up to 2 decimals."/>
        <x-form.input name="validity_months" type="number" label="Validity (months)" :value="$level->validity_months" required min="1" max="120"/>
        <x-form.input name="sort_order" type="number" label="Display order" :value="$level->sort_order" min="0" max="255"/>
    </div>
    <x-form.textarea name="benefits" label="Benefits" :value="$level->benefits" rows="5" maxlength="2000"
                     hint="One benefit per line."/>
    <label class="flex items-start gap-3 text-sm">
        <input type="hidden" name="is_active" value="0">
        <input type="checkbox" name="is_active" value="1" class="mt-0.5 size-4 accent-[#5d7f24]"
            @checked(old('is_active', $level->is_active ? '1' : '0') == '1')>
        <span><span class="font-bold">Active</span>
            <span class="block text-muted">Inactive levels stay on existing members but cannot be chosen for new registrations.</span></span>
    </label>
    @if ($level->exists && $level->transactions_count > 0)
        <div class="alert-info">
            <x-icon name="alert" class="mt-0.5 size-4 shrink-0"/>
            <p>{{ $level->transactions_count }} existing {{ str('transaction')->plural($level->transactions_count) }} keep the fee recorded when they were created. A new fee applies to future applications and renewals.</p>
        </div>
    @endif
</div>
