<?php

namespace App\Models;

use App\Enums\PlannerProposalStatus;
use Database\Factories\PlannerProposalItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $planner_proposal_id
 * @property int $task_id
 * @property Carbon $proposed_scheduled_date
 * @property string $reason
 * @property PlannerProposalStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['planner_proposal_id', 'task_id', 'proposed_scheduled_date', 'reason', 'status'])]
class PlannerProposalItem extends Model
{
    /** @use HasFactory<PlannerProposalItemFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'proposed_scheduled_date' => 'date',
            'status' => PlannerProposalStatus::class,
        ];
    }

    /**
     * @return BelongsTo<PlannerProposal, $this>
     */
    public function plannerProposal(): BelongsTo
    {
        return $this->belongsTo(PlannerProposal::class);
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
