<?php

namespace Database\Factories;

use App\Enums\AgentWorkspaceKind;
use App\Models\TeamAgent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TeamAgent>
 */
class TeamAgentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->firstName();

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'when_to_use' => 'Quand il faut quelque chose à '.$name.'.',
            'role' => 'Tu es '.$name.', un agent de l\'équipe de Thomas.',
            'model' => 'sonnet',
            'effort' => null,
            'workspace_kind' => AgentWorkspaceKind::Vault,
            'workspace_path' => null,
            'can_delegate' => false,
            'skill_slugs' => [],
            'enabled' => true,
        ];
    }

    public function delegator(): self
    {
        return $this->state(['can_delegate' => true]);
    }

    public function onRepo(?string $path = null): self
    {
        return $this->state([
            'workspace_kind' => AgentWorkspaceKind::Repo,
            'workspace_path' => $path,
        ]);
    }
}
