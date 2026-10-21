<?php

namespace App\Support\Missions;

use App\Models\Mission;
use App\Models\TeamAgent;
use App\Support\Mcp\CallingMission;
use App\Support\Mcp\McpSettings;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * La ligne de commande d'une mission, et les fichiers de config qu'elle vise.
 *
 * Tout l'enjeu est ici : lancé sans précaution, `claude -p` hérite de tous les
 * serveurs MCP et plugins personnels de la machine — mesuré à 104 000 tokens de
 * contexte parasite et 0,42 $ pour faire dire « bonjour ». Une mission est donc
 * lancée dans une config explicite et fermée :
 *
 * - `--strict-mcp-config --mcp-config <run>/mcp.json` : Tars, et rien d'autre ;
 * - `--setting-sources project` : ni les réglages utilisateur, ni ses plugins ;
 * - `--max-budget-usd` : un agent en bypass ne dépense pas sans fin ;
 * - `--session-id` : l'identifiant est le nôtre, pour pouvoir reprendre.
 *
 * `--forward-subagent-text` est ce qui rend la délégation visible : le texte
 * d'un sous-agent arrive dans le flux avec le `parent_tool_use_id` de l'appel
 * `Task` qui l'a engendré.
 */
class MissionCommandBuilder
{
    /**
     * Le nom du greffon qu'on fabrique par mission pour y porter ses skills.
     * Il préfixe la commande slash : `/tars-mission:pest-testing`.
     */
    public const string PLUGIN = 'tars-mission';

    public function __construct(
        private readonly McpSettings $mcp = new McpSettings,
        private readonly SkillCatalog $skills = new SkillCatalog,
    ) {}

    /**
     * @return list<string>
     */
    public function build(Mission $mission): array
    {
        $agent = $mission->teamAgent;

        $command = [
            (string) config('missions.claude_binary'),
            '--print',
            '--output-format', 'stream-json',
            '--verbose',
            '--forward-subagent-text',
            '--dangerously-skip-permissions',
            // Ceinture et bretelles : en bypass rien ne demande d'autorisation,
            // mais si un jour une action en demandait quand même, personne n'est
            // devant l'écran pour répondre — elle doit être refusée, pas attendre.
            '--permission-prompts', 'none',
            '--setting-sources', 'project',
            // Toujours, même sans serveur à déclarer : c'est ce drapeau qui
            // empêche d'hériter des serveurs MCP de la machine. L'absence de
            // `--mcp-config` donne alors un agent sans outils externes, ce qui
            // est le bon défaut — pas un agent branché sur Figma et Linear.
            '--strict-mcp-config',
            '--model', $mission->model,
            '--add-dir', $mission->workspace_path,
            '--max-budget-usd', (string) $mission->max_budget_usd,
        ];

        if ($mission->effort !== null && $mission->effort !== '') {
            $command[] = '--effort';
            $command[] = $mission->effort;
        }

        if ($mission->session_id !== null) {
            // Au premier message d'une session on la nomme ; aux suivants on
            // la reprend. C'est tout ce qui sépare un échange d'une
            // conversation : le CLI garde le fil, on ne le rejoue pas. Une
            // veille repart périodiquement d'une session neuve — sinon elle
            // traînerait ses cinquante passages précédents à chaque réveil.
            $command[] = $mission->resumesSession() ? '--resume' : '--session-id';
            $command[] = $mission->session_id;
        }

        $mcpConfig = $this->writeMcpConfig($mission);

        if ($mcpConfig !== null) {
            $command[] = '--mcp-config';
            $command[] = $mcpConfig;
        }

        $command[] = '--append-system-prompt';
        $command[] = $this->systemPrompt($agent, $mission);

        $teammates = $this->writeTeammates($agent, $mission);

        if ($teammates !== null) {
            $command[] = '--agents';
            $command[] = $teammates;
        }

        $plugin = $this->writeSkillPlugin($mission);

        if ($plugin !== null) {
            $command[] = '--plugin-dir';
            $command[] = $plugin;
        }

        // Le prompt vient en dernier, et après un drapeau à valeur unique :
        // plusieurs options du CLI sont variadiques (`--add-dir`,
        // `--mcp-config`, `--tools`) et avaleraient l'argument positionnel.
        $command[] = $this->prompt($mission);

        return $command;
    }

    /**
     * Un skill s'invoque par sa commande slash, y compris en mode `--print` :
     * la première ligne du prompt l'active, le reste est la consigne. Le nom
     * est celui que porte le greffon de la mission, pas le slug nu — un skill
     * chargé par `--plugin-dir` vit sous le nom du greffon.
     *
     * Un slug qui ne correspond à aucun skill installé n'est pas mis en
     * commande slash : il arriverait à l'agent comme du texte, et la mission
     * commencerait par une ligne de bruit.
     */
    public function prompt(Mission $mission): string
    {
        $message = $mission->currentPrompt();
        $slug = $mission->skill_slug;

        // Le skill s'active au premier message ; le rappeler à chaque tour
        // rechargerait son mode d'emploi dans une conversation qui l'a déjà.
        if ($mission->resumesSession() || $slug === null || $slug === '' || $this->skills->find($slug) === null) {
            return $message;
        }

        return '/'.self::PLUGIN.':'.$slug."\n\n".$message;
    }

    /**
     * Fabrique le greffon de la mission : un manifeste, et un lien vers chaque
     * skill qu'on lui confie.
     *
     * C'est le seul moyen de donner un skill à une mission sans ouvrir la porte
     * à tout le reste. `--setting-sources user` chargerait bien les skills
     * globaux, mais ramènerait avec eux tous les greffons et serveurs MCP
     * personnels de la machine — les 104 000 tokens qu'on vient d'écarter. Un
     * greffon dédié ne porte que ce qu'on y met.
     */
    private function writeSkillPlugin(Mission $mission): ?string
    {
        $slugs = array_values(array_unique(array_filter([
            ...($mission->teamAgent->skill_slugs ?? []),
            $mission->skill_slug,
        ])));

        $skills = array_values(array_filter(array_map(
            fn (string $slug): ?Skill => $this->skills->find($slug),
            $slugs,
        )));

        if ($skills === []) {
            return null;
        }

        $this->write($mission, 'plugin/.claude-plugin/plugin.json', [
            'name' => self::PLUGIN,
            'description' => 'Les skills confiés à cette mission par Tars.',
            'version' => '1.0.0',
        ]);

        $disk = Storage::disk((string) config('missions.run_disk'));
        $skillsPath = $disk->path($mission->runDirectory().'/plugin/skills');

        File::ensureDirectoryExists($skillsPath);

        foreach ($skills as $skill) {
            $link = $skillsPath.'/'.$skill->slug;

            // Un lien, pas une copie : le skill qu'on exécute est celui qui est
            // installé, pas un instantané qui vieillira en silence.
            if (! File::exists($link)) {
                File::link(dirname($skill->path), $link);
            }
        }

        return $disk->path($mission->runDirectory().'/plugin');
    }

    private function systemPrompt(TeamAgent $agent, Mission $mission): string
    {
        $lines = [$agent->role];

        if ($agent->can_delegate) {
            $lines[] = '';
            $lines[] = "Tu diriges une équipe. Pour tout ce qu'un de tes coéquipiers fait mieux "
                ."que toi, délègue-lui avec l'outil Task plutôt que de le faire toi-même, puis "
                ."rends compte à Thomas de ce que l'équipe a produit.";
        }

        // Un outil qu'on ne nomme pas n'existe pas : l'agent ne va pas
        // parcourir sa liste d'outils pour se demander s'il saurait mieux
        // faire. On lui dit quand chercher.
        $lines[] = '';
        $lines[] = "Avant de te lancer sur un sujet que tu ne maîtrises pas, cherche s'il existe "
            ."un mode d'emploi tout fait avec l'outil `find_skills`, et lis-le avec `read_skill` : "
            ."il te le rend en entier et tu l'appliques immédiatement, sans rien installer. "
            .'Beaucoup de procédures que tu improviserais ont déjà été écrites et éprouvées par '
            ."quelqu'un.";

        // Sans ça, un agent en veille croit devoir programmer la surveillance
        // lui-même — mesuré en vrai : « la surveillance n'est pas en place, je
        // n'ai rien programmé », alors que Tars allait le réveiller.
        if ($mission->isWatching()) {
            $lines[] = '';
            $lines[] = 'Cette conversation est une veille : Tars te réveillera de lui-même toutes '
                ."les {$mission->watch_every_minutes} minutes pour que tu revérifies, et tu "
                ."retrouveras à chaque fois tout ce qui précède. Tu n'as donc rien à programmer "
                ."ni à installer. Vérifie, dis en une ligne ce que tu vois, et appelle l'outil "
                .'`alert_thomas` dès que ce que tu surveilles arrive — la veille s\'arrêtera alors '
                .'toute seule.';
        }

        return implode("\n", $lines);
    }

    /**
     * La config MCP de la mission : le serveur de Tars seul, avec son jeton.
     *
     * Sans MCP activé ou sans jeton exploitable, on ne lance pas une mission
     * sans outils en silence — on la lance sans MCP, et l'écran l'a déjà dit.
     */
    private function writeMcpConfig(Mission $mission): ?string
    {
        if (! $this->mcp->isEnabled()) {
            return null;
        }

        $token = $this->mcp->agentToken();

        if ($token === null) {
            return null;
        }

        return $this->write($mission, 'mcp.json', [
            'mcpServers' => [
                'tars' => [
                    'type' => 'http',
                    'url' => $this->mcp->endpointUrl(),
                    'headers' => [
                        'Authorization' => 'Bearer '.$token,
                        // Chaque mission reçoit une config écrite pour elle
                        // seule : on y glisse son numéro, et Tars sait donc
                        // quel agent l'appelle — c'est ce qui permet à une
                        // alerte de savoir de quelle veille elle vient.
                        CallingMission::HEADER => (string) $mission->id,
                    ],
                ],
            ],
        ]);
    }

    /**
     * Les coéquipiers que ce chef peut missionner, au format `--agents`. Rendus
     * disponibles sous l'outil Task : c'est Claude Code qui porte la hiérarchie,
     * on ne fait que la déclarer.
     */
    private function writeTeammates(TeamAgent $agent, Mission $mission): ?string
    {
        if (! $agent->can_delegate) {
            return null;
        }

        $teammates = TeamAgent::where('enabled', true)
            ->where('id', '!=', $agent->id)
            ->where('can_delegate', false)
            ->orderBy('position')
            ->orderBy('name')
            ->get();

        if ($teammates->isEmpty()) {
            return null;
        }

        $definition = [];

        foreach ($teammates as $teammate) {
            // `description` est ce que Claude Code lit pour aiguiller : il
            // répond à « quand faut-il appeler celui-là ? », pas à « qui
            // est-il ». C'est le seul texte qui décide du routage.
            $definition[$teammate->slug] = [
                'description' => $teammate->name.' — '.$teammate->routingHint(),
                'prompt' => $teammate->role,
                'model' => $teammate->model,
            ];
        }

        return $this->write($mission, 'agents.json', $definition);
    }

    /**
     * @param  array<string, mixed>  $contents
     */
    private function write(Mission $mission, string $file, array $contents): string
    {
        $disk = Storage::disk((string) config('missions.run_disk'));

        $disk->put(
            $mission->runDirectory().'/'.$file,
            (string) json_encode($contents, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );

        return $disk->path($mission->runDirectory().'/'.$file);
    }
}
