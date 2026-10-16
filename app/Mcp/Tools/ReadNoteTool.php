<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\NameResolver;
use App\Models\BrainDocument;
use App\Support\Brain\BrainSettings;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Symfony\Component\Yaml\Yaml;

#[Name('read_note')]
#[Description('Lit une note du cerveau, désignée par son titre approximatif ou son chemin dans le vault. Si plusieurs notes correspondent, énonce les candidates sans rien lire.')]
class ReadNoteTool extends LoggedTool
{
    public function __construct(
        private readonly BrainSettings $settings,
        private readonly NameResolver $resolver = new NameResolver,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'ref' => $schema->string()->description('Titre approximatif de la note, ou son chemin relatif dans le vault.')->required(),
        ];
    }

    protected function execute(Request $request): Response
    {
        $validated = $request->validate(['ref' => ['required', 'string']]);

        if (! $this->settings->isConfigured()) {
            return Response::error("Le vault du cerveau n'est pas configuré.");
        }

        $ref = trim($validated['ref']);

        $document = BrainDocument::where('path', $ref)->first()
            ?? $this->resolver->disambiguate(
                $this->candidates($ref),
                fn (BrainDocument $candidate): string => "{$candidate->title} ({$candidate->path})",
                "Plusieurs notes correspondent à « {$ref} »",
            );

        if ($document === null) {
            return Response::error("Aucune note ne correspond à « {$ref} ».");
        }

        $frontmatter = $document->frontmatter ?? [];
        $header = $frontmatter === [] ? '' : trim(Yaml::dump($frontmatter, 4))."\n\n";

        return Response::text("{$document->path}\n\n{$header}".trim((string) $document->content));
    }

    /**
     * Titles first — that is how Thomas and Hermes name a note out loud — then
     * paths, for the agent that read an `_index.md` and quotes a file name.
     *
     * @return Collection<int, BrainDocument>
     */
    private function candidates(string $ref): Collection
    {
        $byTitle = $this->resolver->notes($ref);

        if ($byTitle->isNotEmpty()) {
            return $byTitle;
        }

        // `%` and `_` arrive straight from the model, and `_` is common in the
        // very filenames this fallback exists for (`_index.md`).
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $ref);

        return BrainDocument::whereRaw("path LIKE ? ESCAPE '\\'", ["%{$escaped}%"])->orderBy('path')->get();
    }
}
