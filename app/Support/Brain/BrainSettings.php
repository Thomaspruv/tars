<?php

namespace App\Support\Brain;

use App\Enums\GitSyncStatus;
use App\Models\Setting;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class BrainSettings
{
    public function remoteUrl(): ?string
    {
        return $this->get('remote_url') ?? config('brain.remote_url');
    }

    public function branch(): string
    {
        return $this->get('branch') ?? config('brain.branch');
    }

    public function localPath(): string
    {
        return $this->get('local_path') ?? config('brain.local_path');
    }

    public function syncFrequencyMinutes(): int
    {
        return (int) ($this->get('sync_frequency_minutes') ?? config('brain.sync_frequency_minutes'));
    }

    public function autoIndexEnabled(): bool
    {
        $value = $this->get('auto_index');

        return $value !== null
            ? filter_var($value, FILTER_VALIDATE_BOOLEAN)
            : (bool) config('brain.auto_index');
    }

    public function lastSyncedAt(): ?Carbon
    {
        $value = $this->get('last_synced_at');

        return $value ? Carbon::parse($value) : null;
    }

    public function lastIndexedAt(): ?Carbon
    {
        $value = $this->get('last_indexed_at');

        return $value ? Carbon::parse($value) : null;
    }

    public function isConfigured(): bool
    {
        return filled($this->remoteUrl());
    }

    /**
     * @param  array{remote_url?: ?string, branch?: ?string, sync_frequency_minutes?: int, auto_index?: bool}  $attributes
     */
    public function updateConfiguration(array $attributes): void
    {
        foreach ($attributes as $key => $value) {
            $this->set($key, is_bool($value) ? ($value ? '1' : '0') : (string) $value);
        }
    }

    public function markSynced(): void
    {
        $this->set('last_synced_at', now()->toDateTimeString());
    }

    public function markIndexed(): void
    {
        $this->set('last_indexed_at', now()->toDateTimeString());
    }

    /**
     * L'état git du vault, tel que la dernière vérification l'a trouvé.
     *
     * Le vérifier demande un `git fetch`, donc un aller-retour réseau d'une
     * seconde : c'est RefreshVaultStatusJob qui le fait, hors requête, et les
     * écrans ne font plus que relire ce qu'il a écrit. Le faire dans un rendu,
     * c'était faire attendre Thomas une seconde pour une pastille — à chaque
     * frappe dans la recherche de /cerveau.
     */
    public function syncStatus(): GitSyncStatus
    {
        return GitSyncStatus::tryFrom((string) $this->get('sync_status')) ?? GitSyncStatus::Unknown;
    }

    /**
     * Quand cet état a été constaté. Un écran qui montre une pastille sans
     * pouvoir dire de quand elle date laisse croire qu'elle est vivante.
     */
    public function syncStatusAt(): ?Carbon
    {
        $value = $this->get('sync_status_at');

        return $value ? Carbon::parse($value) : null;
    }

    public function lastPushAt(): ?Carbon
    {
        $value = $this->get('last_push_at');

        return $value ? Carbon::parse($value) : null;
    }

    public function recordSyncStatus(GitSyncStatus $status, ?CarbonInterface $lastPushAt): void
    {
        $this->set('sync_status', $status->value);
        $this->set('sync_status_at', now()->toDateTimeString());
        $this->set('last_push_at', $lastPushAt?->toDateTimeString());
    }

    public function lastPushError(): ?string
    {
        return $this->get('last_push_error');
    }

    public function recordPushError(?string $message): void
    {
        $this->set('last_push_error', $message);
    }

    public function lastSyncWarning(): ?string
    {
        return $this->get('last_sync_warning');
    }

    /**
     * Why the vault was not up to date when TARS last wrote to it — nothing was
     * lost, but Thomas should see it in Réglages → Cerveau.
     */
    public function recordSyncWarning(?string $message): void
    {
        if ($message === null && $this->get('last_sync_warning') === null) {
            return;
        }

        $this->set('last_sync_warning', $message);
    }

    /**
     * Depuis quand la synchro périodique échoue, et combien de fois de suite.
     *
     * `pullRebase()` ne rend `successful: false` que pour une vraie divergence
     * — un remote injoignable passe en avertissement. Or une divergence ne se
     * répare jamais d'elle-même : la synchro échouera à l'identique toutes les
     * quinze minutes jusqu'à ce que Thomas tranche. Il faut donc le dire, et le
     * dire dès le premier échec. Vécu : `git pull --ff-only` a refusé pendant
     * trois mois sans que rien à l'écran ne le signale, et le vault est resté
     * figé avec pour seul indice un `last_synced_at` vieux de l'été.
     *
     * @return ?array{since: Carbon, message: string, attempts: int}
     */
    public function syncFailure(): ?array
    {
        $since = $this->get('sync_failed_since');

        if ($since === null) {
            return null;
        }

        return [
            'since' => Carbon::parse($since),
            'message' => (string) $this->get('sync_failure_message'),
            'attempts' => max(1, (int) $this->get('sync_failure_count')),
        ];
    }

    /**
     * La date retenue est celle du *premier* échec de la série : c'est elle qui
     * dit depuis quand le vault est figé. Les suivants n'incrémentent que le
     * compteur.
     */
    public function recordSyncFailure(string $message): void
    {
        if ($this->get('sync_failed_since') === null) {
            $this->set('sync_failed_since', now()->toDateTimeString());
        }

        $this->set('sync_failure_message', $message);
        $this->set('sync_failure_count', (string) (((int) $this->get('sync_failure_count')) + 1));
    }

    public function clearSyncFailure(): void
    {
        if ($this->get('sync_failed_since') === null) {
            return;
        }

        $this->set('sync_failed_since', null);
        $this->set('sync_failure_message', null);
        $this->set('sync_failure_count', null);
    }

    public function lastConflictAt(): ?Carbon
    {
        $value = $this->get('last_conflict_at');

        return $value ? Carbon::parse($value) : null;
    }

    public function lastConflictMessage(): ?string
    {
        return $this->get('last_conflict_message');
    }

    /**
     * A refused write is an event Thomas has to see: it is kept until the next
     * write succeeds (Réglages → Cerveau).
     */
    public function recordConflict(string $message): void
    {
        $this->set('last_conflict_at', now()->toDateTimeString());
        $this->set('last_conflict_message', $message);
    }

    public function clearConflict(): void
    {
        if ($this->get('last_conflict_at') === null) {
            return;
        }

        $this->set('last_conflict_at', null);
        $this->set('last_conflict_message', null);
    }

    private function get(string $key): ?string
    {
        return Setting::where('key', "brain.{$key}")->value('value');
    }

    private function set(string $key, ?string $value): void
    {
        Setting::updateOrCreate(['key' => "brain.{$key}"], ['value' => $value]);
    }
}
