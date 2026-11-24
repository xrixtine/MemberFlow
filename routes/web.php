<?php

use App\Http\Controllers\AccountEmailController;
use App\Http\Controllers\Auth\InvitationAcceptController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmailDeliveryController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\LevelController;
use App\Http\Controllers\MemberAccountController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\MemberPortalController;
use App\Http\Controllers\MyTaskController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Public\ApplicantVerificationController;
use App\Http\Controllers\Public\ApplicationController;
use App\Http\Controllers\Public\ApplicationStatusController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TaskProgressController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WeatherController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Guest routes
|--------------------------------------------------------------------------
| There is intentionally NO registration route: accounts are created only by
| the initial-admin seeder or by accepting an administrator's invitation.
*/

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
});

/*
| Staff invitation acceptance (account activation). Public, rate-limited,
| token-protected; responses are no-referrer / noindex / no-store. A user who
| is signed in is signed out first (see InvitationAcceptController::show).
*/
Route::middleware(['throttle:invitation', 'no-referrer'])->group(function () {
    Route::get('/invitations/{token}', [InvitationAcceptController::class, 'show'])
        ->where('token', '[A-Za-z0-9]+')->name('invitation.show');
    Route::post('/invitations/{token}', [InvitationAcceptController::class, 'store'])
        ->where('token', '[A-Za-z0-9]+')->name('invitation.accept');
});

/*
| Public membership applications (no login).
|  - /apply: rate-limited, honeypot, minimum fill time, enumeration-safe response.
|  - verify / status: RELATIVE signed, expiring links keyed by the member's ULID and
|    an email hash (validated in the controller to show a friendly invalid page).
|  - Token/status pages are no-referrer / noindex / no-store.
*/
Route::prefix('apply')->name('apply.')->group(function () {
    Route::get('/', [ApplicationController::class, 'create'])->name('create');
    Route::post('/', [ApplicationController::class, 'store'])
        ->middleware(['throttle:apply', 'honeypot:apply.submitted'])->name('store');
    Route::get('/submitted', [ApplicationController::class, 'submitted'])->name('submitted');

    Route::get('/verification/resend', [ApplicantVerificationController::class, 'resendForm'])->name('resend');
    Route::post('/verification/resend', [ApplicantVerificationController::class, 'resend'])
        ->middleware(['throttle:resend-verification', 'honeypot:apply.resend'])->name('resend.send');

    Route::middleware(['throttle:applicant-link', 'no-referrer'])->group(function () {
        Route::get('/verify/{member}/{hash}', [ApplicantVerificationController::class, 'verify'])
            ->where(['member' => '[0-9a-z]{26}', 'hash' => '[0-9a-f]{40}'])->name('verify');
        Route::get('/status/{member}/{hash}', [ApplicationStatusController::class, 'show'])
            ->where(['member' => '[0-9a-z]{26}', 'hash' => '[0-9a-f]{40}'])->name('status');
    });
});

/*
|--------------------------------------------------------------------------
| Authenticated routes (any active user)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'active'])->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/', [DashboardController::class, 'index'])->name('home');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');

    // Current weather (server-side OpenWeather proxy; the API key never reaches the browser)
    Route::get('/weather', [WeatherController::class, 'show'])->middleware('throttle:weather')->name('weather');

    /*
    |----------------------------------------------------------------------
    | Member workspace
    |----------------------------------------------------------------------
    | "active" (above) has already refused deactivated accounts, retired staff
    | roles and member accounts without a valid member record.
    */
    Route::middleware('role:member')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'workspace'])->name('dashboard');

        // My Tasks (TaskPolicy decides view vs. update per task)
        Route::get('/my/tasks', [MyTaskController::class, 'index'])->name('my.tasks.index');
        Route::get('/my/tasks/{task}', [MyTaskController::class, 'show'])->name('my.tasks.show');
        Route::post('/my/tasks/{task}/progress', [TaskProgressController::class, 'store'])->name('my.tasks.progress');
        Route::get('/my/history', [MyTaskController::class, 'history'])->name('my.history');
    });

    /*
    | Member portal: the signed-in member's OWN membership and payments only.
    */
    Route::middleware('role:member')->prefix('my')->name('my.')->group(function () {
        Route::get('/membership', [MemberPortalController::class, 'membership'])->name('membership');
        Route::get('/membership/print', [MemberPortalController::class, 'print'])->name('membership.print');
        Route::get('/payments', [MemberPortalController::class, 'payments'])->name('payments.index');
        Route::get('/payments/{payment}/receipt', [MemberPortalController::class, 'receipt'])->name('payments.receipt');
    });

    /*
    | Sign-in email change confirmation (member must be signed in AND open the link
    | sent to the new address). Relative-signed, expiring, bound to the new email.
    */
    Route::middleware('role:member')->prefix('account/email')->name('account.email.')->group(function () {
        Route::get('/confirm/{user}/{hash}', [AccountEmailController::class, 'confirm'])
            ->where(['user' => '[0-9]+', 'hash' => '[0-9a-f]{40}'])
            ->middleware(['throttle:applicant-link', 'no-referrer'])->name('confirm');
        Route::post('/resend', [AccountEmailController::class, 'resend'])->middleware('throttle:email-actions')->name('resend');
    });

    /*
    |----------------------------------------------------------------------
    | Administrator area
    |----------------------------------------------------------------------
    */
    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'admin'])->name('dashboard');
        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');

        // Members (CRUD + lifecycle actions + printable record)
        Route::post('/members/{member}/send-verification', [MemberController::class, 'sendVerification'])
            ->middleware('throttle:email-actions')->name('members.send-verification');
        Route::patch('/members/{member}/deactivate', [MemberController::class, 'deactivate'])->name('members.deactivate');
        Route::patch('/members/{member}/reactivate', [MemberController::class, 'reactivate'])->name('members.reactivate');
        Route::get('/members/{member}/print', [MemberController::class, 'print'])->name('members.print');
        // Member login accounts: activation invitation and sign-in email change confirmation
        Route::post('/members/{member}/account-invitation', [MemberAccountController::class, 'invite'])
            ->middleware('throttle:email-actions')->name('members.account-invitation');
        Route::post('/members/{member}/account-email-confirmation', [MemberAccountController::class, 'resendEmailConfirmation'])
            ->middleware('throttle:email-actions')->name('members.account-email-confirmation');
        Route::get('/members/{member}/history', [MemberController::class, 'history'])->name('members.history');
        Route::resource('members', MemberController::class);

        // Membership transactions (applications & renewals)
        Route::post('/transactions/expire', [TransactionController::class, 'expire'])->name('transactions.expire');
        Route::patch('/transactions/{transaction}/approve', [TransactionController::class, 'approve'])->name('transactions.approve');
        Route::patch('/transactions/{transaction}/cancel', [TransactionController::class, 'cancel'])->name('transactions.cancel');
        Route::resource('transactions', TransactionController::class)->only(['index', 'create', 'store', 'show']);

        // Payments (no edit/delete: financial records are voided, never changed)
        Route::get('/payments/{payment}/receipt', [PaymentController::class, 'receipt'])->name('payments.receipt');
        Route::patch('/payments/{payment}/void', [PaymentController::class, 'void'])->name('payments.void');
        Route::resource('payments', PaymentController::class)->only(['index', 'create', 'store', 'show']);

        // Tasks (destroy = soft delete to trash; nothing is hard-deleted)
        Route::patch('/tasks/{task}/reassign', [TaskController::class, 'reassign'])->name('tasks.reassign');
        Route::patch('/tasks/{task}/reopen', [TaskController::class, 'reopen'])->name('tasks.reopen');
        Route::patch('/tasks/{task}/archive', [TaskController::class, 'archive'])->name('tasks.archive');
        Route::patch('/tasks/{task}/unarchive', [TaskController::class, 'unarchive'])->name('tasks.unarchive');
        Route::patch('/tasks/{task}/restore', [TaskController::class, 'restore'])->name('tasks.restore')->withTrashed();
        Route::resource('tasks', TaskController::class)->withTrashed(['show']);

        // Membership categories and levels
        Route::resource('categories', CategoryController::class)->except('show');
        Route::resource('levels', LevelController::class)->except('show');

        // Account invitations: member-account activation invitations are issued from the member's
        // page; old staff invitations remain listed as history only (no raw tokens in the admin UI).
        Route::post('/invitations/{invitation}/resend', [InvitationController::class, 'resend'])->name('invitations.resend');
        Route::patch('/invitations/{invitation}/revoke', [InvitationController::class, 'revoke'])->name('invitations.revoke');
        Route::resource('invitations', InvitationController::class)->only(['index', 'show']);

        // User management (no create: member accounts come from activation invitations, administrators
        // from the CLI seeder; no delete: deactivate instead)
        Route::patch('/users/{user}/deactivate', [UserController::class, 'deactivate'])->name('users.deactivate');
        Route::patch('/users/{user}/reactivate', [UserController::class, 'reactivate'])->name('users.reactivate');
        Route::resource('users', UserController::class)->only(['index', 'edit', 'update']);

        // Email log (honest delivery statuses), test email to self, retry of failed deliveries
        Route::get('/email-deliveries', [EmailDeliveryController::class, 'index'])->name('email-deliveries.index');
        Route::post('/email-deliveries/test', [EmailDeliveryController::class, 'sendTest'])
            ->middleware('throttle:email-actions')->name('email-deliveries.test');
        Route::post('/email-deliveries/{delivery}/retry', [EmailDeliveryController::class, 'retry'])
            ->middleware('throttle:email-actions')->name('email-deliveries.retry');
    });
});
