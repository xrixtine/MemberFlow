<?php

namespace App\Notifications;

use App\Models\EmailDelivery;
use App\Models\Member;
use App\Services\ApplicantVerificationService;
use App\Support\Money;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * "Confirm your email" for an applicant. It also acknowledges that the online
 * application was received. The signed links are generated when the email is
 * actually sent (so the 48-hour window starts then) and are never stored.
 */
class VerifyApplicantEmail extends TrackedNotification
{
    public function __construct(public Member $member)
    {
        parent::__construct();
    }

    protected function buildMail(object $notifiable): MailMessage
    {
        $member = $this->member->loadMissing(['level', 'category', 'latestTransaction']);
        $links = app(ApplicantVerificationService::class);
        $txn = $member->latestTransaction;
        $hours = $links->verificationTtlHours();

        $mail = (new MailMessage)
            ->subject('Confirm your email address — '.config('app.name').' membership application')
            ->greeting("Hello {$member->first_name},")
            ->line('Thank you for applying for membership. Please confirm that this email address belongs to you.')
            ->action('Verify my email address', $links->verificationUrl($member))
            ->line("This verification link expires in {$hours} hours.");

        if ($txn) {
            $mail->line("Application reference: {$txn->reference_no}")
                ->line("Level: {$member->level->name} ({$member->category->name}) — amount due ".Money::formatDecimal($txn->amount).'.');
        }

        return $mail
            ->line('Verifying your email does not approve your membership or record any payment. An administrator approves the application after it is fully paid.')
            ->line('You can check your application at any time with this private link (valid for '.$links->statusTtlDays().' days): '.$links->statusUrl($member))
            ->line('If you did not apply, you can ignore this email.');
    }

    public static function rebuild(EmailDelivery $delivery): ?self
    {
        $member = $delivery->related_type === 'member' ? Member::find($delivery->related_id) : null;

        return $member && ! $member->isEmailVerified() ? new self($member) : null;
    }

    public static function retryHint(): string
    {
        return 'The member no longer exists or is already verified. Use "Send verification email" on the member profile if needed.';
    }
}
