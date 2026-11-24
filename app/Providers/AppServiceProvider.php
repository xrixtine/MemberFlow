<?php

namespace App\Providers;

use App\Models\Member;
use App\Models\MembershipTransaction;
use App\Models\Payment;
use App\Models\Task;
use App\Models\User;
use App\Models\UserInvitation;
use App\Support\DatabaseSafety;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Database safety: migrate:fresh/refresh/reset/rollback and db:wipe are
        // refused unless the active database is the dedicated testing database.
        DatabaseSafety::applyCommandProhibitions();

        // Catch lazy-loading (N+1) mistakes and silently-dropped attributes during development.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        Password::defaults(fn () => Password::min(8)->letters()->mixedCase()->numbers());

        Paginator::defaultView('vendor.pagination.memberflow');

        // Administrator gate (used together with the "role:admin" route middleware).
        Gate::define('admin', fn (User $user) => $user->isAdmin() && $user->is_active);

        // Short, stable names in polymorphic columns (email_deliveries.related_type, …).
        Relation::morphMap([
            'member' => Member::class,
            'transaction' => MembershipTransaction::class,
            'payment' => Payment::class,
            'task' => Task::class,
            'invitation' => UserInvitation::class,
            'user' => User::class,
        ]);

        $this->registerRateLimiters();
    }

    private function registerRateLimiters(): void
    {
        // Public invitation links: limit guessing/abuse per IP.
        RateLimiter::for('invitation', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        // Public application form: 5 per minute and 20 per day per IP.
        RateLimiter::for('apply', fn (Request $request) => [
            Limit::perMinute(5)->by('apply-min:'.$request->ip()),
            Limit::perDay(20)->by('apply-day:'.$request->ip()),
        ]);

        // Public "resend verification": 3 per 10 minutes and 10 per hour per IP
        // (the controller also limits each email address to 3 per hour, silently).
        RateLimiter::for('resend-verification', fn (Request $request) => [
            Limit::perMinutes(10, 3)->by('resend-10m:'.$request->ip()),
            Limit::perHour(10)->by('resend-1h:'.$request->ip()),
        ]);

        // Signed applicant links (verify / status).
        RateLimiter::for('applicant-link', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));

        // Weather JSON for signed-in users (WeatherService also caches and has a global upstream budget).
        RateLimiter::for('weather', fn (Request $request) => Limit::perMinute(30)->by('weather:'.($request->user()?->id ?? $request->ip())));

        // Admin email actions (test email, retry, send verification).
        RateLimiter::for('email-actions', fn (Request $request) => Limit::perMinute(6)->by('email-actions:'.($request->user()?->id ?? $request->ip())));
    }
}
