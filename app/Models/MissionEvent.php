<?php

namespace App\Models;

use Database\Factories\MissionEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Une ligne du flux `stream-json` de la mission, rangée telle qu'elle est
 * arrivée. `parent_tool_use_id` porte l'arbre de la délégation.
 *
 * @property int $id
 * @property int $mission_id
 * @property int $seq
 * @property int $turn
 * @property string $type
 * @property string|null $subtype
 * @property string|null $tool_use_id
 * @property string|null $parent_tool_use_id
 * @property string|null $tool_name
 * @property string|null $text
 * @property array<string, mixed> $payload
 * @property Carbon $occurred_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'mission_id', 'seq', 'turn', 'type', 'subtype', 'tool_use_id',
    'parent_tool_use_id', 'tool_name', 'text', 'payload', 'occurred_at',
])]
class MissionEvent extends Model
{
    /** @use HasFactory<MissionEventFactory> */
    use HasFactory;

    /**
     * Les noms sous lesquels Claude Code missionne un sous-agent. `Agent` est
     * celui que la 2.1 émet, `Task` celui des versions antérieures et de la
     * documentation : on accepte les deux plutôt que de rendre l'arbre muet au
     * prochain renommage.
     */
    public const array DELEGATION_TOOLS = ['Agent', 'Task'];

    /** Le sous-type d'un message écrit par Thomas, et non par une machine. */
    public const string PROMPT = 'prompt';

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * Cet événement est-il un agent qui en missionne un autre ?
     */
    public function isDelegation(): bool
    {
        return $this->tool_name !== null && in_array($this->tool_name, self::DELEGATION_TOOLS, true);
    }

    /**
     * La consigne reçue par un sous-agent : un bloc de texte côté « user »,
     * porté par l'appel qui l'a engendré. Ce n'est pas la parole d'un agent,
     * et ça ne doit donc pas porter le vert de l'agent.
     */
    public function isBriefing(): bool
    {
        return $this->type === 'user' && $this->subtype === 'text';
    }

    /**
     * Un message de Thomas. C'est la seule chose de ce fil qui ne vient pas
     * d'une machine, et ça se voit à l'écran.
     */
    public function isMine(): bool
    {
        return $this->subtype === self::PROMPT;
    }

    /**
     * À qui la délégation s'adresse. Claude Code range le nom du coéquipier
     * dans `subagent_type` — c'est le slug qu'on lui a donné en `--agents`, donc
     * celui d'un `TeamAgent`. Sans ça, l'écran montrerait une branche anonyme :
     * on verrait qu'il y a eu délégation, pas à qui.
     */
    public function delegateSlug(): ?string
    {
        $slug = $this->payload['input']['subagent_type'] ?? null;

        return is_string($slug) && $slug !== '' ? $slug : null;
    }

    /**
     * @return BelongsTo<Mission, $this>
     */
    public function mission(): BelongsTo
    {
        return $this->belongsTo(Mission::class);
    }
}
