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

    /**
     * Les documents de tête du vault, dans l'ordre où une IA doit les lire.
     * `AGENTS.md` est la convention des agents de code, et le vault en a un :
     * c'est lui qui porte le rituel de démarrage.
     */
    private const array FRONT_DOOR = ['AGENTS.md', 'README.md'];

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

        // La porte d'entrée du vault appartient au vault, pas à TARS : on lit
        // ce qu'il a — son README et, s'il en a un, le protocole de lecture
        // destiné aux IA — sans jamais le réécrire.
        $blocks = [];

        foreach (self::FRONT_DOOR as $path) {
            $document = BrainDocument::where('path', $path)->first();

            if ($document !== null && trim((string) $document->content) !== '') {
                $blocks[] = "# {$path}\n\n".$this->truncate(trim((string) $document->content));
            }
        }

        foreach (IndexFileGenerator::INDEXED_FOLDERS as $folder) {
            $index = BrainDocument::where('path', "{$folder}/_index.md")->first();

            if (! $index) {
                continue;
            }

            $blocks[] = "# {$folder}\n\n".$this->truncate(trim((string) $index->content));
        }

        if ($blocks === []) {
            return Response::error("Le cerveau n'a pas encore été indexé. Lance une indexation.");
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
