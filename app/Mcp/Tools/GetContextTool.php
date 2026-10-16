<?php

namespace App\Mcp\Tools;

use App\Models\BrainDocument;
use App\Models\Checklist;
use App\Models\Entity;
use App\Models\Goal;
use App\Support\Brain\BrainHealth;
use App\Support\Brain\BrainSettings;
use App\Support\Brain\IndexFileGenerator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('get_context')]
#[Description("Le profil de Thomas (dossier Profil/ du vault) et un résumé de l'état courant (entités et objectifs actifs). Pensé pour être appelé en début de conversation.")]
class GetContextTool extends LoggedTool
{
    public function __construct(private readonly BrainSettings $settings) {}

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function execute(Request $request): Response
    {
        $entities = Entity::where('status', 'active')->count();
        $goals = Goal::where('status', 'active')->count();
        $lists = Checklist::pluck('name');

        $summary = "{$entities} entité(s) active(s), {$goals} objectif(s) actif(s).";
        $summary .= $lists->isNotEmpty()
            ? ' Listes existantes : '.$lists->implode(', ').'.'
            : ' Aucune liste existante.';

        if (! $this->settings->isConfigured()) {
            return Response::text($summary);
        }

        // The "projets actifs" section lands with the Projets iteration (#8).

        $summary .= "\n\n".$this->memorySection();

        $profile = BrainDocument::where('path', 'like', 'Profil/%')->get();

        if ($profile->isEmpty()) {
            return Response::text($summary);
        }

        $profileText = $profile->map(fn (BrainDocument $document): string => "{$document->title} : {$document->content}")->implode("\n\n");

        return Response::text("{$profileText}\n\n{$summary}");
    }

    /**
     * Enough for Hermes to know how big the memory is, how healthy it is, and
     * which summaries exist — never the journal.
     */
    private function memorySection(): string
    {
        $report = app(BrainHealth::class)->report();

        $indexes = collect(IndexFileGenerator::INDEXED_FOLDERS)
            ->filter(fn (string $folder): bool => BrainDocument::where('path', "{$folder}/_index.md")->exists())
            ->implode(', ');

        $line = "Mémoire : {$report->documents} note(s), {$report->conformanceRate} % conformes au contrat v3.";

        return $indexes === ''
            ? $line.' Aucun sommaire disponible — appelle get_memory_map après une indexation.'
            : $line." Sommaires disponibles : {$indexes}.";
    }
}
