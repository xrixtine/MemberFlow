<?php

namespace App\Models;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A task assigned by an administrator to a staff user. Tasks are archived or
 * soft-deleted, never physically removed, so their history is preserved.
 */
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'title',
        'description',
        'priority',
        'due_date',
        'member_id',
    ];

    protected function casts(): array
    {
        return [
            'priority' => TaskPriority::class,
            'status' => TaskStatus::class,
            'progress' => 'integer',
            'due_date' => 'date',
            'completed_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /** Display identifier, e.g. TASK-00042 (derived from the unique primary key). */
    public function code(): string
    {
        return sprintf('TASK-%05d', $this->id);
    }

    public function isCompleted(): bool
    {
        return $this->status === TaskStatus::Completed;
    }

    /** Whether the user has ever been assigned this task (current or previous). */
    public function wasAssignedTo(User $user): bool
    {
        return $this->assignments()->where('user_id', $user->id)->exists();
    }

    public function isCurrentlyAssignedTo(User $user): bool
    {
        return $this->assigned_to !== null && (int) $this->assigned_to === (int) $user->id;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function isOverdue(): bool
    {
        return $this->due_date !== null
            && ! $this->isCompleted()
            && $this->due_date->lt(today());
    }

    // ---- Scopes -------------------------------------------------------

    /** Not archived (soft-deleted rows are already excluded by SoftDeletes). */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->whereNotNull('due_date')
            ->whereDate('due_date', '<', today())
            ->where('status', '!=', TaskStatus::Completed->value);
    }

    public function scopeAssignedTo(Builder $query, User $user): Builder
    {
        return $query->where('assigned_to', $user->id);
    }

    // ---- Relationships ------------------------------------------------

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function archiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class)->withTrashed();
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(TaskAssignment::class)->orderBy('assigned_at');
    }

    public function updates(): HasMany
    {
        return $this->hasMany(TaskProgressUpdate::class)->orderBy('created_at')->orderBy('id');
    }
}
