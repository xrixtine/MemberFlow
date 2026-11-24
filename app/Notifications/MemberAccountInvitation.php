<?php

namespace App\Notifications;

use App\Models\UserInvitation;
use App\Support\AppUrl;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Member-account activation email with the one-time link.
 *
 * Same protections as StaffInvitation: the raw token exists only in this
 * notification (the queued job is encrypted with APP_KEY when a queue is used),
 * it is never stored, and a failed email is re-sent with "Resend", which issues
 * a new link. No password is ever included: the member chooses one on the page.
 */
class MemberAccountInvitation extends TrackedNotification implements ShouldBeEncrypted
{
    public function __construct(
        public UserInvitation $invitation,
        #[\SensitiveParameter] private string $token,
        public bool $isResend = false,
    ) {
        parent::__construct();
    }

    protected function buildMail(object $notifiable): MailMessage
    {
        $invitation = $this->invitation->loadMissing('member');
        $membershipNo = $invitation->member?->membership_no;

        return (new MailMessage)
            ->subject(($this->isResend ? 'New link: ' : '').'Activate your '.config('app.name').' member account')
            ->greeting("Hello {$invitation->name},")
            ->line('You can now activate your online member account'.($membershipNo ? " for membership {$membershipNo}" : '').'.')
            ->line('With it you can check your membership status and validity, your payments and receipts, and any tasks assigned to you.')
            ->when($this->isResend, fn (MailMessage $m) => $m->line('This email replaces any earlier activation email: older links no longer work.'))
            ->line('Open the link below and choose a password (at least 8 characters with upper- and lower-case letters and a number).')
            ->action('Activate my member account', AppUrl::route('invitation.show', $this->token))
            ->line('This link works once and expires on '.$invitation->expires_at->format('F j, Y \a\t g:i A').' ('.config('app.timezone').').')
            ->line('Activating your account does not approve or pay for your membership; the membership office still handles those.')
            ->line('Do not forward this email: anyone with the link can activate the account. If you were not expecting it, you can ignore it.');
    }

    public static function retryHint(): string
    {
        return StaffInvitation::retryHint();
    }
}
