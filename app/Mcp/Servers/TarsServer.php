<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\AddEventTool;
use App\Mcp\Tools\AddNoteTool;
use App\Mcp\Tools\AddTaskTool;
use App\Mcp\Tools\AddToListTool;
use App\Mcp\Tools\CaptureTool;
use App\Mcp\Tools\CompleteTaskTool;
use App\Mcp\Tools\GetContextTool;
use App\Mcp\Tools\GetEntityTool;
use App\Mcp\Tools\GetGoalTool;
use App\Mcp\Tools\GetListTool;
use App\Mcp\Tools\GetTodayTool;
use App\Mcp\Tools\ListEntitiesTool;
use App\Mcp\Tools\ListGoalsTool;
use App\Mcp\Tools\ListTasksTool;
use App\Mcp\Tools\LogDecisionTool;
use App\Mcp\Tools\SearchBrainTool;
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
        MARKDOWN;

    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        CaptureTool::class,
        AddTaskTool::class,
        ListTasksTool::class,
        CompleteTaskTool::class,
        GetTodayTool::class,
        AddToListTool::class,
        GetListTool::class,
        ListGoalsTool::class,
        GetGoalTool::class,
        ListEntitiesTool::class,
        GetEntityTool::class,
        AddEventTool::class,
        AddNoteTool::class,
        SearchBrainTool::class,
        GetContextTool::class,
        LogDecisionTool::class,
    ];
}
