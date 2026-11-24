<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Sent ONLY to the membership record's new email address. The signed link changes
 * the member's sign-in email, and only for the member who is signed in to that
 * account, so receiving this email alone never gives access to the account.
 */
class ConfirmAccountEmailChange extends TrackedNotification implements ShouldBeEncrypted
{
    public function __construct(public User $account, private string $url)
    {
        parent::__construct();
    }

    protected function buildMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Confirm your new '.config('app.name').' sign-in email')
            ->greeting("Hello {$this->account->name},")
            ->line('The membership office updated the email address on your membership record to this address.')
            ->line('Your member account still signs in with your previous email. To use this address for signing in, open the link below. You will be asked to sign in first if you are not signed in.')
            ->action('Confirm my new email', $this->url)
            ->line('The link expires in '.config('memberflow.verification_ttl_hours', 48).' hours. If you did not expect this change, do not open the link and contact the membership office.');
    }

    public static function retryHint(): string
    {
        return 'Confirmation links are not stored. Use "Resend confirmation" on the member\'s page, which emails a new link.';
    }
}
