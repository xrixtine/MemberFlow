<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Services\ApplicantVerificationService;
use Illuminate\Console\Command;

/**
 * DEVELOPMENT-ONLY: print a fresh signed verification link and status link for
 * an applicant, to this terminal only. Used to test verification with
 * MAIL_MAILER=log (where secure links are removed from the mail log).
 * Nothing is sent, stored or logged. Refuses to run outside local/testing.
 */
class VerificationLink extends Command
{
    protected $signature = 'memberflow:verification-link {email : The applicant\'s email address}';

    protected $description = '[Local development only] Print a signed email-verification link and status link for an applicant';

    public function handle(ApplicantVerificationService $verification): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('This command only runs in the local or testing environment.');

            return self::FAILURE;
        }

        $member = Member::where('email', strtolower(trim($this->argument('email'))))->first();

        if (! $member) {
            $this->error('No member was found with that email address.');

            return self::FAILURE;
        }

        if ($member->isEmailVerified()) {
            $this->info('This email address is already verified ('.$member->email_verified_at->format('Y-m-d H:i').').');
        } else {
            $this->line('Verification link (valid for '.$verification->verificationTtlHours().' hours):');
            $this->line($verification->verificationUrl($member));
        }

        $this->line('Application status link (valid for '.$verification->statusTtlDays().' days):');
        $this->line($verification->statusUrl($member));

        return self::SUCCESS;
    }
}
