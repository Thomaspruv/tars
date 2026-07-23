<?php

namespace App\Models;

use App\Enums\GoalStatus;
use App\Enums\ReviewFrequency;
use Database\Factories\GoalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $life_area_id
 * @property int|null $entity_id
 * @property string $title
 * @property string|null $description
 * @property GoalStatus $status
 * @property Carbon|null $target_date
 * @property ReviewFrequency $review_frequency
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string $tag
 */
#[Fillable(['life_area_id', 'entity_id', 'title', 'description', 'status', 'target_date', 'review_frequency', 'position'])]
class Goal extends Model
{
    /** @use HasFactory<GoalFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'active',
        'review_frequency' => 'weekly',
        'position' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => GoalStatus::class,
            'review_frequency' => ReviewFrequency::class,
            'target_date' => 'date',
        ];
    }

    /**
     * @return Attribute<string, never>
     */
    protected function tag(): Attribute
    {
        return Attribute::make(
            get: fn (): string => Str::slug($this->title),
        )->shouldCache();
    }

    /**
     * @return BelongsTo<LifeArea, $this>
     */
    public function lifeArea(): BelongsTo
    {
        return $this->belongsTo(LifeArea::class);
    }

    /**
     * @return BelongsTo<Entity, $this>
     */
    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    /**
     * @return HasMany<Milestone, $this>
     */
    public function milestones(): HasMany
    {
        return $this->hasMany(Milestone::class);
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
     * @return HasMany<Note, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }

    /**
     * @return HasMany<Decision, $this>
     */
    public function decisions(): HasMany
    {
        return $this->hasMany(Decision::class);
    }
}
