<?php

namespace App\Notifications;

use App\Models\EmailDelivery;
use App\Support\AppUrl;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Sent instead of a second application when someone applies with an email that is
 * already registered. The browser sees the same generic response either way, so the
 * form never reveals whether an address is registered; only the mailbox owner learns it.
 */
class ApplicationAlreadyExists extends TrackedNotification
{
    public function __construct()
    {
        parent::__construct();
    }

    protected function buildMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('About your '.config('app.name').' membership application')
            ->greeting('Hello,')
            ->line('We received a membership application for this email address, but an application or membership already exists for it, so no new application was created.')
            ->line('If you applied recently and have not verified your email yet, you can request a new verification link:')
            ->action('Request a new verification link', AppUrl::route('apply.resend'))
            ->line('Otherwise, please contact the membership office. If you did not apply, you can ignore this email.');
    }

    public static function rebuild(EmailDelivery $delivery): ?self
    {
        return new self;
    }
}
