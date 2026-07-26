<?php

namespace App\Support\Agents;

use App\Models\AiProvider;
use Illuminate\Support\Facades\Http;

class AgentRunner
{
    /**
     * A cheap, free connectivity check — lists available models rather than
     * spending a completion's worth of tokens just to verify a key works.
     */
    public function testConnection(AiProvider $provider): bool
    {
        if ($provider->name === 'anthropic') {
            return Http::withHeaders([
                'x-api-key' => $provider->api_key,
                'anthropic-version' => '2023-06-01',
            ])->get($this->baseUrl($provider, 'https://api.anthropic.com').'/v1/models')->successful();
        }

        return Http::withToken($provider->api_key)
            ->get($this->baseUrl($provider, 'https://api.openai.com/v1').'/models')
            ->successful();
    }

    private function baseUrl(AiProvider $provider, string $default): string
    {
        return rtrim($provider->base_url ?: $default, '/');
    }
}
