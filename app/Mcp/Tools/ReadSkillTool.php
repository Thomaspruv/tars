<?php

namespace App\Mcp\Tools;

use App\Support\Missions\SkillCatalog;
use App\Support\Missions\SkillRegistry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('read_skill')]
#[Description(
    "Lit un skill en entier et te le rend : tu peux l'appliquer immédiatement, sans "
    ."rien installer ni redémarrer. Donne soit le nom d'un skill installé "
    .'("pest-testing"), soit son adresse complète dans le registre '
    .'("kepano/obsidian-skills@obsidian-cli"). Suis ce qu\'il dit comme si c\'était '
    .'ta consigne.'
)]
class ReadSkillTool extends LoggedTool
{
    private const int LIMIT = 24000;

    public function __construct(
        private readonly SkillCatalog $catalog = new SkillCatalog,
        private readonly SkillRegistry $registry = new SkillRegistry,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'skill' => $schema->string()
                ->description('"pest-testing" pour un skill installé, "propriétaire/dépôt@skill" pour un skill du registre.')
                ->required(),
        ];
    }

    protected function execute(Request $request): Response
    {
        $validated = $request->validate(['skill' => ['required', 'string', 'max:300']]);

        $reference = trim($validated['skill']);

        $markdown = str_contains($reference, '@')
            ? $this->fromRegistry($reference)
            : $this->catalog->markdown($reference);

        if ($markdown === null) {
            return Response::error(
                "Impossible de lire « {$reference} ». Vérifie le nom avec find_skills — "
                .'un skill du registre s\'écrit "propriétaire/dépôt@skill".'
            );
        }

        return Response::text(str($markdown)->limit(self::LIMIT)->toString());
    }

    private function fromRegistry(string $reference): ?string
    {
        [$source, $skillId] = array_pad(explode('@', $reference, 2), 2, '');

        return $skillId === '' ? null : $this->registry->markdown($source, $skillId);
    }
}
