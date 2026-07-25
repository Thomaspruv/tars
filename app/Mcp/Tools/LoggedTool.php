<?php

namespace App\Mcp\Tools;

use App\Enums\McpCallStatus;
use App\Mcp\Support\AmbiguousToolCall;
use App\Models\McpCall;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

abstract class LoggedTool extends Tool
{
    final public function handle(Request $request): Response
    {
        $startedAt = microtime(true);
        $errorMessage = null;

        try {
            $response = $this->execute($request);
            $status = McpCallStatus::Success;
        } catch (AmbiguousToolCall $e) {
            $response = Response::text($e->getMessage());
            $status = McpCallStatus::Ambiguous;
        } catch (\Throwable $e) {
            report($e);
            $response = Response::error('Une erreur est survenue.');
            $status = McpCallStatus::Error;
            $errorMessage = $e->getMessage();
        }

        McpCall::create([
            'tool' => $this->name(),
            'parameters' => $request->all(),
            'result_summary' => Str::limit((string) $response->content(), 500),
            'status' => $status,
            'error_message' => $errorMessage,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return $response;
    }

    abstract protected function execute(Request $request): Response;
}
