<?php

namespace App\Models;

use App\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Task activity log entry (one row per meaningful event, never deleted).
 */
class TaskProgressUpdate extends Model
{
    public const CREATED = 'created';

    public const UPDATED = 'updated';

    public const ASSIGNED = 'assigned';

    public const REASSIGNED = 'reassigned';

    public const STATUS = 'status';

    public const PROGRESS = 'progress';

    public const NOTE = 'note';

    public const COMPLETED = 'completed';

    public const REOPENED = 'reopened';

    public const ARCHIVED = 'archived';

    public const UNARCHIVED = 'unarchived';

    public const DELETED = 'deleted';

    public const RESTORED = 'restored';

    public const LABELS = [
        self::CREATED => 'Task created',
        self::UPDATED => 'Details edited',
        self::ASSIGNED => 'Assigned',
        self::REASSIGNED => 'Reassigned',
        self::STATUS => 'Status changed',
        self::PROGRESS => 'Progress updated',
        self::NOTE => 'Progress note',
        self::COMPLETED => 'Completed',
        self::REOPENED => 'Reopened',
        self::ARCHIVED => 'Archived',
        self::UNARCHIVED => 'Restored from archive',
        self::DELETED => 'Moved to trash',
        self::RESTORED => 'Restored from trash',
    ];

    protected $fillable = [
        'task_id',
        'user_id',
        'type',
        'old_status',
        'new_status',
        'old_progress',
        'new_progress',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'old_status' => TaskStatus::class,
            'new_status' => TaskStatus::class,
            'old_progress' => 'integer',
            'new_progress' => 'integer',
        ];
    }

    public function label(): string
    {
        return self::LABELS[$this->type] ?? ucfirst($this->type);
    }

    public function color(): string
    {
        return match ($this->type) {
            self::COMPLETED => 'green',
            self::REOPENED, self::ARCHIVED, self::DELETED => 'amber',
            self::ASSIGNED, self::REASSIGNED => 'blue',
            self::CREATED => 'lime',
            default => 'gray',
        };
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
