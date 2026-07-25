<?php

namespace App\Mcp\Tools;

use App\Support\Brain\BrainSettings;
use App\Support\Brain\GitRepository;
use App\Support\Brain\VaultIndexer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('add_note')]
#[Description("Crée une note dans le cerveau (dossier TARS/ du vault), avec commit git. L'ancrage sur une entité/objectif se fait par nom, résolu automatiquement à l'indexation.")]
class AddNoteTool extends Tool
{
    public function __construct(
        private readonly BrainSettings $settings,
        private readonly GitRepository $git,
        private readonly VaultIndexer $indexer,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'content' => $schema->string()->description('Le contenu de la note.')->required(),
            'entity' => $schema->string()->description("Nom approximatif de l'entité à ancrer (optionnel)."),
            'goal' => $schema->string()->description("Nom approximatif de l'objectif à ancrer (optionnel)."),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'content' => ['required', 'string'],
            'entity' => ['nullable', 'string'],
            'goal' => ['nullable', 'string'],
        ]);

        if (! $this->settings->isConfigured()) {
            return Response::error('Le vault du cerveau n\'est pas configuré.');
        }

        $date = now()->toDateString();
        $slug = Str::slug(Str::limit($validated['content'], 40, ''));
        $relativePath = "TARS/{$date}-{$slug}.md";

        $frontmatter = ['date' => $date, 'source' => 'hermes'];

        if (! empty($validated['entity'])) {
            $frontmatter['entity'] = $validated['entity'];
        }

        if (! empty($validated['goal'])) {
            $frontmatter['goal'] = $validated['goal'];
        }

        $yaml = collect($frontmatter)->map(fn ($value, $key): string => "{$key}: \"{$value}\"")->implode("\n");
        $fileContent = "---\n{$yaml}\n---\n\n{$validated['content']}\n";

        $vaultPath = $this->settings->localPath();
        $absolutePath = rtrim($vaultPath, '/')."/{$relativePath}";

        File::ensureDirectoryExists(dirname($absolutePath));
        File::put($absolutePath, $fileContent);
        $this->git->commit($vaultPath, $relativePath, "tars: add note via hermes ({$relativePath})");
        $this->indexer->indexFile($absolutePath);

        return Response::text('Note ajoutée au cerveau.');
    }
}
