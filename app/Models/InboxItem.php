<?php

namespace App\Models;

use App\Enums\InboxConvertedType;
use Database\Factories\InboxItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $content
 * @property Carbon|null $processed_at
 * @property InboxConvertedType|null $converted_to_type
 * @property int|null $converted_to_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, InboxSuggestion> $suggestions
 */
#[Fillable(['content', 'processed_at', 'converted_to_type', 'converted_to_id'])]
class InboxItem extends Model
{
    /** @use HasFactory<InboxItemFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
            'converted_to_type' => InboxConvertedType::class,
        ];
    }

    /**
     * Ce qu'Hermès propose pour cet item. La plus récente d'abord : un item
     * peut avoir été repassé au trieur après un refus.
     *
     * @return HasMany<InboxSuggestion, $this>
     */
    public function suggestions(): HasMany
    {
        return $this->hasMany(InboxSuggestion::class)->latest();
    }
}
