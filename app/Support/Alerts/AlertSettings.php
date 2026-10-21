<?php

namespace App\Support\Alerts;

use App\Models\Setting;

/**
 * Où Tars pousse ce qui ne peut pas attendre que Thomas ouvre l'écran.
 *
 * Un webhook plutôt qu'un canal en dur : Thomas le branche sur ce qu'il veut —
 * son Hermès, n8n, ntfy, Slack — et l'alerte arrive là où il vit déjà.
 */
class AlertSettings
{
    public function webhookUrl(): ?string
    {
        $url = Setting::where('key', 'alerts.webhook_url')->value('value');

        return is_string($url) && trim($url) !== '' ? trim($url) : null;
    }

    public function setWebhookUrl(?string $url): void
    {
        Setting::updateOrCreate(
            ['key' => 'alerts.webhook_url'],
            ['value' => $url !== null && trim($url) !== '' ? trim($url) : null],
        );
    }

    public function isConfigured(): bool
    {
        return $this->webhookUrl() !== null;
    }
}
