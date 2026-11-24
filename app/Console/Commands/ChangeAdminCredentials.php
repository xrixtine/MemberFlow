<?php

namespace App\Console\Commands;

use App\Exceptions\AccountException;
use App\Exceptions\EmailDeliveryException;
use App\Models\User;
use App\Services\AdminCredentialService;
use App\Support\OneTimeCode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Throwable;

/**
 * Change an EXISTING administrator's sign-in email and password.
 *
 *   php artisan memberflow:change-admin-credentials --user=1 --current-email=old@example.com --email=new@example.com
 *   (add --dry-run to only check and show what would change)
 *
 * - A one-time 8-digit code is emailed to the NEW address only; the change happens only
 *   after it is typed back (15 minutes, 3 attempts, single use).
 * - The new password is typed twice at hidden prompts. There is no password option.
 * - Email, password, verification time, remember token, audit rows and the sign-out of
 *   every session of that administrator are applied in one transaction, or not at all.
 * - The user id, role, active status and every related record stay as they are.
 * - Repeating the command after success is refused (--current-email no longer matches).
 */
class ChangeAdminCredentials extends Command
{
    protected $signature = 'memberflow:change-admin-credentials
        {--user= : Id of the existing administrator account}
        {--current-email= : The account\'s current sign-in email (safety check)}
        {--email= : The new sign-in email (receives the one-time code)}
        {--dry-run : Only check and show what would change; nothing is sent or changed}';

    protected $description = 'Change an existing administrator\'s sign-in email (verified by a one-time code) and password (hidden prompt)';

    public function handle(AdminCredentialService $service): int
    {
        $userId = (int) $this->option('user');
        $currentEmail = AdminCredentialService::normalizeEmail($this->option('current-email'));
        $newEmail = AdminCredentialService::normalizeEmail($this->option('email'));

        if ($userId < 1) {
            $this->error('Give the administrator\'s id with --user=<id>.');

            return self::FAILURE;
        }

        $user = User::find($userId);
        $problems = $service->problems($user, $currentEmail, $newEmail);
        if ($problems !== []) {
            $this->error('Nothing was changed and no email was sent:');
            foreach ($problems as $problem) {
                $this->line("  - {$problem}");
            }

            return self::FAILURE;
        }

        $this->line("Administrator #{$user->id} ({$user->name})");
        $this->line("  Sign-in email: {$user->email}  →  {$newEmail}");
        $this->line('  Password: will be replaced (entered at a hidden prompt)');
        $this->line('  Unchanged: id, role (administrator), active status, and all related records');
        $this->line('  Sessions to sign out: '.$service->activeSessionCount($user).' (plus every "remember me" sign-in)');

        if ($this->option('dry-run')) {
            $this->info('Dry run: all checks passed. Nothing was sent or changed.');

            return self::SUCCESS;
        }

        if (! $this->input->isInteractive()) {
            $this->error('This command needs an interactive terminal for the hidden prompts. Nothing was changed.');

            return self::FAILURE;
        }

        $lock = Cache::lock('memberflow:admin-credentials:'.$user->id, (int) config('memberflow.admin_credentials.lock_seconds', 1200));
        if (! $lock->get()) {
            $this->error('Another change for this administrator is running, or one was interrupted less than 20 minutes ago. Try again later. Nothing was changed.');

            return self::FAILURE;
        }

        $password = null;

        try {
            $expectedEmail = $user->email;
            $expectedHash = (string) $user->getAuthPassword();

            $password = (string) $this->secret('New password (hidden; at least 8 characters with upper- and lower-case letters and a number)', false);
            $confirmation = (string) $this->secret('Confirm the new password', false);

            $validator = Validator::make(
                ['password' => $password, 'password_confirmation' => $confirmation],
                ['password' => ['required', 'confirmed', Password::defaults()]],
            );
            if ($validator->fails()) {
                foreach ($validator->errors()->all() as $error) {
                    $this->error($error);   // describes the rule, never the value
                }
                $this->line('Nothing was changed and no email was sent.');

                return self::FAILURE;
            }
            if (Hash::check($password, $expectedHash)) {
                $this->error('Choose a new password that differs from the current one. Nothing was changed and no email was sent.');

                return self::FAILURE;
            }

            [$challenge, $code] = $service->issueCode();
            try {
                $service->sendCode($user, $newEmail, $code);
            } catch (EmailDeliveryException $e) {
                $this->error($e->getMessage());
                $this->line('Nothing was changed. Fix the mail settings and run the command again.');

                return self::FAILURE;
            } finally {
                unset($code);
            }

            $minutes = (int) config('memberflow.admin_credentials.code_ttl_minutes', 15);
            $this->info("A one-time code was sent to {$newEmail}. It expires in {$minutes} minutes. Nothing has changed yet.");

            while (true) {
                $result = $challenge->verify($this->secret('Enter the 8-digit code from that email (hidden)', false));

                if ($result === OneTimeCode::VALID) {
                    break;
                }
                if ($result === OneTimeCode::INCORRECT) {
                    $this->warn('Incorrect code. Attempts left: '.$challenge->attemptsLeft().'.');

                    continue;
                }

                $this->error(match ($result) {
                    OneTimeCode::EXPIRED => 'The code has expired.',
                    OneTimeCode::EXHAUSTED => 'Too many incorrect codes. The code no longer works.',
                    default => 'The code has already been used.',
                }.' Nothing was changed. Run the command again for a new code.');

                return self::FAILURE;
            }

            if ($challenge->isExpired()) {
                $this->error('The code expired before the change could be applied. Nothing was changed.');

                return self::FAILURE;
            }

            $signedOut = $service->apply($user->id, $expectedEmail, $expectedHash, $newEmail, $password);

            $this->info("Done. Administrator #{$user->id} now signs in with {$newEmail} and the new password.");
            $this->line("  {$signedOut} session(s) signed out; remember-me sign-ins no longer work.");
            $this->line('  Recorded in the account history: "Sign-in email changed" and "Password changed".');

            return self::SUCCESS;
        } catch (AccountException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            // Never include the exception message: it could contain query values.
            Log::error('memberflow:change-admin-credentials failed; the transaction was rolled back.', ['user_id' => $user->id, 'exception' => get_class($e)]);
            $this->error('The change could not be completed and was rolled back. Nothing was changed. ('.class_basename($e).')');

            return self::FAILURE;
        } finally {
            $password = null;
            $lock->release();
        }
    }
}
