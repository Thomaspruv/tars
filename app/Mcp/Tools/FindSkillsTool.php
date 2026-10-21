<?php

namespace App\Mcp\Tools;

use App\Support\Missions\RegistrySkill;
use App\Support\Missions\Skill;
use App\Support\Missions\SkillCatalog;
use App\Support\Missions\SkillRegistry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('find_skills')]
#[Description(
    "Cherche un mode d'emploi tout fait pour ce que tu as à faire, parmi ceux déjà "
    ."installés et dans le registre public skills.sh. Un skill t'évite de réinventer "
    ."une procédure que quelqu'un a déjà écrite et éprouvée. Cherche avant de te "
    .'lancer sur un sujet que tu ne maîtrises pas, puis lis-en un avec `read_skill`.'
)]
class FindSkillsTool extends LoggedTool
{
    private const int SHOWN = 8;

    public function __construct(
        private readonly SkillCatalog $catalog = new SkillCatalog,
        private readonly SkillRegistry $registry = new SkillRegistry,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Le sujet, en un ou deux mots : "obsidian", "pdf", "gmail", "tests pest".')
                ->required(),
        ];
    }

    protected function execute(Request $request): Response
    {
        $validated = $request->validate(['query' => ['required', 'string', 'min:2', 'max:120']]);

        $installed = $this->installed($validated['query']);
        $found = $this->registry->search($validated['query']);

        if ($installed === [] && $found === []) {
            return Response::text("Aucun skill ne correspond à « {$validated['query']} ». Fais sans.");
        }

        $lines = [];

        if ($installed !== []) {
            $lines[] = 'Déjà installés, utilisables tout de suite avec read_skill :';

            foreach ($installed as $skill) {
                $lines[] = "- {$skill->slug} — ".$skill->shortDescription(160);
            }
        }

        // Ceux du registre qui ne sont pas déjà dans la liste du dessus.
        $slugs = array_map(fn (Skill $skill): string => $skill->slug, $installed);
        $elsewhere = array_values(array_filter(
            $found,
            fn (RegistrySkill $skill): bool => ! in_array($skill->skillId, $slugs, true),
        ));

        if ($elsewhere !== []) {
            $lines[] = '';
            $lines[] = 'Sur skills.sh — read_skill les lit sans rien installer :';

            foreach (array_slice($elsewhere, 0, self::SHOWN) as $skill) {
                $lines[] = "- {$skill->source}@{$skill->skillId} ({$skill->installsLabel()} installations)"
                    .($skill->description !== null ? ' — '.str($skill->description)->limit(160)->toString() : '');
            }
        }

        return Response::text(implode("\n", $lines));
    }

    /**
     * @return list<Skill>
     */
    private function installed(string $query): array
    {
        $needle = mb_strtolower($query);

        return array_values(array_filter(
            $this->catalog->all(),
            fn (Skill $skill): bool => str_contains(mb_strtolower($skill->slug), $needle)
                || str_contains(mb_strtolower($skill->description), $needle),
        ));
    }
}
