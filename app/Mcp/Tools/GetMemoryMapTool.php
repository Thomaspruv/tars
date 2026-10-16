<?php

namespace App\Mcp\Tools;

use App\Models\BrainDocument;
use App\Support\Brain\BrainSettings;
use App\Support\Brain\IndexFileGenerator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('get_memory_map')]
#[Description('Le plan du cerveau : le README du vault (ontologie, où écrire selon le type, contrat de frontmatter) et les sommaires des dossiers structurants. À appeler quand tu ne sais pas où chercher ou où écrire.')]
class GetMemoryMapTool extends LoggedTool
{
    /**
     * A long index is a listing, not a text: past this many lines it is cut
     * and `list_notes` takes over.
     */
    private const int MAX_INDEX_LINES = 200;

    public function __construct(private readonly BrainSettings $settings) {}

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function execute(Request $request): Response
    {
        if (! $this->settings->isConfigured()) {
            return Response::error("Le vault du cerveau n'est pas configuré.");
        }

        $readme = BrainDocument::where('path', 'README.md')->first();

        if (! $readme) {
            return Response::error("Le plan du vault n'a pas encore été écrit. Lance une indexation du cerveau.");
        }

        $blocks = ["# README du vault\n\n".trim((string) $readme->content)];

        foreach (IndexFileGenerator::INDEXED_FOLDERS as $folder) {
            $index = BrainDocument::where('path', "{$folder}/_index.md")->first();

            if (! $index) {
                continue;
            }

            $blocks[] = "# {$folder}\n\n".$this->truncate(trim((string) $index->content));
        }

        return Response::text(implode("\n\n---\n\n", $blocks));
    }

    private function truncate(string $content): string
    {
        $lines = explode("\n", $content);

        if (count($lines) <= self::MAX_INDEX_LINES) {
            return $content;
        }

        $kept = implode("\n", array_slice($lines, 0, self::MAX_INDEX_LINES));

        return $kept."\n\n(".(count($lines) - self::MAX_INDEX_LINES).' lignes de plus — utilise list_notes pour filtrer.)';
    }
}
