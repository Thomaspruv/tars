<?php

namespace App\Models;

use App\Enums\EntityContext;
use App\Enums\EntityStatus;
use App\Enums\EntityType;
use Database\Factories\EntityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $life_area_id
 * @property string $name
 * @property EntityType $type
 * @property EntityContext $context
 * @property string|null $notes
 * @property array<string, mixed>|null $details
 * @property EntityStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['life_area_id', 'name', 'type', 'context', 'notes', 'details', 'status'])]
class Entity extends Model
{
    /** @use HasFactory<EntityFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'active',
    ];

    protected static function booted(): void
    {
        static::saving(function (Entity $entity): void {
            $entity->context = self::deduceContext($entity->type);
        });
    }

    public static function deduceContext(EntityType $type): EntityContext
    {
        return $type === EntityType::Company ? EntityContext::Pro : EntityContext::Perso;
    }

    protected function casts(): array
    {
        return [
            'type' => EntityType::class,
            'context' => EntityContext::class,
            'details' => 'array',
            'status' => EntityStatus::class,
        ];
    }

    /**
     * @return BelongsTo<LifeArea, $this>
     */
    public function lifeArea(): BelongsTo
    {
        return $this->belongsTo(LifeArea::class);
    }

    /**
     * @return HasMany<Goal, $this>
     */
    public function goals(): HasMany
    {
        return $this->hasMany(Goal::class);
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * @return HasMany<Event, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /**
     * @return HasMany<Checklist, $this>
     */
    public function checklists(): HasMany
    {
        return $this->hasMany(Checklist::class);
    }

    /**
     * @return HasMany<Note, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }

    /**
     * @return MorphToMany<BrainDocument, $this>
     */
    public function brainDocuments(): MorphToMany
    {
        return $this->morphToMany(BrainDocument::class, 'anchorable', 'brain_document_anchors');
    }

    /**
     * Relations where this entity is the subject (e.g. "this entity is employed_by X").
     *
     * @return HasMany<EntityRelation, $this>
     */
    public function relations(): HasMany
    {
        return $this->hasMany(EntityRelation::class);
    }

    /**
     * Relations where this entity is the object (e.g. X is employed_by "this entity").
     *
     * @return HasMany<EntityRelation, $this>
     */
    public function relatedFrom(): HasMany
    {
        return $this->hasMany(EntityRelation::class, 'related_entity_id');
    }
}
