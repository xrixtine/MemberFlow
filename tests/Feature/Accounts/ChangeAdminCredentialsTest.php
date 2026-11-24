<?php

namespace Tests\Feature\Accounts;

use App\Enums\EmailDeliveryStatus;
use App\Enums\InvitationStatus;
use App\Enums\UserRole;
use App\Exceptions\AccountException;
use App\Models\EmailDelivery;
use App\Models\Member;
use App\Models\Payment;
use App\Models\Task;
use App\Models\User;
use App\Models\UserAccountEvent;
use App\Models\UserInvitation;
use App\Notifications\AdminEmailVerificationCode;
use App\Services\AdminCredentialService;
use App\Services\EmailDeliveryTracker;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * php artisan memberflow:change-admin-credentials
 *
 * Mail is simulated: the "smtp" mailer is pointed at Laravel's in-memory array
 * transport (or a transport that always fails), so nothing leaves this machine.
 */
class ChangeAdminCredentialsTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_EMAIL = 'old.admin@mailbox.dev';

    private const NEW_EMAIL = 'new.admin@mailbox.dev';

    private const OLD_PASSWORD = 'OldPassword1';

    private const NEW_PASSWORD = 'EvalPassword2027';

    private const CODE = '24681357';

    private const Q_PASSWORD = 'New password (hidden; at least 8 characters with upper- and lower-case letters and a number)';

    private const Q_CONFIRM = 'Confirm the new password';

    private const Q_CODE = 'Enter the 8-digit code from that email (hidden)';

    private User $admin;

    private User $memberUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useSimulatedSmtp('array');
        config(['session.driver' => 'database', 'session.connection' => null]);

        $this->admin = User::factory()->admin()->create([
            'name' => 'Evaluation Admin', 'email' => self::OLD_EMAIL, 'password' => self::OLD_PASSWORD,
            'email_verified_at' => '2026-01-01 08:00:00', 'remember_token' => 'old-remember-token',
        ]);

        // History tied to the administrator: a payment it received (with its member and
        // transaction) and a task it created for a member account.
        $payment = Payment::factory()->create(['received_by' => $this->admin->id]);
        $payment->transaction->forceFill(['created_by' => $this->admin->id])->save();
        $this->memberUser = User::factory()->create();
        Task::factory()->create(['created_by' => $this->admin->id, 'assigned_to' => $this->memberUser->id]);

        $this->addSession('admin-session-1', $this->admin->id);
        $this->addSession('admin-session-2', $this->admin->id);
        $this->addSession('member-session', $this->memberUser->id);

        $service = app(AdminCredentialService::class);
        $service->codeGenerator = fn () => self::CODE;
        $this->app->instance(AdminCredentialService::class, $service);
    }

    // ------------------------------------------------------------------ helpers

    /** Point the "smtp" mailer at a local in-memory or always-failing transport. */
    private function useSimulatedSmtp(string $transport): void
    {
        if ($transport === 'failing') {
            Mail::extend('failing', fn () => new class extends AbstractTransport
            {
                protected function doSend(SentMessage $message): void
                {
                    throw new TransportException('Connection could not be established with host "smtp.simulated.invalid:587"');
                }

                public function __toString(): string
                {
                    return 'failing://';
                }
            });
        }

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp' => ['transport' => $transport, 'username' => 'simulated-user', 'password' => 'simulated-not-a-secret'],
        ]);
        app('mail.manager')->purge('smtp');
    }

    private function addSession(string $id, int $userId): void
    {
        DB::table('sessions')->insert([
            'id' => $id, 'user_id' => $userId, 'ip_address' => '127.0.0.1', 'user_agent' => 'test',
            'payload' => base64_encode(serialize([])), 'last_activity' => time(),
        ]);
    }

    /** @return list<Email> */
    private function sentEmails(): array
    {
        return app('mail.manager')->mailer('smtp')->getSymfonyTransport()->messages()
            ->map(fn ($sent) => $sent->getOriginalMessage())->values()->all();
    }

    /** Every business table, row by row, plus every other user. */
    private function businessFingerprint(): array
    {
        $tables = ['members', 'membership_categories', 'membership_levels', 'membership_transactions', 'payments',
            'tasks', 'task_assignments', 'task_progress_updates', 'user_invitations'];
        $data = [];
        foreach ($tables as $table) {
            $data[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        }
        $data['other_users'] = DB::table('users')->where('id', '!=', $this->admin->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        return $data;
    }

    private function adminRow(): array
    {
        return (array) DB::table('users')->where('id', $this->admin->id)->first();
    }

    private function runCommand(array $options = []): PendingCommand
    {
        return $this->artisan('memberflow:change-admin-credentials', array_merge([
            '--user' => $this->admin->id, '--current-email' => self::OLD_EMAIL, '--email' => self::NEW_EMAIL,
        ], $options));
    }

    private function assertNothingChanged(array $fingerprintBefore, array $adminBefore): void
    {
        $this->assertSame($adminBefore, $this->adminRow(), 'administrator row changed');
        $this->assertSame($fingerprintBefore, $this->businessFingerprint(), 'business records changed');
        $this->assertSame(0, UserAccountEvent::count(), 'audit rows written');
        $this->assertSame(2, DB::table('sessions')->where('user_id', $this->admin->id)->count(), 'sessions removed');
        $this->assertSame(1, User::admins()->count());
    }

    // ------------------------------------------------------------------ success

    public function test_it_changes_email_and_password_after_the_code_is_confirmed(): void
    {
        $before = $this->businessFingerprint();
        $adminBefore = $this->adminRow();

        $this->travelTo(now()->setDate(2026, 11, 24)->setTime(16, 30));

        $this->runCommand()
            ->expectsQuestion(self::Q_PASSWORD, self::NEW_PASSWORD)
            ->expectsQuestion(self::Q_CONFIRM, self::NEW_PASSWORD)
            ->expectsOutputToContain('A one-time code was sent to '.self::NEW_EMAIL)
            ->expectsQuestion(self::Q_CODE, self::CODE)
            ->expectsOutputToContain('2 session(s) signed out')
            ->doesntExpectOutputToContain(self::NEW_PASSWORD)
            ->doesntExpectOutputToContain(self::CODE)
            ->assertExitCode(0);

        $admin = $this->admin->fresh();

        // Same account, same role and status; new credentials.
        $this->assertSame($adminBefore['id'], $admin->id);
        $this->assertSame(UserRole::Admin, $admin->role);
        $this->assertTrue($admin->is_active);
        $this->assertNull($admin->member_id);
        $this->assertSame($adminBefore['name'], $admin->name);
        $this->assertSame($adminBefore['created_at'], $this->adminRow()['created_at']);
        $this->assertSame(self::NEW_EMAIL, $admin->email);
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $admin->password));
        $this->assertFalse(Hash::check(self::OLD_PASSWORD, $admin->password));
        $this->assertSame('2026-11-24 16:30:00', $admin->email_verified_at->format('Y-m-d H:i:s'));
        $this->assertNotSame('old-remember-token', $admin->remember_token);
        $this->assertSame(60, strlen((string) $admin->remember_token));
        $this->assertSame(1, User::admins()->count());

        // Every session of the administrator is gone; other users keep theirs.
        $this->assertSame(0, DB::table('sessions')->where('user_id', $admin->id)->count());
        $this->assertSame(1, DB::table('sessions')->where('id', 'member-session')->count());

        // History and business records untouched.
        $this->assertSame($before, $this->businessFingerprint());
        $this->assertSame(1, Payment::where('received_by', $admin->id)->count());
        $this->assertSame(1, Task::where('created_by', $admin->id)->count());

        // Audit trail: exactly two rows, no password or code in them.
        $events = UserAccountEvent::where('user_id', $admin->id)->orderBy('id')->get();
        $this->assertSame([UserAccountEvent::EMAIL_CHANGED, UserAccountEvent::PASSWORD_CHANGED], $events->pluck('type')->all());
        $this->assertSame(self::OLD_EMAIL, $events[0]->old_value);
        $this->assertSame(self::NEW_EMAIL, $events[0]->new_value);
        $this->assertNull($events[1]->old_value);
        $this->assertNull($events[1]->new_value);
        $this->assertStringContainsString('2 session(s) signed out', $events[1]->note);
        $this->assertSame('Password changed', $events[1]->label());
        $audit = json_encode(UserAccountEvent::all()->toArray());
        foreach ([self::NEW_PASSWORD, self::OLD_PASSWORD, self::CODE, $admin->password] as $secret) {
            $this->assertStringNotContainsString($secret, $audit);
        }
    }

    public function test_the_code_goes_only_to_the_new_address_and_is_never_stored(): void
    {
        $this->runCommand()
            ->expectsQuestion(self::Q_PASSWORD, self::NEW_PASSWORD)
            ->expectsQuestion(self::Q_CONFIRM, self::NEW_PASSWORD)
            ->expectsQuestion(self::Q_CODE, self::CODE)
            ->assertExitCode(0);

        $emails = $this->sentEmails();
        $this->assertCount(1, $emails);
        $this->assertSame([self::NEW_EMAIL], array_map(fn ($a) => $a->getAddress(), $emails[0]->getTo()));
        $this->assertEmpty($emails[0]->getCc());
        $this->assertEmpty($emails[0]->getBcc());
        $this->assertStringNotContainsString(self::CODE, (string) $emails[0]->getSubject());
        $this->assertStringContainsString(self::CODE, (string) $emails[0]->getTextBody());

        $delivery = EmailDelivery::sole();
        $this->assertSame('AdminEmailVerificationCode', $delivery->notification);
        $this->assertSame(self::NEW_EMAIL, $delivery->recipient_email);
        $this->assertSame(EmailDeliveryStatus::Sent, $delivery->status);
        $this->assertNull($delivery->context);

        // The code is nowhere in the database (deliveries, jobs, audit, cache).
        foreach (['email_deliveries', 'jobs', 'failed_jobs', 'user_account_events', 'cache'] as $table) {
            $this->assertStringNotContainsString(self::CODE, json_encode(DB::table($table)->get()), "code found in {$table}");
        }
        // It cannot be re-sent from the Email log.
        $this->assertNull(AdminEmailVerificationCode::rebuild($delivery));
    }

    public function test_the_code_is_sent_immediately_even_when_a_queue_worker_is_configured(): void
    {
        config(['queue.default' => 'database']);

        $this->runCommand()
            ->expectsQuestion(self::Q_PASSWORD, self::NEW_PASSWORD)
            ->expectsQuestion(self::Q_CONFIRM, self::NEW_PASSWORD)
            ->expectsQuestion(self::Q_CODE, self::CODE)
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertCount(1, $this->sentEmails());
    }

    public function test_after_the_change_only_the_new_credentials_sign_in_and_the_dashboard_opens(): void
    {
        $this->runCommand()
            ->expectsQuestion(self::Q_PASSWORD, self::NEW_PASSWORD)
            ->expectsQuestion(self::Q_CONFIRM, self::NEW_PASSWORD)
            ->expectsQuestion(self::Q_CODE, self::CODE)
            ->assertExitCode(0);

        $this->post(route('login'), ['email' => self::OLD_EMAIL, 'password' => self::OLD_PASSWORD])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post(route('login'), ['email' => self::OLD_EMAIL, 'password' => self::NEW_PASSWORD])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post(route('login'), ['email' => self::NEW_EMAIL, 'password' => self::OLD_PASSWORD])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post(route('login'), ['email' => self::NEW_EMAIL, 'password' => self::NEW_PASSWORD])->assertRedirect();
        $this->assertAuthenticatedAs($this->admin->fresh());
        $this->get(route('admin.dashboard'))->assertOk();
        $this->get(route('admin.users.edit', $this->admin))->assertOk()->assertSee('Sign-in email changed')->assertSee('Password changed');
    }

    public function test_an_old_session_cookie_no_longer_signs_in(): void
    {
        // A real signed-in browser session, stored in the database.
        $this->post(route('login'), ['email' => self::OLD_EMAIL, 'password' => self::OLD_PASSWORD]);
        $this->assertAuthenticatedAs($this->admin);
        // The real login session, not the two fixture rows added in setUp() (they can share the same second).
        $sessionId = DB::table('sessions')->where('user_id', $this->admin->id)
            ->whereNotIn('id', ['admin-session-1', 'admin-session-2'])->value('id');
        $this->assertNotNull($sessionId);
        $this->assertSame(3, DB::table('sessions')->where('user_id', $this->admin->id)->count());

        // Control: in a fresh request state the cookie alone signs in (so the check below is meaningful).
        $this->freshRequestState();
        $this->withCookie(config('session.cookie'), $sessionId)->get(route('admin.dashboard'))->assertOk();

        $this->runCommand()
            ->expectsQuestion(self::Q_PASSWORD, self::NEW_PASSWORD)
            ->expectsQuestion(self::Q_CONFIRM, self::NEW_PASSWORD)
            ->expectsQuestion(self::Q_CODE, self::CODE)
            ->expectsOutputToContain('3 session(s) signed out')
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('sessions')->where('id', $sessionId)->count());

        // Replay the old cookie in a fresh request state (as a new browser request would be).
        $this->freshRequestState();
        $this->withCookie(config('session.cookie'), $sessionId)
            ->get(route('admin.dashboard'))
            ->assertRedirect(route('login'));
        $this->assertGuest();
    }

    /**
     * The test application is shared between requests, so its session store and guard
     * would remember the last login in memory. Each real request starts without them.
     */
    private function freshRequestState(): void
    {
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->app['auth']->forgetGuards();
    }

    public function test_a_remember_me_cookie_no_longer_signs_in(): void
    {
        $recaller = $this->admin->id.'|old-remember-token|'.$this->admin->password;

        // Control: before the change the remember-me cookie alone signs in.
        $this->freshRequestState();
        $this->withCookie(auth()->guard('web')->getRecallerName(), $recaller)->get(route('admin.dashboard'))->assertOk();
        $this->post(route('logout'));
        $this->admin->forceFill(['remember_token' => 'old-remember-token'])->save();   // logout rotates it; restore the old value
        $this->freshRequestState();

        $this->runCommand()
            ->expectsQuestion(self::Q_PASSWORD, self::NEW_PASSWORD)
            ->expectsQuestion(self::Q_CONFIRM, self::NEW_PASSWORD)
            ->expectsQuestion(self::Q_CODE, self::CODE)
            ->assertExitCode(0);

        $this->freshRequestState();
        $this->withCookie(auth()->guard('web')->getRecallerName(), $recaller)
            ->get(route('admin.dashboard'))
            ->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_dry_run_changes_nothing_and_sends_nothing(): void
    {
        $before = $this->businessFingerprint();
        $adminBefore = $this->adminRow();

        $this->runCommand(['--dry-run' => true])
            ->expectsOutputToContain(self::OLD_EMAIL.'  →  '.self::NEW_EMAIL)
            ->expectsOutputToContain('Sessions to sign out: 2')
            ->expectsOutputToContain('Dry run: all checks passed')
            ->assertExitCode(0);

        $this->assertNothingChanged($before, $adminBefore);
        $this->assertSame(0, EmailDelivery::count());
        $this->assertCount(0, $this->sentEmails());
    }

    public function test_the_password_cannot_be_given_as_an_option(): void
    {
        $definition = Artisan::all()['memberflow:change-admin-credentials']->getDefinition();

        foreach (array_keys($definition->getOptions()) as $option) {
            $this->assertDoesNotMatchRegularExpression('/pass|secret|code|token/i', $option);
        }
        $this->assertSame([], $definition->getArguments());
        foreach (['user', 'current-email', 'email', 'dry-run'] as $option) {
            $this->assertTrue($definition->hasOption($option));
        }
    }

    // ------------------------------------------------------------------ refusals before anything is sent

    /** @return array<string, array{0: \Closure, 1: array<string, string>, 2: string}> */
    public static function refusals(): array
    {
        return [
            'unknown user' => [fn () => null, ['--user' => '999'], 'No user account has that id'],
            'member account' => [fn (self $t) => null, ['--user' => 'member'], 'is not an administrator'],
            'wrong current email' => [fn () => null, ['--current-email' => 'someone.else@mailbox.dev'], 'does not match'],
            'same email' => [fn () => null, ['--email' => self::OLD_EMAIL], 'already this administrator'],
            'invalid email' => [fn () => null, ['--email' => 'not-an-email'], 'not a valid email'],
            'fictional domain' => [fn () => null, ['--email' => 'admin@example.com'], 'reserved/fictional'],
            'email used by another user (any case)' => [fn () => User::factory()->create(['email' => 'new.admin@mailbox.dev']), ['--email' => 'NEW.Admin@MailBox.dev'], 'already used by another MemberFlow account'],
            'email used by a member record' => [fn () => Member::factory()->create(['email' => self::NEW_EMAIL]), [], 'belongs to a membership record'],
            'email has a pending invitation' => [fn (self $t) => (new UserInvitation)->forceFill([
                'email' => self::NEW_EMAIL, 'name' => 'Pending', 'role' => 'member', 'member_id' => Member::factory()->create()->id,
                'token_hash' => hash('sha256', Str::random(64)), 'status' => InvitationStatus::Pending, 'expires_at' => now()->addDay(),
            ])->save(), [], 'pending invitation'],
            'log mail mode' => [fn () => config(['mail.default' => 'log']), [], 'MAIL_MAILER is not "smtp"'],
            'missing smtp credentials' => [fn () => config(['mail.mailers.smtp.password' => '']), [], 'MAIL_USERNAME or MAIL_PASSWORD is empty'],
            'sessions not in the database' => [fn () => config(['session.driver' => 'file']), [], 'existing sessions could not be signed out safely'],
            'sessions on another connection' => [fn () => config(['session.connection' => 'sqlite']), [], 'existing sessions could not be signed out safely'],
        ];
    }

    #[DataProvider('refusals')]
    public function test_it_refuses_unsafe_requests_before_sending_anything(\Closure $arrange, array $options, string $message): void
    {
        $arrange($this);
        if (($options['--user'] ?? null) === 'member') {
            $options['--user'] = $this->memberUser->id;
        }
        $before = $this->businessFingerprint();
        $adminBefore = $this->adminRow();

        $this->runCommand($options)
            ->expectsOutputToContain('Nothing was changed and no email was sent')
            ->expectsOutputToContain($message)
            ->assertExitCode(1);

        $this->assertNothingChanged($before, $adminBefore);
        $this->assertSame(0, EmailDelivery::count());
        $this->assertCount(0, $this->sentEmails());
    }

    public function test_it_refuses_a_deactivated_administrator(): void
    {
        $other = User::factory()->admin()->inactive()->create(['email' => 'inactive.admin@mailbox.dev']);

        $this->runCommand(['--user' => $other->id, '--current-email' => 'inactive.admin@mailbox.dev'])
            ->expectsOutputToContain('is deactivated')
            ->assertExitCode(1);

        $this->assertSame('inactive.admin@mailbox.dev', $other->fresh()->email);
        $this->assertCount(0, $this->sentEmails());
    }

    public function test_it_refuses_without_an_interactive_terminal(): void
    {
        $adminBefore = $this->adminRow();

        $this->runCommand(['--no-interaction' => true])
            ->expectsOutputToContain('needs an interactive terminal')
            ->assertExitCode(1);

        $this->assertSame($adminBefore, $this->adminRow());
        $this->assertCount(0, $this->sentEmails());
    }

    public function test_running_it_again_after_success_is_refused(): void
    {
        $this->runCommand()
            ->expectsQuestion(self::Q_PASSWORD, self::NEW_PASSWORD)
            ->expectsQuestion(self::Q_CONFIRM, self::NEW_PASSWORD)
            ->expectsQuestion(self::Q_CODE, self::CODE)
            ->assertExitCode(0);
        $after = $this->adminRow();

        $this->runCommand()->expectsOutputToContain('does not match')->assertExitCode(1);

        $this->assertSame($after, $this->adminRow());
        $this->assertSame(2, UserAccountEvent::count());
        $this->assertCount(1, $this->sentEmails());
    }

    // ------------------------------------------------------------------ password prompts

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function badPasswords(): array
    {
        return [
            'confirmation differs' => ['EvalPassword2027', 'EvalPassword2028', 'confirmation does not match'],
            'too short' => ['Ab1', 'Ab1', 'at least 8'],
            'no upper-case letter' => ['password12', 'password12', 'uppercase and one lowercase'],
            'empty' => ['', '', 'required'],
            'same as current' => [self::OLD_PASSWORD, self::OLD_PASSWORD, 'differs from the current one'],
        ];
    }

    #[DataProvider('badPasswords')]
    public function test_a_bad_password_stops_before_any_email_is_sent(string $password, string $confirmation, string $message): void
    {
        $before = $this->businessFingerprint();
        $adminBefore = $this->adminRow();

        $this->runCommand()
            ->expectsQuestion(self::Q_PASSWORD, $password)
            ->expectsQuestion(self::Q_CONFIRM, $confirmation)
            ->expectsOutputToContain($message)
            ->assertExitCode(1);

        $this->assertNothingChanged($before, $adminBefore);
        $this->assertCount(0, $this->sentEmails());
        $this->assertSame(0, EmailDelivery::count());
    }

    // ------------------------------------------------------------------ code problems

    public function test_three_incorrect_codes_abort_without_changes(): void
    {
        $before = $this->businessFingerprint();
        $adminBefore = $this->adminRow();

        $this->runCommand()
            ->expectsQuestion(self::Q_PASSWORD, self::NEW_PASSWORD)
            ->expectsQuestion(self::Q_CONFIRM, self::NEW_PASSWORD)
            ->expectsQuestion(self::Q_CODE, '11111111')
            ->expectsOutputToContain('Incorrect code. Attempts left: 2.')
            ->expectsQuestion(self::Q_CODE, '22222222')
            ->expectsOutputToContain('Attempts left: 1.')
            ->expectsQuestion(self::Q_CODE, '33333333')
            ->expectsOutputToContain('Too many incorrect codes')
            ->assertExitCode(1);

        $this->assertNothingChanged($before, $adminBefore);
        $this->assertCount(1, $this->sentEmails());   // the code was sent once, then abandoned
    }

    public function test_a_wrong_code_followed_by_the_right_one_succeeds(): void
    {
        $this->runCommand()
            ->expectsQuestion(self::Q_PASSWORD, self::NEW_PASSWORD)
            ->expectsQuestion(self::Q_CONFIRM, self::NEW_PASSWORD)
            ->expectsQuestion(self::Q_CODE, '00000000')
            ->expectsQuestion(self::Q_CODE, self::CODE)
            ->assertExitCode(0);

        $this->assertSame(self::NEW_EMAIL, $this->admin->fresh()->email);
    }

    public function test_an_expired_code_is_refused(): void
    {
        config(['memberflow.admin_credentials.code_ttl_minutes' => 0]);   // expires the moment it is sent
        $before = $this->businessFingerprint();
        $adminBefore = $this->adminRow();

        $this->runCommand()
            ->expectsQuestion(self::Q_PASSWORD, self::NEW_PASSWORD)
            ->expectsQuestion(self::Q_CONFIRM, self::NEW_PASSWORD)
            ->expectsQuestion(self::Q_CODE, self::CODE)
            ->expectsOutputToContain('The code has expired')
            ->assertExitCode(1);

        $this->assertNothingChanged($before, $adminBefore);
    }

    // ------------------------------------------------------------------ SMTP failure

    public function test_an_smtp_failure_stops_before_any_change(): void
    {
        $this->useSimulatedSmtp('failing');
        $before = $this->businessFingerprint();
        $adminBefore = $this->adminRow();

        $this->runCommand()
            ->expectsQuestion(self::Q_PASSWORD, self::NEW_PASSWORD)
            ->expectsQuestion(self::Q_CONFIRM, self::NEW_PASSWORD)
            ->expectsOutputToContain('The email could not be sent')
            ->expectsOutputToContain('Nothing was changed')
            ->doesntExpectOutputToContain('simulated-not-a-secret')
            ->assertExitCode(1);

        $this->assertNothingChanged($before, $adminBefore);
        $delivery = EmailDelivery::sole();
        $this->assertSame(EmailDeliveryStatus::Failed, $delivery->status);
        $this->assertSame(self::NEW_EMAIL, $delivery->recipient_email);
    }

    // ------------------------------------------------------------------ atomicity and concurrency

    public function test_a_session_invalidation_failure_rolls_everything_back(): void
    {
        $service = Mockery::mock(AdminCredentialService::class, [app(EmailDeliveryTracker::class)])->makePartial();
        $service->codeGenerator = fn () => self::CODE;
        $service->shouldReceive('invalidateSessions')->once()->andThrow(new \RuntimeException('simulated session store failure'));
        $this->app->instance(AdminCredentialService::class, $service);

        $before = $this->businessFingerprint();
        $adminBefore = $this->adminRow();

        $this->runCommand()
            ->expectsQuestion(self::Q_PASSWORD, self::NEW_PASSWORD)
            ->expectsQuestion(self::Q_CONFIRM, self::NEW_PASSWORD)
            ->expectsQuestion(self::Q_CODE, self::CODE)
            ->expectsOutputToContain('rolled back')
            ->doesntExpectOutputToContain('simulated session store failure')
            ->assertExitCode(1);

        // Email, password, verification time and remember token all unchanged; no audit rows.
        $this->assertNothingChanged($before, $adminBefore);
        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $this->admin->fresh()->password));

        // The lock was released, so the command can be run again.
        $this->assertTrue(Cache::lock('memberflow:admin-credentials:'.$this->admin->id, 5)->get());
    }

    public function test_a_second_concurrent_run_is_refused(): void
    {
        $held = Cache::lock('memberflow:admin-credentials:'.$this->admin->id, 60);
        $this->assertTrue($held->get());
        $adminBefore = $this->adminRow();

        $this->runCommand()->expectsOutputToContain('Another change for this administrator is running')->assertExitCode(1);

        $this->assertSame($adminBefore, $this->adminRow());
        $this->assertCount(0, $this->sentEmails());
        $held->release();
    }

    public function test_apply_refuses_when_the_account_changed_while_the_command_was_running(): void
    {
        $service = app(AdminCredentialService::class);
        $hash = $this->admin->getAuthPassword();
        $adminBefore = $this->adminRow();

        // The email or password changed after the command read them.
        foreach ([['changed@mailbox.dev', $hash], [self::OLD_EMAIL, Hash::make('Different1')]] as [$expectedEmail, $expectedHash]) {
            try {
                $service->apply($this->admin->id, $expectedEmail, $expectedHash, self::NEW_EMAIL, self::NEW_PASSWORD);
                $this->fail('apply() should have refused');
            } catch (AccountException $e) {
                $this->assertStringContainsString('changed by someone else', $e->getMessage());
            }
        }

        $this->assertSame($adminBefore, $this->adminRow());
        $this->assertSame(0, UserAccountEvent::count());
    }

    public function test_apply_refuses_when_the_new_email_was_taken_meanwhile(): void
    {
        User::factory()->create(['email' => self::NEW_EMAIL]);
        $adminBefore = $this->adminRow();

        $this->expectException(AccountException::class);
        try {
            app(AdminCredentialService::class)->apply($this->admin->id, self::OLD_EMAIL, $this->admin->getAuthPassword(), self::NEW_EMAIL, self::NEW_PASSWORD);
        } finally {
            $this->assertSame($adminBefore, $this->adminRow());
            $this->assertSame(0, UserAccountEvent::count());
        }
    }

    public function test_apply_refuses_an_account_that_is_no_longer_an_active_administrator(): void
    {
        $this->admin->forceFill(['is_active' => false])->save();
        $adminBefore = $this->adminRow();

        $this->expectException(AccountException::class);
        try {
            app(AdminCredentialService::class)->apply($this->admin->id, self::OLD_EMAIL, $this->admin->getAuthPassword(), self::NEW_EMAIL, self::NEW_PASSWORD);
        } finally {
            $this->assertSame($adminBefore, $this->adminRow());
        }
    }

    // ------------------------------------------------------------------ initial-admin setting

    public function test_the_initial_admin_env_setting_cannot_recreate_or_change_an_administrator(): void
    {
        config(['memberflow.admin' => ['name' => 'Seed Admin', 'email' => 'seed.admin@mailbox.dev', 'password' => 'SeedPassword1']]);
        $adminBefore = $this->adminRow();

        $this->seed(UserSeeder::class);

        $this->assertSame(1, User::admins()->count());
        $this->assertSame($adminBefore, $this->adminRow());
        $this->assertSame(0, User::where('email', 'seed.admin@mailbox.dev')->count());
    }
}
