<?php

namespace App\Support\Mcp;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class McpSettings
{
    public function isEnabled(): bool
    {
        return filter_var($this->get('enabled') ?? '0', FILTER_VALIDATE_BOOLEAN);
    }

    public function endpointUrl(): string
    {
        return rtrim(config('app.url'), '/').'/mcp';
    }

    public function hasToken(): bool
    {
        return $this->get('token_hash') !== null;
    }

    public function tokenMatches(string $plain): bool
    {
        $hash = $this->get('token_hash');

        return $hash !== null && hash_equals($hash, hash('sha256', $plain));
    }

    public function generateToken(): string
    {
        $plain = Str::random(48);

        $this->set('token_hash', hash('sha256', $plain));

        // Le hash reste la seule source de vérité pour authentifier un appel.
        // Mais Tars doit aussi pouvoir *armer ses propres agents* : une mission
        // lance un `claude` qui appelle ce serveur, et un hash ne sert à rien
        // pour émettre un appel. On garde donc le clair, chiffré, à côté.
        $this->set('token_cipher', Crypt::encryptString($plain));

        return $plain;
    }

    /**
     * Le jeton dont une mission a besoin pour appeler les outils de Tars, ou
     * `null` s'il date d'avant qu'on le conserve — dans ce cas l'écran demande
     * d'en régénérer un plutôt que de lancer une mission aveugle.
     */
    public function agentToken(): ?string
    {
        $cipher = $this->get('token_cipher');

        if ($cipher === null) {
            return null;
        }

        try {
            return Crypt::decryptString($cipher);
        } catch (\Throwable) {
            return null;
        }
    }

    public function setEnabled(bool $enabled): void
    {
        $this->set('enabled', $enabled ? '1' : '0');
    }

    private function get(string $key): ?string
    {
        return Setting::where('key', "mcp.{$key}")->value('value');
    }

    private function set(string $key, ?string $value): void
    {
        Setting::updateOrCreate(['key' => "mcp.{$key}"], ['value' => $value]);
    }
}
