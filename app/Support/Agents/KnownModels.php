<?php

namespace App\Support\Agents;

class KnownModels
{
    /**
     * Known chat-completion model IDs per provider name, so the Agents screen
     * can offer a dropdown instead of asking the user to type a raw model ID
     * they can't be expected to know (e.g. DeepSeek's).
     *
     * @return array<string, list<string>>
     */
    private static function catalog(): array
    {
        return [
            'anthropic' => [
                'claude-sonnet-5',
                'claude-opus-5',
                'claude-fable-5',
                'claude-haiku-4-5',
            ],
            'openai' => [
                'gpt-5.6',
                'gpt-5.6-sol',
                'gpt-5.6-terra',
                'gpt-5.6-luna',
            ],
            'deepseek' => [
                'deepseek-v4-pro',
                'deepseek-v4-flash',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function forProvider(?string $providerName): array
    {
        return self::catalog()[strtolower((string) $providerName)] ?? [];
    }
}
