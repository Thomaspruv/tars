<?php

namespace App\Support\Mcp;

use App\Models\Mission;

/**
 * La mission au nom de laquelle un outil MCP est appelé.
 *
 * Un agent missionné reçoit une config MCP écrite pour lui seul : on y glisse
 * son numéro de mission en en-tête, et le serveur sait donc qui parle. Hermès,
 * lui, appelle sans en-tête — et c'est très bien : tous les outils ne sont pas
 * réservés aux agents.
 */
class CallingMission
{
    public const string HEADER = 'X-Tars-Mission';

    public static function get(): ?Mission
    {
        $id = request()->header(self::HEADER);

        return is_numeric($id) ? Mission::find((int) $id) : null;
    }
}
