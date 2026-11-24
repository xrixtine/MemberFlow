{{--
    Shared member form (register + edit).
    Expects: $member, $categories, $levels, optional $levelLocked (bool)
--}}
@php
    $levelLocked ??= false;
    $selectedLevelId = (int) old('membership_level_id', $member->membership_level_id);
    $selectedLevel = $levels->firstWhere('id', $selectedLevelId);
    $selectedCategoryId = (string) old('membership_category_id', $member->membership_category_id);
@endphp

<div class="grid gap-6 xl:grid-cols-3">
    <div class="space-y-6 xl:col-span-2">
        <section class="card" aria-labelledby="personal-heading">
            <h2 id="personal-heading" class="card-title">Personal information</h2>
            <p class="card-subtitle">Fields marked * are required.</p>

            <div class="mt-6 grid gap-5 sm:grid-cols-3">
                <x-form.input name="first_name" label="First name" :value="$member->first_name" required maxlength="100" autocomplete="given-name"/>
                <x-form.input name="middle_name" label="Middle name" :value="$member->middle_name" maxlength="100" autocomplete="additional-name"/>
                <x-form.input name="last_name" label="Last name" :value="$member->last_name" required maxlength="100" autocomplete="family-name"/>
            </div>

            <div class="mt-5 grid gap-5 sm:grid-cols-2">
                <x-form.input name="email" type="email" label="Email address" :value="$member->email" required maxlength="255" autocomplete="email"
                              :hint="$member->exists ? 'Changing the email resets its verification status.' : 'Must be unique. Used for membership notifications.'"/>
                <x-form.input name="phone" type="tel" label="Phone" :value="$member->phone" maxlength="30" autocomplete="tel" placeholder="09XX XXX XXXX"/>
                <x-form.input name="birthdate" type="date" label="Birthdate" :value="$member->birthdate?->format('Y-m-d')" :max="now()->subDay()->format('Y-m-d')"/>
                <x-form.input name="address" label="Address" :value="$member->address" maxlength="255" autocomplete="street-address"/>
            </div>
        </section>

        <x-location-picker :latitude="$member->latitude" :longitude="$member->longitude"
                           :consented="$member->location_consent_at !== null"
                           description="The member's address location. Pick it on the map or type the coordinates; leave both empty to skip."
                           consent-label="The member has agreed that MemberFlow may store these coordinates with their record."/>
    </div>

    <div class="space-y-6">
        <section class="card" aria-labelledby="membership-heading">
            <h2 id="membership-heading" class="card-title">Membership</h2>
            <p class="card-subtitle">Every member belongs to exactly one category and one level.</p>

            <div class="mt-6 space-y-5">
                <x-form.select name="membership_category_id" label="Category" required placeholder="Choose a category"
                               :options="$categories->mapWithKeys(fn ($c) => [$c->id => $c->name.($c->is_active ? '' : ' (inactive)')])->all()"
                               :value="$selectedCategoryId"/>

                <div>
                    <label for="membership_level_id" class="form-label">Level<span class="text-red-600" aria-hidden="true"> *</span></label>
                    @if ($levelLocked)
                        {{-- Level cannot change here; keep the current value so validation still sees it. --}}
                        <input type="hidden" name="membership_level_id" value="{{ $member->membership_level_id }}">
                        <div class="form-input bg-canvas">{{ $member->level->name }}</div>
                        <p class="form-hint">The level of an active, expired or paid membership is changed through a renewal.</p>
                    @else
                        <select id="membership_level_id" name="membership_level_id" required data-level-select="level-preview"
                                @error('membership_level_id') aria-invalid="true" aria-describedby="membership_level_id-error" @enderror
                                @class(['form-input', 'form-input-invalid' => $errors->has('membership_level_id')])>
                            <option value="">Choose a level</option>
                            @foreach ($levels as $level)
                                <option value="{{ $level->id }}" @selected($selectedLevelId === $level->id)
                                        data-name="{{ $level->name }}"
                                        data-fee="{{ $level->formattedFee() }}"
                                        data-validity="{{ $level->validityLabel() }}"
                                        data-benefits='@json($level->benefitList())'>
                                    {{ $level->name }} — {{ $level->formattedFee() }}{{ $level->is_active ? '' : ' (inactive)' }}
                                </option>
                            @endforeach
                        </select>
                    @endif
                    @error('membership_level_id')
                        <p id="membership_level_id-error" class="form-error">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Live preview of the chosen level (server-rendered, updated by JS on change). --}}
                <div id="level-preview" class="rounded-2xl bg-charcoal p-5 text-white" aria-live="polite" @if(! $selectedLevel) hidden @endif>
                    <p class="text-xs font-bold uppercase tracking-wider text-sidebar-text">Selected level</p>
                    <p class="mt-1 text-lg font-bold" data-preview="name">{{ $selectedLevel?->name }}</p>
                    <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
                        <div><dt class="text-sidebar-text">Fee</dt><dd class="text-xl font-bold text-lime" data-preview="fee">{{ $selectedLevel?->formattedFee() }}</dd></div>
                        <div><dt class="text-sidebar-text">Validity</dt><dd class="text-xl font-bold" data-preview="validity">{{ $selectedLevel?->validityLabel() }}</dd></div>
                    </dl>
                    <ul class="mt-3 list-inside list-disc space-y-1 text-sm text-sidebar-text" data-preview="benefits">
                        @foreach ($selectedLevel?->benefitList() ?? [] as $benefit)
                            <li>{{ $benefit }}</li>
                        @endforeach
                    </ul>
                </div>

                @unless ($member->exists)
                    <label class="flex items-start gap-3 rounded-xl border border-line bg-field p-4 text-sm">
                        <input type="hidden" name="create_application" value="0">
                        <input type="checkbox" name="create_application" value="1" class="mt-0.5 size-4 accent-[#5d7f24]"
                            @checked(old('create_application', '1') === '1')>
                        <span>
                            <span class="font-bold">Create a membership application now</span>
                            <span class="mt-1 block text-muted">Opens a <strong>pending</strong> application transaction for the level fee. The membership becomes active only after it is fully paid and approved.</span>
                        </span>
                    </label>
                @endunless
            </div>
        </section>
    </div>
</div>
