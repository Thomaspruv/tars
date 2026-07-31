<?php

namespace App\Models;

use App\Enums\PlannerProposalStatus;
use Database\Factories\PlannerProposalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property PlannerProposalStatus $status
 * @property int $agent_run_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['period_start', 'period_end', 'status', 'agent_run_id'])]
class PlannerProposal extends Model
{
    /** @use HasFactory<PlannerProposalFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'status' => PlannerProposalStatus::class,
        ];
    }

    /**
     * @return BelongsTo<AgentRun, $this>
     */
    public function agentRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class);
    }

    /**
     * @return HasMany<PlannerProposalItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PlannerProposalItem::class);
    }
}
