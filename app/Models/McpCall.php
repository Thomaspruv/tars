<?php

namespace App\Models;

use App\Enums\McpCallStatus;
use Database\Factories\McpCallFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $tool
 * @property array<string, mixed>|null $parameters
 * @property string|null $result_summary
 * @property McpCallStatus $status
 * @property string|null $error_message
 * @property int $duration_ms
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['tool', 'parameters', 'result_summary', 'status', 'error_message', 'duration_ms'])]
class McpCall extends Model
{
    /** @use HasFactory<McpCallFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'status' => McpCallStatus::class,
        ];
    }
}
