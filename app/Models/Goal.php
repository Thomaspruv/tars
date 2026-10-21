<?php

namespace App\Models;

use App\Enums\GoalStatus;
use App\Enums\ReviewFrequency;
use App\Support\Palette;
use Database\Factories\GoalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
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
#[Fillable(['entity_id', 'title', 'description', 'status', 'target_date', 'review_frequency', 'position'])]
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
     * Teinte de saison de l'objectif — stable dans le temps, parce qu'elle
     * dérive de l'id. Voir {@see Palette} pour la série.
     *
     * @return Attribute<string, never>
     */
    protected function colorSlot(): Attribute
    {
        return Attribute::make(
            get: fn (): string => Palette::slot($this->id ?? 0),
        )->shouldCache();
    }

    /**
     * L'horizon de l'objectif : « ce trimestre », « cette année ».
     *
     * Le doc de refonte posait l'horizon comme un attribut à part, à côté d'un
     * domaine de vie. Ni l'un ni l'autre n'existent en base, et une colonne de
     * plus à saisir pour une information déjà contenue dans l'échéance n'aurait
     * rien dit de nouveau : l'horizon se déduit donc de `target_date`.
     *
     * @return Attribute<string, never>
     */
    protected function horizonLabel(): Attribute
    {
        return Attribute::make(get: function (): string {
            if ($this->target_date === null) {
                return 'sans échéance';
            }

            if ($this->target_date->isPast()) {
                return 'échéance dépassée';
            }

            $months = today()->diffInMonths($this->target_date);

            return match (true) {
                $months < 4 => 'ce trimestre',
                $months < 13 => 'cette année',
                default => 'au-delà d\'un an',
            };
        })->shouldCache();
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

    /**
     * @return MorphToMany<BrainDocument, $this>
     */
    public function brainDocuments(): MorphToMany
    {
        return $this->morphToMany(BrainDocument::class, 'anchorable', 'brain_document_anchors');
    }
}
