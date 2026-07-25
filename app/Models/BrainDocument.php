<?php

namespace App\Models;

use Database\Factories\BrainDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $path
 * @property string|null $title
 * @property array<string, mixed>|null $frontmatter
 * @property string|null $content
 * @property string $hash
 * @property Carbon|null $mtime
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['path', 'title', 'frontmatter', 'content', 'hash', 'mtime'])]
class BrainDocument extends Model
{
    /** @use HasFactory<BrainDocumentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'frontmatter' => 'array',
            'mtime' => 'datetime',
        ];
    }

    /**
     * @return MorphToMany<Entity, $this>
     */
    public function entities(): MorphToMany
    {
        return $this->morphedByMany(Entity::class, 'anchorable', 'brain_document_anchors');
    }

    /**
     * @return MorphToMany<Goal, $this>
     */
    public function goals(): MorphToMany
    {
        return $this->morphedByMany(Goal::class, 'anchorable', 'brain_document_anchors');
    }

    /**
     * @param  Builder<BrainDocument>  $query
     * @return Builder<BrainDocument>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where(function (Builder $query) use ($term): void {
            $query->where('title', 'like', "%{$term}%")
                ->orWhere('content', 'like', "%{$term}%");
        });
    }
}
