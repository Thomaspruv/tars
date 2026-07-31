<?php

namespace Database\Factories;

use App\Models\AgentRun;
use App\Models\PlannerProposal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlannerProposal>
 */
class PlannerProposalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'period_start' => now()->startOfWeek(),
            'period_end' => now()->endOfWeek(),
            'status' => 'pending',
            'agent_run_id' => AgentRun::factory(),
        ];
    }
}
