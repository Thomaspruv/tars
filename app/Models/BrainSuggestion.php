<?php

namespace App\Models;

use App\Enums\BrainSuggestionAction;
use App\Enums\BrainSuggestionStatus;
use App\Enums\SuggestionConfidence;
use Database\Factories\BrainSuggestionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $brain_document_id
 * @property BrainSuggestionAction $action
 * @property string|null $target
 * @property array<string, mixed>|null $frontmatter_patch
 * @property string|null $merged_content
 * @property SuggestionConfidence $confidence
 * @property string $reason
 * @property BrainSuggestionStatus $status
 * @property int $agent_run_id
 * @property string|null $created_type
 * @property int|null $created_id
 * @property Carbon|null $deprioritized_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'brain_document_id', 'action', 'target', 'frontmatter_patch', 'merged_content',
    'confidence', 'reason', 'status', 'agent_run_id', 'created_type', 'created_id', 'deprioritized_at',
])]
class BrainSuggestion extends Model
{
    /** @use HasFactory<BrainSuggestionFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'action' => BrainSuggestionAction::class,
            'frontmatter_patch' => 'array',
            'confidence' => SuggestionConfidence::class,
            'status' => BrainSuggestionStatus::class,
            'deprioritized_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<BrainDocument, $this>
     */
    public function brainDocument(): BelongsTo
    {
        return $this->belongsTo(BrainDocument::class);
    }

    /**
     * @return BelongsTo<AgentRun, $this>
     */
    public function agentRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function createdRecord(): MorphTo
    {
        return $this->morphTo('created');
    }
}
