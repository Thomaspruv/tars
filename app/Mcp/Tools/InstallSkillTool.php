<?php

namespace App\Mcp\Tools;

use App\Support\Missions\SkillCatalog;
use App\Support\Missions\SkillInstaller;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('install_skill')]
#[Description(
    "Installe durablement un skill du registre, pour qu'il reste disponible aux "
    ."prochaines missions et apparaisse dans l'écran Skills de Thomas. Inutile pour "
    ."t'en servir maintenant — `read_skill` suffit. Ne l'installe que si tu juges "
    .'que ce skill resservira.'
)]
class InstallSkillTool extends LoggedTool
{
    public function __construct(private readonly SkillInstaller $installer = new SkillInstaller) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'source' => $schema->string()->description('Le dépôt, sous la forme "propriétaire/dépôt".')->required(),
            'skill' => $schema->string()->description('Le nom du skill dans ce dépôt.')->required(),
        ];
    }

    protected function execute(Request $request): Response
    {
        $validated = $request->validate([
            'source' => ['required', 'string', 'max:200'],
            'skill' => ['required', 'string', 'max:200'],
        ]);

        if (! SkillCatalog::isValidPackage($validated['source'])) {
            return Response::error('Le dépôt s\'écrit "propriétaire/dépôt".');
        }

        $result = $this->installer->install($validated['source'], $validated['skill']);

        return $result['ok']
            ? Response::text("Skill {$validated['skill']} installé. Il sera chargé aux prochaines missions ; pour t'en servir maintenant, lis-le avec read_skill.")
            : Response::error("L'installation a échoué : ".str($result['output'])->limit(400)->toString());
    }
}
