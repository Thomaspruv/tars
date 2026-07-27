<?php

namespace App\Models;

use App\Enums\EntityRelationType;
use Database\Factories\EntityRelationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $entity_id
 * @property int $related_entity_id
 * @property EntityRelationType $relation_type
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['entity_id', 'related_entity_id', 'relation_type', 'note'])]
class EntityRelation extends Model
{
    /** @use HasFactory<EntityRelationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'relation_type' => EntityRelationType::class,
        ];
    }

    /**
     * @return BelongsTo<Entity, $this>
     */
    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    /**
     * @return BelongsTo<Entity, $this>
     */
    public function relatedEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'related_entity_id');
    }
}
