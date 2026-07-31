<?php

namespace App\Models;

use App\Enums\InboxConvertedType;
use App\Enums\InboxSuggestionStatus;
use App\Enums\SuggestionConfidence;
use Database\Factories\InboxSuggestionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $inbox_item_id
 * @property InboxConvertedType $suggested_type
 * @property array<string, mixed> $suggested_fields
 * @property SuggestionConfidence $confidence
 * @property string $reason
 * @property InboxSuggestionStatus $status
 * @property int $agent_run_id
 * @property string|null $created_type
 * @property int|null $created_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'inbox_item_id', 'suggested_type', 'suggested_fields', 'confidence',
    'reason', 'status', 'agent_run_id', 'created_type', 'created_id',
])]
class InboxSuggestion extends Model
{
    /** @use HasFactory<InboxSuggestionFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'suggested_type' => InboxConvertedType::class,
            'suggested_fields' => 'array',
            'confidence' => SuggestionConfidence::class,
            'status' => InboxSuggestionStatus::class,
        ];
    }

    /**
     * @return BelongsTo<InboxItem, $this>
     */
    public function inboxItem(): BelongsTo
    {
        return $this->belongsTo(InboxItem::class);
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
