<?php

namespace App\Http\Requests\Auth;

use App\Enums\MemberStatus;
use App\Enums\UserRole;
use App\Models\Member;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public const MAX_ATTEMPTS = 5;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate. Inactive accounts are rejected with the same
     * generic message as a wrong password, so no account details leak.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $credentials = [
            'email' => $this->string('email')->toString(),
            'password' => $this->string('password')->toString(),
            // Only active accounts whose role may sign in (never legacy_staff). A member account
            // must also be linked to an existing, non-deleted, non-deactivated member record.
            // Every refusal uses the same generic message, so nothing about the account leaks.
            fn (Builder $query) => $query->where('is_active', true)
                ->whereIn('role', UserRole::signInValues())
                ->where(fn (Builder $q) => $q->where('role', '<>', UserRole::Member->value)
                    ->orWhereIn('member_id', Member::query()->where('status', '<>', MemberStatus::Inactive->value)->select('id'))),
        ];

        if (! Auth::attempt($credentials, $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey(), 60);

            throw ValidationException::withMessages([
                'email' => 'These credentials do not match an active MemberFlow account.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /** @throws ValidationException */
    private function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => "Too many login attempts. Please try again in {$seconds} seconds.",
        ]);
    }

    private function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
