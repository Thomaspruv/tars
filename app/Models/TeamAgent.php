<?php

namespace App\Models;

use App\Enums\AgentWorkspaceKind;
use Database\Factories\TeamAgentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Un membre de l'équipe : un rôle, un modèle, un terrain, et le droit — ou non
 * — de missionner d'autres agents à son tour.
 *
 * @property int $id
 * @property string $name
 * @property string|null $when_to_use
 * @property string $slug
 * @property string $role
 * @property string $model
 * @property string|null $effort
 * @property AgentWorkspaceKind $workspace_kind
 * @property string|null $workspace_path
 * @property bool $can_delegate
 * @property list<string>|null $skill_slugs
 * @property bool $enabled
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'name', 'slug', 'when_to_use', 'role', 'model', 'effort', 'workspace_kind',
    'workspace_path', 'can_delegate', 'skill_slugs', 'enabled', 'position',
])]
class TeamAgent extends Model
{
    /** @use HasFactory<TeamAgentFactory> */
    use HasFactory;

    protected $attributes = [
        'model' => 'sonnet',
        'workspace_kind' => 'vault',
        'can_delegate' => false,
        'enabled' => true,
    ];

    protected function casts(): array
    {
        return [
            'workspace_kind' => AgentWorkspaceKind::class,
            'can_delegate' => 'boolean',
            'skill_slugs' => 'array',
            'enabled' => 'boolean',
        ];
    }

    /**
     * Le répertoire où l'agent travaille. Un agent du cerveau ne note pas le
     * chemin du vault : il suit le vault configuré, pour qu'un déménagement ne
     * laisse pas l'équipe pointer dans le vide.
     */
    public function workspacePath(): string
    {
        if ($this->workspace_kind === AgentWorkspaceKind::Vault) {
            return (string) ($this->workspace_path ?: config('brain.local_path'));
        }

        return (string) ($this->workspace_path ?: base_path());
    }

    /**
     * Celui à qui Thomas parle.
     *
     * Il n'en choisit pas : il écrit au chef, et c'est au chef de trouver la
     * bonne personne dans son équipe. Sans chef déclaré — une équipe qu'on
     * aurait vidée — le premier agent disponible répond quand même, plutôt que
     * de laisser l'écran sans interlocuteur.
     */
    public static function chief(): ?self
    {
        return self::where('enabled', true)
            ->orderByDesc('can_delegate')
            ->orderBy('position')
            ->orderBy('id')
            ->first();
    }

    /**
     * Ce que le chef lit pour décider s'il appelle celui-là. Sans consigne
     * d'aiguillage explicite, on retombe sur le début de son rôle — mieux que
     * rien, mais c'est un texte écrit pour l'agent, pas pour celui qui route.
     */
    public function routingHint(): string
    {
        $hint = $this->when_to_use;

        return $hint !== null && trim($hint) !== ''
            ? trim($hint)
            : str($this->role)->limit(200)->toString();
    }

    /**
     * @return HasMany<Mission, $this>
     */
    public function missions(): HasMany
    {
        return $this->hasMany(Mission::class);
    }
}
