<?php

namespace App\Models;

use App\Enums\MissionStatus;
use App\Enums\WatchEnded;
use Database\Factories\MissionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Une mission confiée à un agent de l'équipe.
 *
 * `session_id`, `pid`, `cost_usd` et les comptes de tokens ne sont pas dans le
 * `#[Fillable]` : ils sont écrits par la machine pendant l'exécution, jamais
 * par un formulaire — même doctrine que `google_event_id` sur `Event`.
 *
 * @property int $id
 * @property int $team_agent_id
 * @property string $source
 * @property string|null $skill_slug
 * @property string $prompt
 * @property int $turn
 * @property int|null $watch_every_minutes
 * @property Carbon|null $watch_until
 * @property Carbon|null $watch_next_at
 * @property int $watch_runs
 * @property string|null $watch_max_usd
 * @property string|null $watch_url
 * @property string|null $watch_fingerprint
 * @property Carbon|null $watch_checked_at
 * @property int $watch_skips
 * @property int $session_started_turn
 * @property WatchEnded|null $watch_ended_reason
 * @property MissionStatus $status
 * @property string $workspace_path
 * @property string $model
 * @property string|null $effort
 * @property string|null $session_id
 * @property int|null $pid
 * @property bool $cancel_requested
 * @property string|null $result
 * @property string|null $error
 * @property string|null $cost_usd
 * @property string $max_budget_usd
 * @property int|null $tokens_in
 * @property int|null $tokens_out
 * @property int|null $num_turns
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read TeamAgent $teamAgent
 */
#[Fillable([
    'team_agent_id', 'source', 'skill_slug', 'prompt', 'status',
    'workspace_path', 'model', 'effort', 'max_budget_usd',
])]
class Mission extends Model
{
    /** @use HasFactory<MissionFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'queued',
        'cancel_requested' => false,
        // La valeur par défaut est aussi en base, mais l'instance fraîchement
        // créée doit la connaître : le premier message est écrit avant qu'on
        // soit allé relire la ligne.
        'turn' => 1,
        'source' => 'thomas',
        'session_started_turn' => 1,
    ];

    protected function casts(): array
    {
        return [
            'status' => MissionStatus::class,
            'cancel_requested' => 'boolean',
            'watch_ended_reason' => WatchEnded::class,
            'watch_until' => 'datetime',
            'watch_checked_at' => 'datetime',
            'watch_next_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<TeamAgent, $this>
     */
    public function teamAgent(): BelongsTo
    {
        return $this->belongsTo(TeamAgent::class);
    }

    /**
     * @return HasMany<MissionEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(MissionEvent::class);
    }

    /**
     * Le dossier de cette exécution sur le disque `local`, où la config MCP et
     * la définition de l'équipe sont écrites avant le lancement.
     */
    public function runDirectory(): string
    {
        return trim((string) config('missions.run_directory'), '/').'/'.$this->id;
    }

    /**
     * Le même dossier en chemin absolu : le CLI reçoit des chemins, pas un
     * disque Laravel.
     */
    public function runPath(): string
    {
        return Storage::disk((string) config('missions.run_disk'))->path($this->runDirectory());
    }

    /**
     * Le message auquel l'agent est en train de répondre — ou le dernier qu'on
     * lui a écrit. Une mission est une conversation : son premier message la
     * nomme, mais ce n'est pas forcément celui qui l'occupe.
     */
    public function currentPrompt(): string
    {
        $message = $this->events()
            ->where('subtype', MissionEvent::PROMPT)
            ->orderByDesc('seq')
            ->value('text');

        return is_string($message) && $message !== '' ? $message : $this->prompt;
    }

    /**
     * Ouverte par Hermès plutôt que par Thomas depuis Tars. L'écran le dit :
     * une conversation qu'on n'a pas lancée soi-même ne doit pas apparaître
     * comme si on l'avait fait.
     */
    public function isFromHermes(): bool
    {
        return $this->source === 'hermes';
    }

    /**
     * Une veille en cours : la conversation se réveille toute seule pour
     * revérifier, jusqu'à ce qu'elle trouve ou que son délai passe.
     */
    public function isWatching(): bool
    {
        return $this->watch_every_minutes !== null;
    }

    /**
     * Arrête la veille, en disant pourquoi — une veille qui s'éteint sans
     * raison affichée laisse Thomas croire qu'elle tourne encore.
     */
    public function endWatch(WatchEnded $reason): void
    {
        $this->forceFill([
            'watch_every_minutes' => null,
            'watch_next_at' => null,
            'watch_ended_reason' => $reason,
        ])->save();
    }

    /**
     * Une sentinelle garde la porte de cette veille : tant que la page ne
     * bouge pas, l'agent n'est pas réveillé.
     */
    public function hasSentinel(): bool
    {
        return $this->watch_url !== null && $this->watch_url !== '';
    }

    /**
     * La session CLI courante a-t-elle déjà servi ? C'est ce qui décide entre
     * reprendre la conversation et en ouvrir une neuve.
     */
    public function resumesSession(): bool
    {
        return $this->turn > $this->session_started_turn;
    }

    public function isConversation(): bool
    {
        return $this->turn > 1;
    }

    public function durationInSeconds(): ?int
    {
        if ($this->started_at === null) {
            return null;
        }

        return (int) $this->started_at->diffInSeconds($this->finished_at ?? now());
    }
}
