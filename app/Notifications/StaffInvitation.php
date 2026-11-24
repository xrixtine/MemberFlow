<?php

namespace App\Notifications;

use App\Models\UserInvitation;
use App\Support\AppUrl;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Staff invitation email carrying the one-time activation link.
 *
 * The raw token exists only in this queued notification. ShouldBeEncrypted makes
 * Laravel encrypt the queued job (jobs / failed_jobs tables) with APP_KEY, so the
 * token is never stored in plain text. It cannot be rebuilt for a retry — only
 * the hash is stored — so a failed invitation is re-sent with "Resend", which
 * issues a NEW link and invalidates the old one.
 */
class StaffInvitation extends TrackedNotification implements ShouldBeEncrypted
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
        $invitation = $this->invitation->loadMissing('inviter');
        $inviter = $invitation->inviter?->name ?? 'An administrator';

        return (new MailMessage)
            ->subject(($this->isResend ? 'New link: ' : '').'You are invited to join '.config('app.name').' as staff')
            ->greeting("Hello {$invitation->name},")
            ->line("{$inviter} invited you to create a staff account on ".config('app.name').'.')
            ->when($this->isResend, fn (MailMessage $m) => $m->line('This email replaces any earlier invitation: older invitation links no longer work.'))
            ->line('To activate your account: open the link below, check your name, and choose a password (at least 8 characters with upper- and lower-case letters and a number).')
            ->action('Activate my account', AppUrl::route('invitation.show', $this->token))
            ->line('This link works once and expires on '.$invitation->expires_at->format('F j, Y \a\t g:i A').' ('.config('app.timezone').').')
            ->line('Do not forward this email: anyone with the link can activate the account. If you were not expecting it, you can ignore it.');
    }

    public static function retryHint(): string
    {
        return 'Invitation links are one-time secrets and are not stored, so this email cannot be re-sent as-is. Open the invitation and use "Resend" — it issues a new link and invalidates the old one.';
    }
}
