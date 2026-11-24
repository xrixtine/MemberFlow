<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Creates the single initial administrator from your local .env:
 *   MEMBERFLOW_ADMIN_NAME, MEMBERFLOW_ADMIN_EMAIL, MEMBERFLOW_ADMIN_PASSWORD
 *
 * This is one of only two account-creation paths (the other is accepting an
 * invitation). It refuses to run when any administrator already exists and
 * never prints the password.
 */
class UserSeeder extends Seeder
{
    public const RESULT_CREATED = 'created';

    public const RESULT_ADMIN_EXISTS = 'admin_exists';

    public const RESULT_NOT_CONFIGURED = 'not_configured';

    public const RESULT_INVALID = 'invalid';

    public ?string $result = null;

    public function run(): void
    {
        if (User::admins()->exists()) {
            $this->finish(self::RESULT_ADMIN_EXISTS, 'An administrator already exists — initial admin not created.');

            return;
        }

        $config = config('memberflow.admin');
        $name = trim((string) ($config['name'] ?? '')) ?: 'MemberFlow Administrator';
        $email = trim((string) ($config['email'] ?? ''));
        $password = (string) ($config['password'] ?? '');

        if ($email === '' || $password === '') {
            $this->finish(self::RESULT_NOT_CONFIGURED,
                'MEMBERFLOW_ADMIN_EMAIL / MEMBERFLOW_ADMIN_PASSWORD are not set in .env — initial admin not created.');

            return;
        }

        $validator = Validator::make(
            ['email' => $email, 'password' => $password],
            [
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', Password::min(8)->letters()->mixedCase()->numbers()],
            ],
        );

        if ($validator->fails()) {
            // Validation messages describe the rule, never the value.
            $this->finish(self::RESULT_INVALID, 'Initial admin not created: '.implode(' ', $validator->errors()->all()));

            return;
        }

        $admin = new User(['name' => $name, 'email' => $email, 'password' => $password]);
        $admin->forceFill([
            'role' => UserRole::Admin,
            'is_active' => true,
            'email_verified_at' => now(),
        ])->save();

        // Credentials (email and password) are never echoed to the terminal.
        $this->finish(self::RESULT_CREATED, 'Initial administrator created from the MEMBERFLOW_ADMIN_* settings in .env.');
    }

    private function finish(string $result, string $message): void
    {
        $this->result = $result;

        if ($this->command) {
            $result === self::RESULT_CREATED ? $this->command->info($message) : $this->command->warn($message);
        }
    }
}
