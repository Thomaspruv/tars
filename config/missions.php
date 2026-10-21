<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Le binaire Claude Code
    |--------------------------------------------------------------------------
    |
    | Une mission est un `claude -p` lancé par le worker de queue. Le PATH du
    | worker n'a pas forcément `~/.bun/bin` : on donne donc un chemin absolu
    | plutôt que de compter sur la résolution du shell.
    |
    */

    'claude_binary' => env('CLAUDE_BINARY') ?: env('HOME').'/.bun/bin/claude',

    /*
    |--------------------------------------------------------------------------
    | Le PATH du CLI skills.sh
    |--------------------------------------------------------------------------
    |
    | `npx skills` exige Node >= 22.20 ; le `node` par défaut de la machine est
    | en v20 et échoue sur un import de `fs/promises`. On préfixe donc le PATH
    | du Node 22 installé par nvm.
    |
    */

    'node_path' => env('SKILLS_NODE_PATH') ?: env('HOME').'/.nvm/versions/node/v22.22.3/bin',

    'default_model' => env('MISSIONS_DEFAULT_MODEL') ?: 'sonnet',

    /*
    |--------------------------------------------------------------------------
    | Le plafond de dépense d'une mission
    |--------------------------------------------------------------------------
    |
    | Passé en `--max-budget-usd` : un agent lancé en bypass ne peut pas, en
    | plus, dépenser sans fin.
    |
    */

    'default_budget_usd' => (float) (env('MISSIONS_DEFAULT_BUDGET_USD') ?: 2.0),

    'max_budget_usd' => (float) (env('MISSIONS_MAX_BUDGET_USD') ?: 20.0),

    /*
    |--------------------------------------------------------------------------
    | Les skills installés
    |--------------------------------------------------------------------------
    |
    | skills.sh installe dans `.claude/skills` (portée projet) et
    | `~/.claude/skills` (portée globale). On lit les deux, le projet d'abord.
    |
    */

    'skill_paths' => [
        'projet' => base_path('.claude/skills'),
        'global' => env('HOME').'/.claude/skills',
    ],

    'skill_lock_path' => env('HOME').'/.agents/.skill-lock.json',

    /*
    |--------------------------------------------------------------------------
    | Où vit une exécution
    |--------------------------------------------------------------------------
    |
    | La config MCP et la définition de l'équipe sont écrites là, par mission.
    | On passe par le disque `local` plutôt que par `File::put`, qui est réservé
    | à `VaultWriter` — seul point d'écriture machine du vault.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Les garde-fous d'une veille
    |--------------------------------------------------------------------------
    |
    | Une veille relance une conversation toute seule : sans plafond, elle
    | dépenserait jusqu'à ce que Thomas s'en aperçoive. Le nombre de passages
    | est borné, et l'intervalle ne descend pas sous le quart d'heure.
    |
    */

    'watch_max_runs' => (int) (env('MISSIONS_WATCH_MAX_RUNS') ?: 200),

    'watch_min_minutes' => (int) (env('MISSIONS_WATCH_MIN_MINUTES') ?: 15),

    'watch_max_failures' => 3,

    'watch_max_usd' => (float) (env('MISSIONS_WATCH_MAX_USD') ?: 5.0),

    /*
    | Même quand la sentinelle ne voit rien bouger, on réveille l'agent de temps
    | en temps : une sentinelle qui regarde la mauvaise page garderait sinon le
    | silence pour toujours.
    */

    'watch_heartbeat_skips' => (int) (env('MISSIONS_WATCH_HEARTBEAT_SKIPS') ?: 24),

    /*
    | Tous les N réveils, l'agent repart d'une session neuve avec le strict
    | nécessaire. Sans ça le contexte — et donc le prix du passage — enfle à
    | chaque fois, en silence.
    */

    'watch_fresh_session_every' => (int) (env('MISSIONS_WATCH_FRESH_EVERY') ?: 10),

    'run_disk' => env('MISSIONS_RUN_DISK', 'local'),

    'run_directory' => 'missions',

    /*
    |--------------------------------------------------------------------------
    | Les dépôts qu'un agent peut prendre pour terrain
    |--------------------------------------------------------------------------
    */

    'repo_roots' => array_filter(explode(',', (string) (env('MISSIONS_REPO_ROOTS') ?: base_path()))),

];
