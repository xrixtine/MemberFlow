<?php

namespace App\Notifications;

use App\Models\EmailDelivery;
use App\Models\TaskProgressUpdate;
use App\Support\AppUrl;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Tells an administrator that the assignee completed a task or put it On Hold.
 * Routine progress/note updates do NOT email anyone (no notification spam).
 */
class TaskStatusNotification extends TrackedNotification
{
    public function __construct(public TaskProgressUpdate $update)
    {
        parent::__construct();
    }

    protected function buildMail(object $notifiable): MailMessage
    {
        $update = $this->update->loadMissing(['task', 'user']);
        $task = $update->task;
        $completed = $update->type === TaskProgressUpdate::COMPLETED;
        $who = $update->user?->name ?? 'The assignee';

        $mail = (new MailMessage)
            ->subject(($completed ? 'Task completed: ' : 'Task on hold: ').$task->title)
            ->greeting('Hello '.strtok((string) ($notifiable->name ?? ''), ' ').',')
            ->line("{$who} ".($completed ? 'completed' : 'put on hold')." task {$task->code()} “{$task->title}”.");

        if ($update->note) {
            $mail->line('Note: '.$update->note);
        }

        return $mail->action('Review the task', AppUrl::route('admin.tasks.show', $task));
    }

    public static function rebuild(EmailDelivery $delivery): ?self
    {
        $update = isset($delivery->context['update_id']) ? TaskProgressUpdate::find($delivery->context['update_id']) : null;

        return $update ? new self($update) : null;
    }

    public function context(): array
    {
        return ['update_id' => $this->update->id];
    }
}
