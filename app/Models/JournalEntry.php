<?php

namespace App\Models;

use Database\Factories\JournalEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property Carbon $date
 * @property int|null $mood
 * @property string|null $mood_label
 * @property string $summary
 * @property list<string>|null $highlights
 * @property string $source
 * @property string $note_path
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['date', 'mood', 'mood_label', 'summary', 'highlights', 'source', 'note_path'])]
class JournalEntry extends Model
{
    /** @use HasFactory<JournalEntryFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'mood' => 'integer',
            'highlights' => 'array',
        ];
    }
}
