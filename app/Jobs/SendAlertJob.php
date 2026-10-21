<?php

namespace App\Jobs;

use App\Models\Mission;
use App\Support\Alerts\AlertSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pousse une alerte sur le webhook de Thomas.
 *
 * En file, et pas dans l'appel d'outil : un webhook lent ou mort ne doit pas
 * faire attendre — ni échouer — l'agent qui vient de trouver quelque chose.
 */
class SendAlertJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly string $message,
        public readonly ?int $missionId = null,
        public readonly ?string $link = null,
    ) {}

    public function handle(AlertSettings $settings): void
    {
        $url = $settings->webhookUrl();

        if ($url === null) {
            Log::warning('Alerte sans webhook configuré', ['message' => $this->message]);

            return;
        }

        $mission = $this->missionId !== null ? Mission::find($this->missionId) : null;

        $response = Http::timeout(15)->asJson()->post($url, array_filter([
            'source' => 'tars',
            'message' => $this->message,
            'link' => $this->link,
            'mission_id' => $mission?->id,
            'mission_url' => $mission !== null ? route('missions.show', $mission) : null,
            'agent' => $mission?->teamAgent->name,
            'at' => now()->toIso8601String(),
        ], fn (mixed $value): bool => $value !== null));

        if (! $response->successful()) {
            Log::warning('Le webhook d\'alerte a refusé', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 500),
            ]);
        }
    }
}
