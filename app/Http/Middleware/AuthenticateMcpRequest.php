<?php

namespace App\Http\Middleware;

use App\Support\Mcp\McpSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateMcpRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $settings = app(McpSettings::class);

        if (! $settings->isEnabled()) {
            abort(503);
        }

        $token = $request->bearerToken();

        if ($token === null || ! $settings->hasToken() || ! $settings->tokenMatches($token)) {
            abort(401);
        }

        return $next($request);
    }
}
