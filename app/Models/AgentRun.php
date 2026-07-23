<?php

namespace App\Models;

use App\Enums\AgentRunStatus;
use App\Enums\AgentRunTrigger;
use Database\Factories\AgentRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $agent_name
 * @property AgentRunTrigger $trigger
 * @property AgentRunStatus $status
 * @property string|null $provider
 * @property string|null $model
 * @property array<string, mixed>|null $input
 * @property string|null $output
 * @property string|null $error
 * @property int|null $tokens_in
 * @property int|null $tokens_out
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'agent_name', 'trigger', 'status', 'provider', 'model', 'input', 'output',
    'error', 'tokens_in', 'tokens_out', 'started_at', 'finished_at',
])]
class AgentRun extends Model
{
    /** @use HasFactory<AgentRunFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'running',
    ];

    protected function casts(): array
    {
        return [
            'trigger' => AgentRunTrigger::class,
            'status' => AgentRunStatus::class,
            'input' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
