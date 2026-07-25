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

];
