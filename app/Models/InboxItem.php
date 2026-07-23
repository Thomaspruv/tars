<?php

namespace App\Models;

use App\Enums\InboxConvertedType;
use Database\Factories\InboxItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $content
 * @property Carbon|null $processed_at
 * @property InboxConvertedType|null $converted_to_type
 * @property int|null $converted_to_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
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
}
