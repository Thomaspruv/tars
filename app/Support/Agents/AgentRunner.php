<?php

namespace App\Support\Agents;

use App\Enums\AgentRunStatus;
use App\Enums\AgentRunTrigger;
use App\Models\AgentConfig;
use App\Models\AgentRun;
use App\Models\AiProvider;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class AgentRunner
{
    /**
     * Run an agent's chat completion, logging the attempt (and its outcome) to
     * agent_runs regardless of what happens — an agent can't run unlogged.
     *
     * @param  list<array{role: string, content: string}>  $messages
     */
    public function run(AgentConfig $config, string $systemPrompt, array $messages, AgentRunTrigger $trigger): AgentRun
    {
        $provider = $config->aiProvider;

        $run = AgentRun::create([
            'agent_name' => $config->agent_name,
            'trigger' => $trigger,
            'status' => AgentRunStatus::Running,
            'provider' => $provider->name,
            'model' => $config->model,
            'input' => ['system' => $systemPrompt, 'messages' => $messages],
            'started_at' => now(),
        ]);

        try {
            $isAnthropic = $provider->name === 'anthropic';

            $response = $isAnthropic
                ? $this->callAnthropic($provider, $config->model, $systemPrompt, $messages)
                : $this->callOpenAiCompatible($provider, $config->model, $systemPrompt, $messages);

            $response->throw();

            $data = $response->json();

            [$text, $tokensIn, $tokensOut] = $isAnthropic
                ? [
                    $data['content'][0]['text'] ?? '',
                    $data['usage']['input_tokens'] ?? null,
                    $data['usage']['output_tokens'] ?? null,
                ]
                : [
                    $data['choices'][0]['message']['content'] ?? '',
                    $data['usage']['prompt_tokens'] ?? null,
                    $data['usage']['completion_tokens'] ?? null,
                ];

            $run->update([
                'status' => AgentRunStatus::Success,
                'output' => $text,
                'tokens_in' => $tokensIn,
                'tokens_out' => $tokensOut,
                'finished_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $run->update([
                'status' => AgentRunStatus::Failed,
                'error' => $e->getMessage(),
                'finished_at' => now(),
            ]);
        }

        return $run->fresh();
    }

    /**
     * A cheap, free connectivity check — lists available models rather than
     * spending a completion's worth of tokens just to verify a key works.
     */
    public function testConnection(AiProvider $provider): bool
    {
        if ($provider->name === 'anthropic') {
            return $this->client()
                ->withHeaders(['x-api-key' => $provider->api_key, 'anthropic-version' => '2023-06-01'])
                ->get($this->baseUrl($provider, 'https://api.anthropic.com').'/v1/models')
                ->successful();
        }

        return $this->client()
            ->withToken($provider->api_key)
            ->get($this->baseUrl($provider, 'https://api.openai.com/v1').'/models')
            ->successful();
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     */
    private function callAnthropic(AiProvider $provider, string $model, string $systemPrompt, array $messages): Response
    {
        return $this->client()
            ->withHeaders(['x-api-key' => $provider->api_key, 'anthropic-version' => '2023-06-01'])
            ->post($this->baseUrl($provider, 'https://api.anthropic.com').'/v1/messages', [
                'model' => $model,
                'max_tokens' => 4096,
                'system' => $systemPrompt,
                'messages' => $messages,
            ]);
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     */
    private function callOpenAiCompatible(AiProvider $provider, string $model, string $systemPrompt, array $messages): Response
    {
        return $this->client()
            ->withToken($provider->api_key)
            ->post($this->baseUrl($provider, 'https://api.openai.com/v1').'/chat/completions', [
                'model' => $model,
                'messages' => [['role' => 'system', 'content' => $systemPrompt], ...$messages],
            ]);
    }

    private function client(): PendingRequest
    {
        return Http::timeout(120);
    }

    private function baseUrl(AiProvider $provider, string $default): string
    {
        return rtrim($provider->base_url ?: $default, '/');
    }
}
