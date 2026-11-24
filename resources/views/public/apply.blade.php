@php
    $selectedLevelId = (int) old('membership_level_id');
    $selectedLevel = $levels->firstWhere('id', $selectedLevelId);
@endphp
<x-layouts.guest title="Apply for membership" wide>
    <p class="eyebrow mb-2">Online application</p>
    <h1 class="text-3xl font-bold">Apply for membership</h1>
    <p class="mt-2 text-sm text-muted">
        Fill in the form and we will email you a link to confirm your address. Your membership becomes active only after
        the application is fully paid and approved by the membership office.
    </p>

    @if ($categories->isEmpty() || $levels->isEmpty())
        <div class="alert-warning mt-8" role="status">
            <x-icon name="alert" class="mt-0.5 size-4 shrink-0"/>
            <p>Online applications are temporarily unavailable. Please visit the membership office.</p>
        </div>
    @else
        <form method="POST" action="{{ route('apply.store') }}" class="mt-8 space-y-8" novalidate>
            @csrf
            <input type="hidden" name="form_token" value="{{ $formToken }}">
            {{-- Honeypot: hidden from people and assistive technology; bots tend to fill it in. --}}
            <div class="absolute -left-[9999px] h-px w-px overflow-hidden" aria-hidden="true">
                <label for="website">Leave this field empty</label>
                <input type="text" id="website" name="website" tabindex="-1" autocomplete="off" value="">
            </div>

            @error('form_token')
                <div class="alert-error" role="alert">
                    <x-icon name="alert" class="mt-0.5 size-4 shrink-0"/>
                    <p>{{ $message }}</p>
                </div>
            @enderror

            <fieldset>
                <legend class="card-title">Personal information</legend>
                <p class="card-subtitle">Fields marked * are required.</p>
                <div class="mt-5 grid gap-5 sm:grid-cols-3">
                    <x-form.input name="first_name" label="First name" required maxlength="100" autocomplete="given-name"/>
                    <x-form.input name="middle_name" label="Middle name" maxlength="100" autocomplete="additional-name"/>
                    <x-form.input name="last_name" label="Last name" required maxlength="100" autocomplete="family-name"/>
                </div>
                <div class="mt-5 grid gap-5 sm:grid-cols-2">
                    <x-form.input name="email" type="email" label="Email address" required maxlength="255" autocomplete="email"
                                  hint="We send a verification link to this address."/>
                    <x-form.input name="phone" type="tel" label="Phone" maxlength="30" autocomplete="tel" placeholder="09XX XXX XXXX"/>
                    <x-form.input name="birthdate" type="date" label="Birthdate" :max="now()->subDay()->format('Y-m-d')" autocomplete="bday"/>
                    <x-form.input name="address" label="Address" maxlength="255" autocomplete="street-address"/>
                </div>
            </fieldset>

            <fieldset>
                <legend class="card-title">Membership</legend>
                <div class="mt-5 grid gap-5 sm:grid-cols-2">
                    <x-form.select name="membership_category_id" label="Category" required placeholder="Choose a category"
                                   :options="$categories->pluck('name', 'id')->all()"/>
                    <div>
                        <label for="membership_level_id" class="form-label">Level<span class="text-red-600" aria-hidden="true"> *</span></label>
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
                                    {{ $level->name }} — {{ $level->formattedFee() }}
                                </option>
                            @endforeach
                        </select>
                        @error('membership_level_id')
                            <p id="membership_level_id-error" class="form-error">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div id="level-preview" class="mt-5 rounded-2xl bg-charcoal p-5 text-white" aria-live="polite" @if(! $selectedLevel) hidden @endif>
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
            </fieldset>

            <x-location-picker class="!p-0 !shadow-none !border-0 !bg-transparent"
                               description="You may share your home location to help the office plan visits. Pick it on the map or type the coordinates; leave both empty to skip."
                               consent-label="I agree that MemberFlow may store these coordinates with my membership record. They are visible only to the membership office."/>

            <section aria-labelledby="privacy-heading" class="rounded-2xl border border-line bg-white p-5 text-sm">
                <h2 id="privacy-heading" class="font-bold">Privacy notice</h2>
                <ul class="mt-2 list-inside list-disc space-y-1 text-muted">
                    <li>Your details are used only to process your membership and contact you about it.</li>
                    <li>Only the membership office can see your address, phone, birthdate and location.</li>
                    <li>Submitting this form does not activate a membership or charge anything. Payment is made at the office.</li>
                    <li>If an application already exists for your email, no new one is created; we email that address instead.</li>
                </ul>
                <label class="mt-4 flex items-start gap-3">
                    <input type="checkbox" name="privacy_consent" value="1" class="mt-0.5 size-4 accent-[#5d7f24]" @checked(old('privacy_consent')) required
                           @error('privacy_consent') aria-invalid="true" aria-describedby="privacy_consent-error" @enderror>
                    <span>I have read the privacy notice and agree to my details being processed for my membership application.<span class="text-red-600" aria-hidden="true"> *</span></span>
                </label>
                @error('privacy_consent')
                    <p id="privacy_consent-error" class="form-error">{{ $message }}</p>
                @enderror
            </section>

            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <a href="{{ route('apply.resend') }}" class="text-sm font-bold text-muted hover:text-ink hover:underline">Already applied? Resend the verification email</a>
                <button type="submit" class="btn-primary py-3" data-loading="Submitting…">Submit application</button>
            </div>
        </form>
    @endif
</x-layouts.guest>
