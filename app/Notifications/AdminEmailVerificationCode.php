<?php

namespace App\Notifications;

use App\Models\EmailDelivery;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * One-time code proving that the person changing an administrator's sign-in email
 * controls the NEW mailbox (php artisan memberflow:change-admin-credentials).
 *
 * Sent immediately (never queued, so the code is never written to the jobs table),
 * only to the new address. The code is not in the subject, not in context(), and the
 * email cannot be retried from the Email log (rebuild() returns null).
 */
class AdminEmailVerificationCode extends TrackedNotification
{
    public function __construct(private readonly string $code, private readonly int $ttlMinutes)
    {
        parent::__construct();
    }

    protected function buildMail(object $notifiable): MailMessage
    {
        $app = config('app.name');

        return (new MailMessage)
            ->subject("{$app} administrator email confirmation code")
            ->greeting('Hello,')
            ->line("Someone is changing a {$app} administrator's sign-in email to this address at the command line.")
            ->line('Your one-time confirmation code is:')
            ->line('**'.$this->code.'**')
            ->line("The code expires in {$this->ttlMinutes} minutes and can be used once. Nothing changes until it is entered.")
            ->line('If you did not expect this email, ignore it. The account stays as it is.');
    }

    public static function rebuild(EmailDelivery $delivery): ?self
    {
        return null;
    }

    public static function retryHint(): string
    {
        return 'This one-time code cannot be resent. Run php artisan memberflow:change-admin-credentials again to get a new code.';
    }

    /** Keep the code out of dumps and logs. */
    public function __debugInfo(): array
    {
        return ['deliveryId' => $this->deliveryId];
    }
}
