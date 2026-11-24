<?php

namespace App\Notifications;

use App\Models\EmailDelivery;
use App\Services\EmailDeliveryTracker;
use App\Support\AppUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Throwable;

/**
 * Base class for every MemberFlow email.
 *
 *  - Queued on the database queue and dispatched only AFTER the surrounding DB
 *    transaction commits (afterCommit), so a rolled-back action never emails anyone.
 *  - Always sent through EmailDeliveryTracker, which creates the email_deliveries
 *    row first and stores its id in $deliveryId.
 *  - shouldSend() refuses to send twice: if the row is already final (sent/logged/
 *    skipped) or was superseded by a manual retry, the job does nothing.
 *  - failed() records the final failure with a sanitised error message.
 */
abstract class TrackedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** The email_deliveries row this notification reports to. */
    public ?int $deliveryId = null;

    /** Queue worker attempts before the delivery is marked failed. */
    public int $tries = 3;

    /** Seconds between attempts. */
    public array $backoff = [30, 120];

    /**
     * APP_URL when the email was queued (read by the web request from the current .env).
     * Links are built with it, so a worker started before an APP_URL change still emails
     * the right host.
     */
    public ?string $appUrl = null;

    public function __construct()
    {
        $this->afterCommit();
        $this->onQueue('default');
        $this->appUrl = (string) config('app.url');
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /** Laravel's mail channel calls this; links inside are based on the captured APP_URL. */
    public function toMail(object $notifiable): MailMessage
    {
        return AppUrl::using($this->appUrl, fn () => $this->buildMail($notifiable));
    }

    /** The email content. Build links with AppUrl (never with an absolute route()). */
    abstract protected function buildMail(object $notifiable): MailMessage;

    public function shouldSend(object $notifiable, string $channel): bool
    {
        return app(EmailDeliveryTracker::class)->shouldSend($this->deliveryId);
    }

    public function failed(Throwable $exception): void
    {
        app(EmailDeliveryTracker::class)->markFailed($this->deliveryId, $exception);
    }

    /** Small, non-sensitive data needed to rebuild this notification for a manual retry. */
    public function context(): array
    {
        return [];
    }

    /**
     * Rebuild this notification from a failed delivery for a manual retry,
     * or null when it cannot be rebuilt safely (e.g. it contained a one-time token).
     */
    public static function rebuild(EmailDelivery $delivery): ?self
    {
        return null;
    }

    /** Why rebuild() returns null — shown to the administrator. */
    public static function retryHint(): string
    {
        return 'This email cannot be retried automatically.';
    }
}
