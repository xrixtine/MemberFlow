<?php

namespace App\Notifications;

use App\Models\EmailDelivery;
use Illuminate\Notifications\Messages\MailMessage;

/** "Send test email to me" from the Email log page (always to the signed-in admin). */
class TestEmail extends TrackedNotification
{
    public function __construct(public string $requestedAt = '')
    {
        parent::__construct();
        $this->requestedAt = $requestedAt ?: now()->format('Y-m-d H:i:s');
    }

    protected function buildMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(config('app.name').' test email ('.$this->requestedAt.')')
            ->greeting('Hello '.strtok((string) ($notifiable->name ?? ''), ' ').',')
            ->line('This is a test email from '.config('app.name').', requested on the Email log page at '.$this->requestedAt.'.')
            ->line('If you are reading this in your inbox, email delivery works end to end. In MemberFlow the delivery shows as "Accepted by SMTP server" — that status alone only proves the SMTP server accepted it; seeing this message proves it arrived.');
    }

    public function context(): array
    {
        return ['requested_at' => $this->requestedAt];
    }

    public static function rebuild(EmailDelivery $delivery): ?self
    {
        return new self(now()->format('Y-m-d H:i:s'));
    }
}
