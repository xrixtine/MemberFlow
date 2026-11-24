<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Honest email delivery states.
 *
 *  - Queued:  waiting for (or being retried by) the queue worker. Not delivered yet.
 *  - Sent:    the configured SMTP server ACCEPTED the message. This is not proof that it
 *             reached the inbox (spam filtering or a later bounce are still possible).
 *  - Logged:  written to the local mail log by MAIL_MAILER=log. NOT delivered to anyone.
 *  - Failed:  the transport rejected it or could not be reached (after all retries).
 *  - Skipped: intentionally not dispatched (fictional / reserved recipient domain).
 *
 * "Logged" and "Skipped" are never counted as sent.
 */
enum EmailDeliveryStatus: string
{
    use HasOptions;

    case Queued = 'queued';
    case Sent = 'sent';
    case Logged = 'logged';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued — waiting for queue worker',
            self::Sent => 'Accepted by SMTP server',
            self::Logged => 'Local log only — NOT delivered',
            self::Failed => 'Delivery failed',
            self::Skipped => 'Skipped (fictional recipient)',
        };
    }

    /** Short label for compact tables and filters. */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Sent => 'Sent (accepted)',
            self::Logged => 'Logged (not delivered)',
            self::Failed => 'Failed',
            self::Skipped => 'Skipped',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Queued => 'Waiting for the queue worker (php artisan queue:work). Nothing has been sent yet.',
            self::Sent => 'The SMTP server accepted the message. This does not guarantee it reached the inbox — check spam, or confirm with the recipient.',
            self::Logged => 'MAIL_MAILER=log: the email was written to storage/logs/mail.log on this computer and was NOT delivered to anyone.',
            self::Failed => 'The mail transport rejected the message or could not be reached. Nothing was delivered.',
            self::Skipped => 'The recipient uses a fictional or reserved domain (e.g. example.com, .test), so no email was dispatched.',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Queued => 'blue',
            self::Sent => 'green',
            self::Logged => 'amber',
            self::Failed => 'red',
            self::Skipped => 'gray',
        };
    }

    /** The status a successful hand-off to the given mailer represents. Only real SMTP counts as sent. */
    public static function forSuccessfulMailer(string $mailer): self
    {
        return $mailer === 'smtp' ? self::Sent : self::Logged;
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Sent, self::Logged, self::Skipped], true);
    }
}
