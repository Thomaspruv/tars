<?php

namespace App\Models;

use App\Enums\NoteSource;
use Database\Factories\NoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $title
 * @property string $content
 * @property int|null $goal_id
 * @property int|null $entity_id
 * @property NoteSource $source
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['title', 'content', 'goal_id', 'entity_id', 'source'])]
class Note extends Model
{
    /** @use HasFactory<NoteFactory> */
    use HasFactory;

    protected $attributes = [
        'source' => 'user',
    ];

    protected function casts(): array
    {
        return [
            'source' => NoteSource::class,
        ];
    }

    /**
     * @return BelongsTo<Goal, $this>
     */
    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }

    /**
     * @return BelongsTo<Entity, $this>
     */
    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }
}
