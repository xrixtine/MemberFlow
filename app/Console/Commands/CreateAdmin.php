<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use App\Models\UserAccountEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * The only way to add an administrator after the initial seed (decided in the
 * revision plan; the staff→admin promotion was retired with the staff role).
 *
 *   php artisan memberflow:create-admin --name="Jane Admin" --email=jane@example.com
 *
 * The password is asked for at a hidden prompt (twice); it is never accepted as an
 * option, never printed and never logged. The creation is recorded in the audit trail.
 */
class CreateAdmin extends Command
{
    protected $signature = 'memberflow:create-admin
        {--name= : Full name of the new administrator}
        {--email= : Sign-in email address of the new administrator}';

    protected $description = 'Create an administrator account (password entered at a hidden prompt)';

    public function handle(): int
    {
        $name = trim((string) ($this->option('name') ?: $this->ask('Full name')));
        $email = strtolower(trim((string) ($this->option('email') ?: $this->ask('Email address'))));
        $password = (string) $this->secret('Password (hidden; at least 8 characters with upper- and lower-case letters and a number)');
        $confirmation = (string) $this->secret('Confirm password');

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password, 'password_confirmation' => $confirmation],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
                'password' => ['required', 'confirmed', Password::min(8)->letters()->mixedCase()->numbers()],
            ],
        );
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = DB::transaction(function () use ($name, $email, $password) {
            $user = new User(['name' => $name, 'email' => $email, 'password' => $password]);
            $user->forceFill(['role' => UserRole::Admin, 'is_active' => true, 'email_verified_at' => now()])->save();

            UserAccountEvent::create([
                'user_id' => $user->id, 'actor_id' => null, 'type' => UserAccountEvent::ADMIN_CREATED,
                'new_value' => UserRole::Admin->value, 'note' => 'Created with php artisan memberflow:create-admin.',
            ]);

            return $user;
        });

        $this->info("Administrator #{$user->id} ({$user->name}) created. They can sign in now.");

        return self::SUCCESS;
    }
}
