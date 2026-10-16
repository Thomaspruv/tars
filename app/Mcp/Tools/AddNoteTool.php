<?php

namespace App\Mcp\Tools;

use App\Support\Brain\Schema\FrontmatterBuilder;
use App\Support\Brain\Schema\NoteType;
use App\Support\Brain\VaultWriter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('add_note')]
#[Description('Dépose une note brute dans le sas du cerveau (dossier TARS/), à trier plus tard. Pour une connaissance typée — personne, organisation, réunion, décision, veille — utilise plutôt upsert_note.')]
class AddNoteTool extends LoggedTool
{
    public function __construct(
        private readonly VaultWriter $writer,
        private readonly FrontmatterBuilder $frontmatter,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'content' => $schema->string()->description('Le contenu de la note.')->required(),
            'entity' => $schema->string()->description("Nom approximatif de l'entité à ancrer (optionnel)."),
            'goal' => $schema->string()->description("Nom approximatif de l'objectif à ancrer (optionnel)."),
        ];
    }

    protected function execute(Request $request): Response
    {
        $validated = $request->validate([
            'content' => ['required', 'string'],
            'entity' => ['nullable', 'string'],
            'goal' => ['nullable', 'string'],
        ]);

        $title = Str::limit($validated['content'], 40, '');
        $relativePath = 'TARS/'.now()->toDateString().'-'.(Str::slug($title) ?: 'note').'.md';

        $frontmatter = $this->frontmatter->build(
            type: NoteType::ATraiter,
            title: $title,
            date: now(),
            source: 'hermes',
            extra: [
                'status' => 'active',
                'entity' => $validated['entity'] ?? null,
                'goal' => $validated['goal'] ?? null,
            ],
        );

        $result = $this->writer->write($relativePath, $frontmatter, $validated['content'], "sas {$relativePath}");

        if (! $result->isWritten()) {
            return Response::error($result->message());
        }

        return Response::text('Note ajoutée au cerveau.');
    }
}
