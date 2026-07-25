<?php

namespace App\Mcp\Tools;

use App\Models\BrainDocument;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('search_brain')]
#[Description('Recherche plein texte dans les notes du cerveau (titre et contenu).')]
class SearchBrainTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Les mots-clés à rechercher.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['query' => ['required', 'string']]);

        $documents = BrainDocument::search($validated['query'])->orderByDesc('mtime')->limit(5)->get();

        if ($documents->isEmpty()) {
            return Response::text('Aucune note ne correspond.');
        }

        $lines = $documents->map(function (BrainDocument $document): string {
            $date = $document->mtime?->translatedFormat('d M') ?? '';
            $excerpt = Str::limit((string) $document->content, 100);

            return trim("{$document->title} ({$date}) — {$excerpt}");
        })->implode(' | ');

        return Response::text($lines);
    }
}
