<?php

namespace App\Listeners;

use App\Services\SecureLinkRedactor;
use Illuminate\Mail\Events\MessageSending;
use Symfony\Component\Mime\Email;

/**
 * With MAIL_MAILER=log the whole email is written to a log file. Activation and
 * verification links are bearer credentials, so they are removed from LOGGED mail.
 * Real SMTP delivery is untouched. For local testing use the development-only
 * commands memberflow:invitation-link and memberflow:verification-link instead.
 */
class RedactSecureLinksInLoggedMail
{
    public const NOTICE = '[secure link removed — MAIL_MAILER=log does not deliver email. '
        .'For local testing run: php artisan memberflow:invitation-link EMAIL or php artisan memberflow:verification-link EMAIL]';

    public function handle(MessageSending $event): void
    {
        $mailer = $event->data['mailer'] ?? config('mail.default');
        if ($mailer !== 'log' || ! $event->message instanceof Email) {
            return;
        }

        $message = $event->message;

        if (($html = $message->getHtmlBody()) !== null) {
            $html = is_resource($html) ? (string) stream_get_contents($html) : (string) $html;
            $message->html(SecureLinkRedactor::redact($html, e(self::NOTICE)), $message->getHtmlCharset() ?? 'utf-8');
        }

        if (($text = $message->getTextBody()) !== null) {
            $text = is_resource($text) ? (string) stream_get_contents($text) : (string) $text;
            $message->text(SecureLinkRedactor::redact($text, self::NOTICE), $message->getTextCharset() ?? 'utf-8');
        }
    }
}
