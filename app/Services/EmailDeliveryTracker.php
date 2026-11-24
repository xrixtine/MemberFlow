<?php

namespace App\Services;

use App\Enums\EmailDeliveryStatus;
use App\Exceptions\EmailDeliveryException;
use App\Models\EmailDelivery;
use App\Notifications\TrackedNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Mail\SentMessage;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * Single entry point for sending email, with honest delivery tracking.
 *
 *   send()       → creates an email_deliveries row ("queued", or "skipped" for a
 *                  fictional recipient) and queues the notification after commit
 *                  (QUEUE_CONNECTION=database), or sends it right after commit in the
 *                  same request (QUEUE_CONNECTION=sync, failures recorded, never thrown).
 *   markSending  → the queue worker is about to try (attempts + 1).
 *   markSent     → the transport returned successfully: "sent" ONLY for the smtp
 *                  mailer; any other mailer (log, array…) is "logged" = NOT delivered.
 *   markFailed   → all attempts failed: "failed" with a sanitised error message.
 *
 * There is no fallback: an SMTP failure is recorded as a failure, never as "logged".
 */
class EmailDeliveryTracker
{
    /**
     * Track and queue a notification. Returns the delivery row (an existing row when
     * $dedupeKey was already used, in which case nothing new is queued).
     */
    public function send(object $notifiable, TrackedNotification $notification, ?Model $related = null, ?string $dedupeKey = null): EmailDelivery
    {
        if ($dedupeKey !== null && ($existing = EmailDelivery::where('dedupe_key', $dedupeKey)->first())) {
            return $existing;
        }

        $recipient = $this->recipientFor($notifiable, $notification);
        $skipped = $recipient === null || $this->isFictional($recipient);

        try {
            $delivery = EmailDelivery::create([
                'notification' => class_basename($notification),
                'dedupe_key' => $dedupeKey,
                'recipient_email' => $recipient ?? '(no address)',
                'notifiable_type' => $notifiable instanceof Model ? $notifiable->getMorphClass() : null,
                'notifiable_id' => $notifiable instanceof Model ? $notifiable->getKey() : null,
                'related_type' => $related?->getMorphClass(),
                'related_id' => $related?->getKey(),
                'context' => $notification->context() ?: null,
                'mailer' => $this->currentMailer(),
                'status' => $skipped ? EmailDeliveryStatus::Skipped : EmailDeliveryStatus::Queued,
                'attempts' => 0,
                'queued_at' => $skipped ? null : now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent request already tracked this exact event.
            return EmailDelivery::where('dedupe_key', $dedupeKey)->firstOrFail();
        }

        if (! $skipped) {
            $notification->deliveryId = $delivery->id;

            if ($this->usesSyncQueue()) {
                $this->sendNowAfterCommit($notifiable, $notification);
            } else {
                $notifiable->notify($notification);   // queued; sent by `php artisan queue:work`
            }
        }

        return $delivery;
    }

    /**
     * Track and send a notification RIGHT NOW in this process, whatever QUEUE_CONNECTION
     * is, and throw if it was not accepted. Used for one-time codes that must never be
     * written to the jobs table and whose caller must know the outcome before going on.
     * Must not be called inside a database transaction.
     *
     * @throws EmailDeliveryException when the recipient is fictional or sending failed
     */
    public function sendNowOrFail(object $notifiable, TrackedNotification $notification, ?Model $related = null): EmailDelivery
    {
        $recipient = $this->recipientFor($notifiable, $notification);
        if ($recipient === null || $this->isFictional($recipient)) {
            throw new EmailDeliveryException('The email was not sent: the recipient is missing or is a fictional/reserved address.');
        }

        $delivery = EmailDelivery::create([
            'notification' => class_basename($notification),
            'recipient_email' => $recipient,
            'notifiable_type' => $notifiable instanceof Model ? $notifiable->getMorphClass() : null,
            'notifiable_id' => $notifiable instanceof Model ? $notifiable->getKey() : null,
            'related_type' => $related?->getMorphClass(),
            'related_id' => $related?->getKey(),
            'context' => $notification->context() ?: null,
            'mailer' => $this->currentMailer(),
            'status' => EmailDeliveryStatus::Queued,
            'attempts' => 0,
            'queued_at' => now(),
        ]);
        $notification->deliveryId = $delivery->id;

        try {
            Notification::sendNow($notifiable, $notification);
        } catch (Throwable $e) {
            $this->markFailed($delivery->id, $e);

            throw new EmailDeliveryException('The email could not be sent: '.$this->safeErrorMessage($e));
        }

        $delivery->refresh();
        if ($delivery->status !== EmailDeliveryStatus::Sent) {
            throw new EmailDeliveryException("The email was not accepted by the SMTP server (status: {$delivery->status->value}).");
        }

        return $delivery;
    }

    /** True when QUEUE_CONNECTION points at a connection using the "sync" driver (no worker). */
    public function usesSyncQueue(): bool
    {
        $connection = (string) config('queue.default');

        return config("queue.connections.{$connection}.driver") === 'sync';
    }

    /**
     * QUEUE_CONNECTION=sync (local demo, no queue worker): send in this request, but only
     * after the surrounding DB transaction commits (never for a rolled-back action).
     *
     * Laravel's sync queue re-throws a failed job into the request — after the business
     * data has already been committed — which would show an error page for an action that
     * actually succeeded (and invite a duplicate retry). Here a send failure is recorded as
     * "failed" with a safe message (retry from the Email log) and the request continues.
     * There is no automatic retry in sync mode.
     */
    private function sendNowAfterCommit(object $notifiable, TrackedNotification $notification): void
    {
        DB::afterCommit(function () use ($notifiable, $notification) {
            try {
                Notification::sendNow($notifiable, $notification);
            } catch (Throwable $e) {
                $this->markFailed($notification->deliveryId, $e);
                Log::warning('Email delivery failed (sync mode)', [
                    'delivery_id' => $notification->deliveryId,
                    'notification' => class_basename($notification),
                    'error' => $this->safeErrorMessage($e),
                ]);
            }
        });
    }

    /** Called by TrackedNotification::shouldSend(): never send a final or superseded delivery again. */
    public function shouldSend(?int $deliveryId): bool
    {
        if ($deliveryId === null) {
            return true;
        }

        $delivery = EmailDelivery::find($deliveryId);

        return $delivery !== null
            && $delivery->superseded_by_id === null
            && ! $delivery->status->isFinal();
    }

    public function markSending(?int $deliveryId): void
    {
        if ($deliveryId === null) {
            return;
        }

        EmailDelivery::whereKey($deliveryId)->update([
            'attempts' => DB::raw('LEAST(attempts + 1, 255)'),
            'last_attempt_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function markSent(?int $deliveryId, mixed $response, ?string $mailer = null): void
    {
        if ($deliveryId === null) {
            return;
        }

        $mailer ??= $this->currentMailer();

        EmailDelivery::whereKey($deliveryId)->update([
            'status' => EmailDeliveryStatus::forSuccessfulMailer($mailer)->value,
            'mailer' => $mailer,
            'sent_at' => now(),
            'failed_at' => null,
            'error_message' => null,
            'message_id' => $response instanceof SentMessage ? Str::limit((string) $response->getMessageId(), 250, '') : null,
            'updated_at' => now(),
        ]);
    }

    /** A single attempt failed but the delivery stays queued for a retry (status unchanged). */
    public function markAttemptFailed(?int $deliveryId, Throwable $exception): void
    {
        if ($deliveryId === null) {
            return;
        }

        EmailDelivery::whereKey($deliveryId)
            ->where('status', EmailDeliveryStatus::Queued->value)
            ->update(['error_message' => $this->safeErrorMessage($exception), 'updated_at' => now()]);
    }

    public function markFailed(?int $deliveryId, Throwable $exception): void
    {
        if ($deliveryId === null) {
            return;
        }

        // Never downgrade a delivery that has already succeeded.
        EmailDelivery::whereKey($deliveryId)
            ->whereIn('status', [EmailDeliveryStatus::Queued->value, EmailDeliveryStatus::Failed->value])
            ->update([
                'status' => EmailDeliveryStatus::Failed->value,
                'mailer' => $this->currentMailer(),
                'failed_at' => now(),
                'error_message' => $this->safeErrorMessage($exception),
                'updated_at' => now(),
            ]);
    }

    /**
     * Administrator retry of a FAILED delivery: queues a freshly built copy as a new
     * row and marks the old row superseded (so its old queued job can never send).
     */
    public function retry(EmailDelivery $delivery): EmailDelivery
    {
        $class = 'App\\Notifications\\'.$delivery->notification;

        if ($delivery->status !== EmailDeliveryStatus::Failed || $delivery->superseded_by_id !== null) {
            throw new EmailDeliveryException('Only failed deliveries that have not been retried yet can be retried.');
        }
        if (! class_exists($class) || ! is_subclass_of($class, TrackedNotification::class)) {
            throw new EmailDeliveryException('This email type cannot be retried.');
        }

        $notification = $class::rebuild($delivery);
        if ($notification === null) {
            throw new EmailDeliveryException($class::retryHint());
        }

        $notifiable = $delivery->notifiable_type ? $delivery->notifiable()->first() : null;
        $notifiable ??= Notification::route('mail', $delivery->recipient_email);

        return DB::transaction(function () use ($delivery, $notification, $notifiable) {
            $locked = EmailDelivery::whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            if ($locked->superseded_by_id !== null) {
                throw new EmailDeliveryException('This delivery has already been retried.');
            }

            $dedupeKey = $locked->dedupe_key;
            $locked->forceFill(['dedupe_key' => null])->save();

            $new = $this->send($notifiable, $notification, $locked->related_type ? $locked->related()->first() : null, $dedupeKey);
            $locked->forceFill(['superseded_by_id' => $new->id])->save();

            return $new;
        });
    }

    /** True for reserved / fictional domains that can never receive mail. */
    public function isFictional(string $email): bool
    {
        $domain = strtolower(trim((string) Str::after($email, '@')));
        if ($domain === '' || ! str_contains($email, '@')) {
            return true;
        }

        foreach (config('memberflow.fictional_email_domains', []) as $reserved) {
            if ($domain === $reserved || str_ends_with($domain, '.'.$reserved)) {
                return true;
            }
        }

        $tld = Str::afterLast($domain, '.');

        return in_array($tld, config('memberflow.fictional_email_tlds', []), true);
    }

    public function currentMailer(): string
    {
        return (string) config('mail.default');
    }

    public function isLogMode(): bool
    {
        return $this->currentMailer() !== 'smtp';
    }

    /** SMTP selected but the username or password is blank (sending is certain to fail). */
    public function smtpMissingCredentials(): bool
    {
        return $this->currentMailer() === 'smtp'
            && (blank(config('mail.mailers.smtp.username')) || blank(config('mail.mailers.smtp.password')));
    }

    /**
     * An honest one-line description of what happened to an email, for flash messages.
     */
    public function describe(EmailDelivery $delivery, string $what = 'The email'): string
    {
        return match ($delivery->status) {
            EmailDeliveryStatus::Skipped => "{$what} was NOT sent: {$delivery->recipient_email} is a fictional/reserved address (status: skipped).",
            EmailDeliveryStatus::Queued => $this->isLogMode()
                ? "{$what} was queued for {$delivery->recipient_email}, but email is in local log mode (MAIL_MAILER={$this->currentMailer()}): it will be written to the local mail log and NOT delivered."
                : "{$what} was queued for {$delivery->recipient_email}. It is sent when the queue worker (php artisan queue:work) processes it — check the Email log for the result.",
            EmailDeliveryStatus::Sent => "{$what} was accepted by the SMTP server for {$delivery->recipient_email} (acceptance is not proof of inbox delivery).",
            EmailDeliveryStatus::Logged => "{$what} was written to the local mail log and was NOT delivered to {$delivery->recipient_email}.",
            EmailDeliveryStatus::Failed => "{$what} to {$delivery->recipient_email} FAILED: ".($delivery->error_message ?: 'unknown error').'.',
        };
    }

    /**
     * Exception message safe to store and show: credentials, secure links and tokens
     * are removed, whitespace is collapsed and the length is capped.
     */
    public function safeErrorMessage(Throwable $exception): string
    {
        $message = class_basename($exception).': '.$exception->getMessage();

        foreach ([config('mail.mailers.smtp.password'), config('mail.mailers.smtp.username')] as $secret) {
            if (is_string($secret) && $secret !== '') {
                $message = str_ireplace($secret, '[redacted]', $message);
            }
        }

        $message = SecureLinkRedactor::redact($message);
        $message = (string) preg_replace('/\s+/', ' ', $message);

        return Str::limit(trim($message), 500);
    }

    private function recipientFor(object $notifiable, TrackedNotification $notification): ?string
    {
        $route = $notifiable instanceof AnonymousNotifiable
            ? ($notifiable->routes['mail'] ?? null)
            : $notifiable->routeNotificationFor('mail', $notification);

        if (is_array($route)) {
            $route = array_key_first($route);
        }

        return is_string($route) && $route !== '' ? strtolower($route) : null;
    }
}
