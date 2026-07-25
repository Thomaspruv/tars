<?php

namespace App\Support\Mcp;

use App\Models\Setting;
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

        return $plain;
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
