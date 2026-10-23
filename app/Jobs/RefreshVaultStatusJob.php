<?php

namespace App\Jobs;

use App\Support\Brain\BrainSettings;
use App\Support\Brain\GitRepository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Va voir où en est le vault par rapport à son remote, et range le résultat
 * dans les réglages.
 *
 * Mesuré : `GitRepository::status()` fait un `git fetch`, soit ~1,1 s de
 * réseau, pendant que le reste du rendu de /cerveau coûte 20 ms. Tant que cet
 * appel était dans le rendu, Thomas payait la seconde à chaque chargement de
 * la page (et à chaque frappe dans la recherche dès que le cache d'une minute
 * avait expiré) pour une pastille de synchro. Le réseau est donc sorti de la
 * requête : il se fait ici, et les écrans relisent.
 */
class RefreshVaultStatusJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Au-delà, un chargement de page redemande une vérification. Les écrans
     * affichaient déjà un état vieux d'une minute (le cache qu'on remplace) :
     * la fraîcheur ne change pas, c'est l'attente qui disparaît.
     */
    public const int FRESH_FOR_MINUTES = 1;

    public int $uniqueFor = 120;

    /**
     * Demande un rafraîchissement si l'état enregistré a vieilli — appelé au
     * `mount()` des écrans qui l'affichent, donc une fois par chargement de
     * page et jamais à chaque rendu.
     */
    public static function dispatchIfStale(): void
    {
        $settings = app(BrainSettings::class);

        if (! $settings->isConfigured()) {
            return;
        }

        $recordedAt = $settings->syncStatusAt();

        if ($recordedAt !== null && $recordedAt->addMinutes(self::FRESH_FOR_MINUTES)->isFuture()) {
            return;
        }

        self::dispatch();
    }

    public function handle(BrainSettings $settings, GitRepository $git): void
    {
        if (! $settings->isConfigured()) {
            return;
        }

        $path = $settings->localPath();

        $settings->recordSyncStatus($git->status($path), $git->lastPushAt($path));
    }
}
