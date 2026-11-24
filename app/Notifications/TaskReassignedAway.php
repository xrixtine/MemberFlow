<?php

namespace App\Notifications;

use App\Models\EmailDelivery;
use App\Models\Task;
use App\Support\AppUrl;
use Illuminate\Notifications\Messages\MailMessage;

/** Tells the previous assignee that a task now belongs to someone else (read-only for them). */
class TaskReassignedAway extends TrackedNotification
{
    public function __construct(public Task $task, public ?string $newAssigneeName = null)
    {
        parent::__construct();
    }

    protected function buildMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Task reassigned: '.$this->task->title)
            ->greeting('Hello '.strtok((string) ($notifiable->name ?? ''), ' ').',')
            ->line("Task {$this->task->code()} “{$this->task->title}” has been reassigned".($this->newAssigneeName ? " to {$this->newAssigneeName}" : '').'.')
            ->line('You no longer need to work on it. You can still view its history (read-only) in MemberFlow.')
            ->action('View task history', AppUrl::route('my.tasks.show', $this->task));
    }

    public function context(): array
    {
        return ['new_assignee' => $this->newAssigneeName];
    }

    public static function rebuild(EmailDelivery $delivery): ?self
    {
        $task = $delivery->related_type === 'task' ? Task::find($delivery->related_id) : null;

        return $task ? new self($task, $delivery->context['new_assignee'] ?? null) : null;
    }
}
