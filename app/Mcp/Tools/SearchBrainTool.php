<?php

namespace App\Mcp\Tools;

use App\Models\BrainDocument;
use App\Support\Brain\NoteSearch;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('search_brain')]
#[Description('Recherche dans les notes du cerveau. Une question en langage naturel fonctionne : la requête est découpée en mots, et les notes qui les contiennent tous sont rendues, les plus pertinentes d\'abord.')]
class SearchBrainTool extends LoggedTool
{
    /**
     * Assez pour que l'agent choisisse, assez peu pour ne pas noyer son
     * contexte. L'ancienne limite de 5, combinée à un tri par date, lui
     * cachait régulièrement la seule note qui répondait.
     */
    private const int LIMIT = 12;

    private const int EXCERPT = 160;

    public function __construct(private readonly NoteSearch $search) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Ce que tu cherches, en mots-clés ou en langage naturel.')->required(),
        ];
    }

    protected function execute(Request $request): Response
    {
        $validated = $request->validate(['query' => ['required', 'string']]);

        $documents = $this->search->find($validated['query'], self::LIMIT);

        if ($documents->isEmpty()) {
            return Response::text('Aucune note ne correspond.');
        }

        // Une ligne par note, et le **chemin** sur chaque ligne : sans lui
        // l'agent lit un résultat qu'il ne sait pas aller ouvrir. Le type dit
        // s'il tient une fiche projet ou une note de réunion.
        $lines = $documents->map(function (BrainDocument $document): string {
            $meta = collect([
                $document->note_type,
                $document->mtime?->translatedFormat('d M Y'),
            ])->filter()->implode(', ');

            return "- {$document->title}".($meta !== '' ? " ({$meta})" : '')
                ."\n  {$document->path}"
                ."\n  ".Str::limit(trim((string) $document->content), self::EXCERPT);
        })->implode("\n");

        return Response::text($documents->count()." note(s) :\n".$lines."\n\nUtilise read_note avec le chemin pour en lire une en entier.");
    }
}
