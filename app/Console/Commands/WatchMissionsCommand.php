<?php

namespace App\Console\Commands;

use App\Enums\MissionStatus;
use App\Enums\WatchEnded;
use App\Models\Mission;
use App\Models\MissionEvent;
use App\Support\Missions\MissionLauncher;
use App\Support\Missions\Sentinel;
use Illuminate\Console\Command;

/**
 * Réveille les veilles dont l'heure est venue — et seulement si ça vaut le
 * coup.
 *
 * Une veille n'est pas une mission à part : c'est la même conversation qu'on
 * relance, ce qui fait que l'agent se souvient de ses passages précédents. Mais
 * le réveiller coûte ~0,35 $ ; aller chercher une page et comparer une
 * empreinte coûte zéro. Quand la veille a une sentinelle, c'est donc elle qui
 * fait le guet, et l'agent n'est appelé que pour juger — ce qu'il est le seul
 * à savoir faire.
 */
class WatchMissionsCommand extends Command
{
    protected $signature = 'missions:watch {--scheduled}';

    protected $description = 'Relance les veilles arrivées à échéance';

    public function __construct(private readonly Sentinel $sentinel = new Sentinel)
    {
        parent::__construct();
    }

    public function handle(MissionLauncher $launcher): int
    {
        $due = Mission::whereNotNull('watch_every_minutes')
            ->whereNotNull('watch_next_at')
            ->where('watch_next_at', '<=', now())
            ->whereNotIn('status', [MissionStatus::Queued, MissionStatus::Running])
            ->get();

        foreach ($due as $mission) {
            $this->tick($launcher, $mission);
        }

        return self::SUCCESS;
    }

    private function tick(MissionLauncher $launcher, Mission $mission): void
    {
        if ($this->shouldEnd($mission)) {
            return;
        }

        if ($mission->hasSentinel() && $this->standsDown($mission)) {
            return;
        }

        $this->wake($launcher, $mission);
    }

    /**
     * Les quatre raisons d'éteindre une veille. Chacune est dite, parce qu'une
     * veille qui s'arrête en silence laisse croire qu'elle tourne encore.
     */
    private function shouldEnd(Mission $mission): bool
    {
        $reason = match (true) {
            $mission->watch_until !== null && $mission->watch_until->isPast() => WatchEnded::Expired,

            // La dépense file bien plus vite que le nombre de passages.
            $mission->watch_max_usd !== null
                && (float) $mission->cost_usd >= (float) $mission->watch_max_usd => WatchEnded::TooExpensive,

            $mission->watch_runs >= (int) config('missions.watch_max_runs') => WatchEnded::Exhausted,

            // Une veille qui n'a jamais réussi ne se réparera pas toute seule.
            $mission->status === MissionStatus::Failed
                && $mission->watch_runs >= (int) config('missions.watch_max_failures')
                && $this->hasNeverSucceeded($mission) => WatchEnded::Failing,

            default => null,
        };

        if ($reason === null) {
            return false;
        }

        $mission->endWatch($reason);
        $this->line("Veille #{$mission->id} terminée : {$reason->label()}.");

        return true;
    }

    /**
     * La sentinelle dit-elle qu'il n'y a rien à voir ?
     *
     * Trois cas réveillent quand même l'agent : la toute première empreinte
     * (il faut bien qu'il dise ce qu'il voit), une page devenue injoignable
     * (c'est peut-être l'événement lui-même), et le battement de cœur — sans
     * lui, une sentinelle qui regarde la mauvaise page garderait le silence
     * pour toujours.
     */
    private function standsDown(Mission $mission): bool
    {
        $fingerprint = $this->sentinel->fingerprint((string) $mission->watch_url);

        $mission->forceFill([
            'watch_checked_at' => now(),
            'watch_next_at' => now()->addMinutes((int) $mission->watch_every_minutes),
        ])->save();

        if ($fingerprint === null || $mission->watch_fingerprint === null) {
            $mission->forceFill(['watch_fingerprint' => $fingerprint ?? $mission->watch_fingerprint])->save();

            return false;
        }

        if ($fingerprint !== $mission->watch_fingerprint) {
            $mission->forceFill(['watch_fingerprint' => $fingerprint])->save();

            return false;
        }

        if ($mission->watch_skips >= (int) config('missions.watch_heartbeat_skips')) {
            $mission->forceFill(['watch_skips' => 0])->save();

            return false;
        }

        $mission->forceFill(['watch_skips' => $mission->watch_skips + 1])->save();
        $this->line("Veille #{$mission->id} : rien n'a bougé, l'agent dort ({$mission->watch_skips}).");

        return true;
    }

    private function wake(MissionLauncher $launcher, Mission $mission): void
    {
        // Repartir d'une session neuve de temps en temps : sinon, au
        // cinquantième réveil, l'agent relit quarante-neuf échanges pour dire
        // « toujours rien », et le prix du passage grimpe avec le contexte.
        $every = (int) config('missions.watch_fresh_session_every');
        $fresh = $every > 0 && $mission->watch_runs > 0 && $mission->watch_runs % $every === 0;

        if ($fresh) {
            $launcher->restartSession($mission);
        }

        if (! $launcher->reply($mission, $this->nudge($mission, $fresh))) {
            return;
        }

        $mission->forceFill([
            'watch_runs' => $mission->watch_runs + 1,
            'watch_skips' => 0,
            'watch_next_at' => now()->addMinutes((int) $mission->watch_every_minutes),
        ])->save();

        $this->line("Veille #{$mission->id} : passage {$mission->watch_runs}".($fresh ? ' (session neuve)' : '').'.');
    }

    private function hasNeverSucceeded(Mission $mission): bool
    {
        return $mission->events()->where('type', 'result')->where('subtype', 'success')->doesntExist();
    }

    /**
     * Ce qu'on dit à l'agent à chaque réveil.
     *
     * Court exprès : la consigne d'origine est dans la conversation. Sauf
     * quand on repart d'une session neuve — là il ne se souvient de rien, et
     * il faut lui rendre le strict nécessaire : ce qu'on lui a demandé, et le
     * dernier état connu.
     */
    private function nudge(Mission $mission, bool $fresh): string
    {
        $instructions = <<<'TXT'
        - Si c'est arrivé : appelle l'outil `alert_thomas` avec ce qu'il doit savoir et le lien où agir. La veille s'arrêtera toute seule.
        - Sinon : réponds en une seule ligne ce que tu as vu, sans détailler ta démarche.
        TXT;

        if (! $fresh) {
            return "Revérifie maintenant ce que je t'ai demandé de surveiller.\n\n".$instructions;
        }

        $lastSeen = $this->lastAnswer($mission);

        return implode("\n", array_filter([
            'Tu surveilles ceci : '.$mission->prompt,
            '',
            "C'est ton {$mission->watch_runs}ᵉ passage.",
            $lastSeen !== null ? 'Dernier état connu : '.$lastSeen : null,
            '',
            'Revérifie maintenant.',
            '',
            $instructions,
        ], fn (?string $line): bool => $line !== null));
    }

    private function lastAnswer(Mission $mission): ?string
    {
        $text = MissionEvent::where('mission_id', $mission->id)
            ->where('subtype', 'text')
            ->orderByDesc('seq')
            ->value('text');

        return is_string($text) ? str($text)->limit(400)->toString() : null;
    }
}
