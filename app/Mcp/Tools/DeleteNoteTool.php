<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\NameResolver;
use App\Support\Brain\BrainSettings;
use App\Support\Brain\GitRepository;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\File;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('delete_note')]
#[Description('Supprime définitivement une note du cerveau (fichier du vault + commit git), résolue par titre approximatif.')]
class DeleteNoteTool extends LoggedTool
{
    public function __construct(
        private readonly NameResolver $resolver = new NameResolver,
        private readonly BrainSettings $settings = new BrainSettings,
        private readonly GitRepository $git = new GitRepository,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'note' => $schema->string()->description('Titre approximatif de la note à supprimer.')->required(),
        ];
    }

    protected function execute(Request $request): Response
    {
        $validated = $request->validate(['note' => ['required', 'string']]);

        if (! $this->settings->isConfigured()) {
            return Response::error('Le vault du cerveau n\'est pas configuré.');
        }

        $document = $this->resolver->disambiguate(
            $this->resolver->notes($validated['note']),
            fn ($d): string => (string) $d->title,
            "Plusieurs notes correspondent à « {$validated['note']} »",
        );

        if ($document === null) {
            return Response::error("Aucune note ne correspond à « {$validated['note']} ».");
        }

        $title = $document->title;
        $vaultPath = $this->settings->localPath();
        $absolutePath = rtrim($vaultPath, '/').'/'.$document->path;

        File::delete($absolutePath);
        $this->git->commit($vaultPath, $document->path, "tars: delete note via hermes ({$document->path})");
        $document->delete();

        return Response::text("Note « {$title} » supprimée.");
    }
}
