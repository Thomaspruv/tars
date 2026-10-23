<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Brain vault defaults
    |--------------------------------------------------------------------------
    |
    | Fallback configuration for the Obsidian vault TARS indexes. These are
    | only defaults: once configured from the Réglages screen, the values
    | stored in the `settings` table (via App\Support\Brain\BrainSettings)
    | take precedence, since the vault's location is expected to change
    | (different server, different remote) without a redeploy.
    |
    */

    'remote_url' => env('BRAIN_VAULT_REMOTE'),

    'branch' => env('BRAIN_VAULT_BRANCH', 'main'),

    'local_path' => env('BRAIN_VAULT_PATH', storage_path('brain/vault')),

    'sync_frequency_minutes' => env('BRAIN_SYNC_FREQUENCY', 15),

    'auto_index' => env('BRAIN_AUTO_INDEX', true),

    /*
    |--------------------------------------------------------------------------
    | Vault layout
    |--------------------------------------------------------------------------
    |
    | Où chaque type de note est rangé dans ce vault. Vide = la disposition par
    | défaut de l'ontologie (voir NoteType::folder()), celle d'un vault neuf
    | que TARS dispose à sa façon.
    |
    | Le cerveau étant un dépôt unique partagé par plusieurs applications, sa
    | disposition lui appartient : elle est souvent antérieure à TARS. Les
    | valeurs réglées dans la table `settings` (clé `brain.folders`) prennent
    | donc le pas, et c'est App\Support\Brain\VaultLayout qui les lit. Le
    | règlement du cerveau est dans resources/vault/AGENTS.md.
    |
    */

    'folders' => [],

];
