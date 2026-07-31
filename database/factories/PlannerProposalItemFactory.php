<?php

namespace Database\Factories;

use App\Models\PlannerProposal;
use App\Models\PlannerProposalItem;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlannerProposalItem>
 */
class PlannerProposalItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'planner_proposal_id' => PlannerProposal::factory(),
            'task_id' => Task::factory(),
            'proposed_scheduled_date' => now()->addDays(2),
            'reason' => fake()->sentence(),
            'status' => 'pending',
        ];
    }
}
