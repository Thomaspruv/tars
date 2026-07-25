<?php

namespace Database\Factories;

use App\Models\BrainDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BrainDocument>
 */
class BrainDocumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->unique()->sentence(3);

        return [
            'path' => 'notes/'.str($title)->slug().'.md',
            'title' => $title,
            'frontmatter' => ['type' => 'note'],
            'content' => fake()->paragraphs(3, true),
            'hash' => fake()->sha256(),
            'mtime' => now(),
        ];
    }
}
