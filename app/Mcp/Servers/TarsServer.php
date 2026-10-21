<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\AddEventTool;
use App\Mcp\Tools\AddNoteTool;
use App\Mcp\Tools\AddTaskTool;
use App\Mcp\Tools\AddToListTool;
use App\Mcp\Tools\AlertThomasTool;
use App\Mcp\Tools\AnswerQuestionnaireTool;
use App\Mcp\Tools\AssignTaskTool;
use App\Mcp\Tools\CaptureTool;
use App\Mcp\Tools\CompleteTaskTool;
use App\Mcp\Tools\CreateEntityTool;
use App\Mcp\Tools\CreateListTool;
use App\Mcp\Tools\DeleteEntityTool;
use App\Mcp\Tools\DeleteListTool;
use App\Mcp\Tools\DeleteNoteTool;
use App\Mcp\Tools\DeleteTaskTool;
use App\Mcp\Tools\FindSkillsTool;
use App\Mcp\Tools\GetContextTool;
use App\Mcp\Tools\GetEntitiesDashboardTool;
use App\Mcp\Tools\GetEntityTool;
use App\Mcp\Tools\GetGoalTool;
use App\Mcp\Tools\GetJournalTool;
use App\Mcp\Tools\GetListTool;
use App\Mcp\Tools\GetMemoryMapTool;
use App\Mcp\Tools\GetMissionTool;
use App\Mcp\Tools\GetPendingQuestionnairesTool;
use App\Mcp\Tools\GetTodayTool;
use App\Mcp\Tools\InstallSkillTool;
use App\Mcp\Tools\LinkEntitiesTool;
use App\Mcp\Tools\ListEntitiesTool;
use App\Mcp\Tools\ListGoalsTool;
use App\Mcp\Tools\ListNotesTool;
use App\Mcp\Tools\ListTasksTool;
use App\Mcp\Tools\LogDecisionTool;
use App\Mcp\Tools\LogJournalTool;
use App\Mcp\Tools\ReadNoteTool;
use App\Mcp\Tools\ReadSkillTool;
use App\Mcp\Tools\RenameListTool;
use App\Mcp\Tools\SearchBrainTool;
use App\Mcp\Tools\SetWatchSentinelTool;
use App\Mcp\Tools\StartMissionTool;
use App\Mcp\Tools\UnlinkEntitiesTool;
use App\Mcp\Tools\UpdateEntityTool;
use App\Mcp\Tools\UpsertNoteTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Tool;

class TarsServer extends Server
{
    protected string $name = 'TARS';

    protected string $version = '1.0.0';

    protected string $instructions = <<<'MARKDOWN'
        Assistant de vie personnel de Thomas. Résous toujours les noms de tâches,
        objectifs, entités et listes en langage naturel et approximatif — jamais
        d'identifiant technique. Si plusieurs éléments correspondent à un nom donné,
        énonce les candidats et n'effectue aucune écriture tant que l'utilisateur n'a
        pas précisé. Réponds toujours en français, en phrases courtes et parlables.

        Thomas a une équipe d'agents qui travaillent en arrière-plan, avec tous les
        droits dans leur répertoire. `start_mission` leur confie un travail : le chef
        reçoit le message et choisit lui-même qui s'en occupe, ne désigne personne.
        Sers-t'en pour ce qui demande d'agir — lire des fichiers, fouiller le cerveau,
        produire quelque chose — et pas pour une question que tu sais traiter seul. La
        réponse n'est jamais immédiate : annonce que c'est parti, et relis la mission
        avec `get_mission` quand Thomas en redemande des nouvelles. N'invente jamais
        le résultat d'une mission qui tourne encore.

        `alert_thomas` le prévient sur-le-champ, où qu'il soit. Réserve-le à ce qui ne
        peut pas attendre qu'il ouvre Tars.

        Une surveillance se demande avec `start_mission` et son `watch_every_minutes` :
        l'agent revérifiera tout seul. Si ce qu'il faut surveiller tient dans une page
        web, donne aussi `watch_url` — Tars ira la chercher lui-même entre deux
        passages et ne réveillera l'agent que si elle a changé, ce qui coûte
        pratiquement rien au lieu de quelques dizaines de centimes par vérification.

        `find_skills` cherche un mode d'emploi tout fait, parmi ceux installés et dans
        le registre public skills.sh ; `read_skill` le rend en entier, sans rien
        installer. Sers-t'en pour toi comme pour préciser une mission : « applique le
        skill X » vaut mieux qu'une consigne que tu improvises.
        MARKDOWN;

    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        CaptureTool::class,
        StartMissionTool::class,
        GetMissionTool::class,
        AlertThomasTool::class,
        SetWatchSentinelTool::class,
        FindSkillsTool::class,
        ReadSkillTool::class,
        InstallSkillTool::class,
        AddTaskTool::class,
        ListTasksTool::class,
        CompleteTaskTool::class,
        GetTodayTool::class,
        LogJournalTool::class,
        GetJournalTool::class,
        GetPendingQuestionnairesTool::class,
        AnswerQuestionnaireTool::class,
        AddToListTool::class,
        GetListTool::class,
        ListGoalsTool::class,
        GetGoalTool::class,
        ListEntitiesTool::class,
        GetEntityTool::class,
        AddEventTool::class,
        AddNoteTool::class,
        UpsertNoteTool::class,
        ReadNoteTool::class,
        ListNotesTool::class,
        GetMemoryMapTool::class,
        SearchBrainTool::class,
        GetContextTool::class,
        LogDecisionTool::class,
        LinkEntitiesTool::class,
        UnlinkEntitiesTool::class,
        CreateEntityTool::class,
        UpdateEntityTool::class,
        DeleteEntityTool::class,
        CreateListTool::class,
        RenameListTool::class,
        DeleteListTool::class,
        DeleteNoteTool::class,
        DeleteTaskTool::class,
        GetEntitiesDashboardTool::class,
        AssignTaskTool::class,
    ];
}
