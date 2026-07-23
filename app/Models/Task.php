<?php

namespace App\Models;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Support\Recurrence\RecurrenceCalculator;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $title
 * @property string|null $notes
 * @property int|null $goal_id
 * @property int|null $milestone_id
 * @property int|null $entity_id
 * @property Carbon|null $due_date
 * @property Carbon|null $scheduled_date
 * @property TaskStatus $status
 * @property TaskPriority|null $priority
 * @property bool $is_delegable
 * @property string|null $recurrence
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'title', 'notes', 'goal_id', 'milestone_id', 'entity_id', 'due_date', 'scheduled_date',
    'status', 'priority', 'is_delegable', 'recurrence', 'completed_at',
])]
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'inbox',
        'is_delegable' => false,
    ];

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'priority' => TaskPriority::class,
            'due_date' => 'date',
            'scheduled_date' => 'date',
            'is_delegable' => 'boolean',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', [TaskStatus::Done, TaskStatus::Cancelled]);
    }

    /**
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function scopeForToday(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->open()->where(function (Builder $query): void {
                $query->whereDate('scheduled_date', today())
                    ->orWhereDate('due_date', '<=', today());
            });
        })->orWhere(function (Builder $query): void {
            $query->where('status', TaskStatus::Done)->whereDate('completed_at', today());
        });
    }

    /**
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function scopeOrphan(Builder $query): Builder
    {
        return $query->whereNull('goal_id');
    }

    public function toggleCompletion(): void
    {
        if ($this->status === TaskStatus::Done) {
            $this->update(['status' => TaskStatus::Todo, 'completed_at' => null]);

            return;
        }

        if ($this->recurrence !== null) {
            $next = app(RecurrenceCalculator::class)->nextOccurrence(
                $this->recurrence,
                $this->due_date ?? $this->scheduled_date ?? today(),
            );

            $this->update([
                'due_date' => $this->due_date ? $next : null,
                'scheduled_date' => $this->scheduled_date ? $next : null,
            ]);

            return;
        }

        $this->update(['status' => TaskStatus::Done, 'completed_at' => now()]);
    }

    /**
     * @return BelongsTo<Goal, $this>
     */
    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }

    /**
     * @return BelongsTo<Milestone, $this>
     */
    public function milestone(): BelongsTo
    {
        return $this->belongsTo(Milestone::class);
    }

    /**
     * @return BelongsTo<Entity, $this>
     */
    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }
}
