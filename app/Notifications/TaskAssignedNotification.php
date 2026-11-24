<?php

namespace App\Notifications;

use App\Models\EmailDelivery;
use App\Models\Task;
use App\Models\User;
use App\Support\AppUrl;
use Illuminate\Notifications\Messages\MailMessage;

/** A task was assigned (or reassigned) to the recipient member. Task details only. */
class TaskAssignedNotification extends TrackedNotification
{
    public function __construct(
        public Task $task,
        public bool $isReassignment = false,
        public ?string $assignedByName = null,
    ) {
        parent::__construct();
    }

    protected function buildMail(object $notifiable): MailMessage
    {
        $task = $this->task;
        $by = $this->assignedByName ?? 'An administrator';

        $mail = (new MailMessage)
            ->subject(($this->isReassignment ? 'Task reassigned to you: ' : 'New task assigned: ').$task->title)
            ->greeting('Hello '.strtok((string) ($notifiable->name ?? ''), ' ').',')
            ->line("{$by} ".($this->isReassignment ? 'reassigned' : 'assigned')." task {$task->code()} to you: “{$task->title}”.")
            ->line('Priority: '.$task->priority->label().($task->due_date ? ' · Due '.$task->due_date->format('F j, Y') : ' · No due date'));

        return $mail->action('Open the task', AppUrl::route('my.tasks.show', $task))
            ->line('Sign in to MemberFlow to update its status and progress.');
    }

    public function context(): array
    {
        return ['reassignment' => $this->isReassignment, 'by' => $this->assignedByName];
    }

    public static function rebuild(EmailDelivery $delivery): ?self
    {
        $task = $delivery->related_type === 'task' ? Task::find($delivery->related_id) : null;
        $user = $delivery->notifiable_type === 'user' ? User::find($delivery->notifiable_id) : null;

        if (! $task || ! $user || ! $user->canSignIn() || ! $task->isCurrentlyAssignedTo($user)) {
            return null;
        }

        return new self($task, (bool) ($delivery->context['reassignment'] ?? false), $delivery->context['by'] ?? null);
    }

    public static function retryHint(): string
    {
        return 'The task is no longer assigned to this person (or their account can no longer sign in), so the email is no longer accurate.';
    }
}
