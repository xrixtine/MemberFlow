<?php

namespace App\Listeners;

use App\Notifications\TrackedNotification;
use App\Services\EmailDeliveryTracker;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;

/**
 * Updates email_deliveries from Laravel's own notification events (queue worker side).
 * NotificationSent fires only after the mail transport returned without an error.
 *
 * While one email is being rendered and sent, config('app.url') is set to the APP_URL
 * captured when that email was queued (TrackedNotification::$appUrl), then restored.
 * This keeps every link in the email — including Laravel's mail layout header, which
 * reads config('app.url') — correct even in a worker started before an APP_URL change.
 */
class TrackEmailDeliveryStatus
{
    /** config('app.url') to restore after the current send (listeners are resolved per event). */
    private static ?string $restoreAppUrl = null;

    public function __construct(private readonly EmailDeliveryTracker $tracker) {}

    public function handleSending(NotificationSending $event): void
    {
        if ($event->channel !== 'mail' || ! $event->notification instanceof TrackedNotification) {
            return;
        }

        $this->tracker->markSending($event->notification->deliveryId);

        if (filled($event->notification->appUrl)) {
            self::$restoreAppUrl ??= (string) config('app.url');
            config(['app.url' => $event->notification->appUrl]);
        }
    }

    /**
     * One attempt failed (the queue may still retry it). The safe error is recorded so the
     * Email log can say "retrying after an error"; the final failure is recorded by failed().
     */
    public function handleFailed(NotificationFailed $event): void
    {
        if ($event->channel !== 'mail' || ! $event->notification instanceof TrackedNotification) {
            return;
        }

        $exception = $event->data['exception'] ?? null;
        if ($exception instanceof \Throwable) {
            $this->tracker->markAttemptFailed($event->notification->deliveryId, $exception);
        }
        self::restoreAppUrl();
    }

    public function handleSent(NotificationSent $event): void
    {
        if ($event->channel !== 'mail' || ! $event->notification instanceof TrackedNotification) {
            return;
        }

        $this->tracker->markSent($event->notification->deliveryId, $event->response);
        self::restoreAppUrl();
    }

    private static function restoreAppUrl(): void
    {
        if (self::$restoreAppUrl !== null) {
            config(['app.url' => self::$restoreAppUrl]);
            self::$restoreAppUrl = null;
        }
    }
}
