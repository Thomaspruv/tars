<?php

namespace App\Support\Brain;

use App\Models\Setting;
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

    public function lastPushError(): ?string
    {
        return $this->get('last_push_error');
    }

    public function recordPushError(?string $message): void
    {
        $this->set('last_push_error', $message);
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
