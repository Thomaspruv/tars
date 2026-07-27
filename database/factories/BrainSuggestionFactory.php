<?php

namespace Database\Factories;

use App\Enums\BrainSuggestionAction;
use App\Enums\SuggestionConfidence;
use App\Models\AgentRun;
use App\Models\BrainDocument;
use App\Models\BrainSuggestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BrainSuggestion>
 */
class BrainSuggestionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'brain_document_id' => BrainDocument::factory(),
            'action' => BrainSuggestionAction::Anchor,
            'target' => null,
            'frontmatter_patch' => null,
            'merged_content' => null,
            'confidence' => SuggestionConfidence::Medium,
            'reason' => fake()->sentence(),
            'status' => 'pending',
            'agent_run_id' => AgentRun::factory(),
        ];
    }
}
