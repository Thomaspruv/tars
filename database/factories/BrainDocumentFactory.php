<?php

namespace Database\Factories;

use App\Models\BrainDocument;
use App\Support\Brain\NoteSearch;
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
        // `paragraphs(3, true)` est typé `array|string` : trois paragraphes
        // concaténés à la main, et le contenu est une chaîne sans ambiguïté.
        $content = fake()->paragraph()."\n\n".fake()->paragraph()."\n\n".fake()->paragraph();

        return [
            'path' => 'notes/'.str($title)->slug().'.md',
            'title' => $title,
            'frontmatter' => ['type' => 'note'],
            'content' => $content,
            // Dérivé comme à l'indexation : une note fabriquée sans son texte
            // de recherche serait introuvable, et chaque test aurait à y
            // penser.
            'search_text' => NoteSearch::haystack($title, $content),
            'hash' => fake()->sha256(),
            'mtime' => now(),
        ];
    }

    /**
     * Garde le texte de recherche aligné quand un test se donne son propre
     * titre ou son propre contenu — c'est-à-dire presque tous.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (BrainDocument $document): void {
            $document->search_text = NoteSearch::haystack($document->title, $document->content);
        });
    }
}
