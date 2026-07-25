<?php

namespace App\Mcp\Servers;

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
    protected array $tools = [];
}
