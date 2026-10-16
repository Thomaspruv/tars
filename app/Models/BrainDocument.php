<?php

namespace App\Models;

use App\Support\Brain\Schema\FrontmatterSchema;
use App\Support\Brain\Schema\NoteType;
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
 * @property int|null $schema_version
 * @property bool $is_conformant
 * @property string|null $note_type
 * @property string|null $status
 * @property string|null $project_slug
 * @property Carbon|null $updated_on
 * @property string|null $content
 * @property string $hash
 * @property Carbon|null $mtime
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'path', 'title', 'frontmatter', 'schema_version', 'is_conformant', 'note_type',
    'status', 'project_slug', 'updated_on', 'content', 'hash', 'mtime',
])]
class BrainDocument extends Model
{
    /** @use HasFactory<BrainDocumentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'frontmatter' => 'array',
            'is_conformant' => 'boolean',
            'updated_on' => 'date',
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

    public function noteType(): ?NoteType
    {
        return $this->note_type !== null ? NoteType::tryFrom($this->note_type) : null;
    }

    /**
     * Zones whose owner curates them alone (Profil/, Hermes's own memory) and
     * files TARS generates are never flagged — see FrontmatterSchema.
     */
    public function isConformanceExempt(): bool
    {
        return FrontmatterSchema::isExempt($this->path) || FrontmatterSchema::isGenerated($this->path);
    }

    /**
     * Why this document is non conformant, recomputed from its frontmatter —
     * cheap, and it keeps the errors out of the index.
     *
     * @return list<string>
     */
    public function schemaErrors(): array
    {
        return (new FrontmatterSchema)->validate($this->frontmatter ?? [])->errors;
    }

    /**
     * Documents the contract actually applies to — the denominator of the
     * conformance rate.
     *
     * @param  Builder<BrainDocument>  $query
     * @return Builder<BrainDocument>
     */
    public function scopeUnderContract(Builder $query): Builder
    {
        foreach (FrontmatterSchema::EXEMPT_FOLDERS as $folder) {
            $query->where('path', 'not like', "{$folder}/%");
        }

        return $query->where('path', '!=', 'README.md')->where('path', 'not like', '%_index.md');
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
