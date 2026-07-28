<?php

use App\Enums\AgentRunStatus;
use App\Enums\AgentRunTrigger;
use App\Models\AgentConfig;
use App\Models\AiProvider;
use App\Support\Agents\AgentRunner;
use Illuminate\Support\Facades\Http;

test('calls the anthropic messages api and logs a successful run', function () {
    Http::fake(['*/v1/messages' => Http::response([
        'content' => [['type' => 'text', 'text' => 'Voici la revue.']],
        'usage' => ['input_tokens' => 120, 'output_tokens' => 45],
    ])]);

    $provider = AiProvider::factory()->create(['name' => 'anthropic', 'api_key' => 'sk-ant-secret']);
    $config = AgentConfig::factory()->create(['agent_name' => 'reviewer', 'ai_provider_id' => $provider->id, 'model' => 'claude-x']);

    $run = (new AgentRunner)->run($config, 'Tu es un assistant.', [['role' => 'user', 'content' => 'Bonjour']], AgentRunTrigger::Manual);

    expect($run->status)->toBe(AgentRunStatus::Success)
        ->and($run->output)->toBe('Voici la revue.')
        ->and($run->tokens_in)->toBe(120)
        ->and($run->tokens_out)->toBe(45)
        ->and($run->provider)->toBe('anthropic')
        ->and($run->model)->toBe('claude-x')
        ->and($run->finished_at)->not->toBeNull();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.anthropic.com/v1/messages'
            && $request->header('x-api-key') === ['sk-ant-secret']
            && $request->header('anthropic-version') === ['2023-06-01']
            && $request['model'] === 'claude-x'
            && $request['system'] === 'Tu es un assistant.'
            && $request['messages'] === [['role' => 'user', 'content' => 'Bonjour']];
    });
});

test('calls an openai-compatible chat completions endpoint and logs a successful run', function () {
    Http::fake(['*/chat/completions' => Http::response([
        'choices' => [['message' => ['role' => 'assistant', 'content' => 'Voici la revue.']]],
        'usage' => ['prompt_tokens' => 200, 'completion_tokens' => 60],
    ])]);

    $provider = AiProvider::factory()->create(['name' => 'deepseek', 'api_key' => 'sk-deep-secret', 'base_url' => 'https://api.deepseek.com/v1']);
    $config = AgentConfig::factory()->create(['ai_provider_id' => $provider->id, 'model' => 'deepseek-chat']);

    $run = (new AgentRunner)->run($config, 'Tu es un assistant.', [['role' => 'user', 'content' => 'Bonjour']], AgentRunTrigger::Scheduled);

    expect($run->status)->toBe(AgentRunStatus::Success)
        ->and($run->output)->toBe('Voici la revue.')
        ->and($run->tokens_in)->toBe(200)
        ->and($run->tokens_out)->toBe(60);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.deepseek.com/v1/chat/completions'
            && $request->header('Authorization') === ['Bearer sk-deep-secret']
            && $request['messages'] === [
                ['role' => 'system', 'content' => 'Tu es un assistant.'],
                ['role' => 'user', 'content' => 'Bonjour'],
            ];
    });
});

test('logs a failed run without throwing when the api errors', function () {
    Http::fake(['*/chat/completions' => Http::response(['error' => 'invalid api key'], 401)]);

    $provider = AiProvider::factory()->create(['name' => 'openai']);
    $config = AgentConfig::factory()->create(['ai_provider_id' => $provider->id]);

    $run = (new AgentRunner)->run($config, 'system', [['role' => 'user', 'content' => 'hi']], AgentRunTrigger::Manual);

    expect($run->status)->toBe(AgentRunStatus::Failed)
        ->and($run->error)->not->toBeNull()
        ->and($run->output)->toBeNull();
});

test('logs a failed run when a 2xx response has no usable text', function () {
    Http::fake(['*/chat/completions' => Http::response(['choices' => [['message' => ['content' => '']]]], 200)]);

    $provider = AiProvider::factory()->create(['name' => 'openai']);
    $config = AgentConfig::factory()->create(['ai_provider_id' => $provider->id]);

    $run = (new AgentRunner)->run($config, 'system', [['role' => 'user', 'content' => 'hi']], AgentRunTrigger::Manual);

    expect($run->status)->toBe(AgentRunStatus::Failed)
        ->and($run->output)->toBeNull()
        ->and($run->error)->not->toBeNull();
});

test('logs a failed run when the 2xx response body is unparseable', function () {
    Http::fake(['*/chat/completions' => Http::response('not json', 200)]);

    $provider = AiProvider::factory()->create(['name' => 'openai']);
    $config = AgentConfig::factory()->create(['ai_provider_id' => $provider->id]);

    $run = (new AgentRunner)->run($config, 'system', [['role' => 'user', 'content' => 'hi']], AgentRunTrigger::Manual);

    expect($run->status)->toBe(AgentRunStatus::Failed)
        ->and($run->output)->toBeNull();
});

test('never stores the api key in agent_runs.input', function () {
    Http::fake(['*/chat/completions' => Http::response([
        'choices' => [['message' => ['content' => 'ok']]],
        'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
    ])]);

    $provider = AiProvider::factory()->create(['name' => 'openai', 'api_key' => 'super-secret-key']);
    $config = AgentConfig::factory()->create(['ai_provider_id' => $provider->id]);

    $run = (new AgentRunner)->run($config, 'system prompt', [['role' => 'user', 'content' => 'hi']], AgentRunTrigger::Manual);

    expect(json_encode($run->input))->not->toContain('super-secret-key');
});

test('testConnection lists models for an openai-compatible provider', function () {
    Http::fake(['*/models' => Http::response(['data' => []], 200)]);

    $provider = AiProvider::factory()->create(['name' => 'openai', 'api_key' => 'sk-secret']);

    expect((new AgentRunner)->testConnection($provider))->toBeTrue();

    Http::assertSent(fn ($request) => $request->url() === 'https://api.openai.com/v1/models' && $request->header('Authorization') === ['Bearer sk-secret']);
});

test('testConnection reports failure on a non-2xx response', function () {
    Http::fake(['*/models' => Http::response(null, 401)]);

    $provider = AiProvider::factory()->create(['name' => 'anthropic']);

    expect((new AgentRunner)->testConnection($provider))->toBeFalse();
});
