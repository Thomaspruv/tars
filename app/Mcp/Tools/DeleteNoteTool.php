<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\NameResolver;
use App\Models\BrainDocument;
use App\Support\Brain\BrainSettings;
use App\Support\Brain\NoteArchiver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('delete_note')]
#[Description("Retire une note du cerveau, résolue par titre approximatif : elle est archivée (déplacée dans Archives/ avec sa date d'archivage), jamais supprimée — rien ne disparaît du vault.")]
class DeleteNoteTool extends LoggedTool
{
    public function __construct(
        private readonly BrainSettings $settings,
        private readonly NoteArchiver $archiver,
        private readonly NameResolver $resolver = new NameResolver,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'note' => $schema->string()->description('Titre approximatif de la note à retirer.')->required(),
        ];
    }

    protected function execute(Request $request): Response
    {
        $validated = $request->validate(['note' => ['required', 'string']]);

        if (! $this->settings->isConfigured()) {
            return Response::error("Le vault du cerveau n'est pas configuré.");
        }

        $document = $this->resolver->disambiguate(
            $this->resolver->notes($validated['note']),
            fn (BrainDocument $candidate): string => (string) $candidate->title,
            "Plusieurs notes correspondent à « {$validated['note']} »",
        );

        if ($document === null) {
            return Response::error("Aucune note ne correspond à « {$validated['note']} ».");
        }

        $title = (string) $document->title;

        $result = $this->archiver->archive($document->path, 'hermes', "archive {$document->path}");

        if (! $result->isWritten()) {
            return Response::error($result->message());
        }

        return Response::text("Note « {$title} » archivée dans ".dirname((string) $result->path()).'/.');
    }
}
