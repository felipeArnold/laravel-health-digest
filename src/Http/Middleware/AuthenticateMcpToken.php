<?php

declare(strict_types=1);

namespace FelipeArnold\HealthDigest\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateMcpToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = config('health-digest.mcp.token');

        if (! is_string($token) || $token === '') {
            abort(404);
        }

        $presentedToken = (string) $request->bearerToken();

        if ($presentedToken === '' || ! hash_equals($token, $presentedToken)) {
            abort(401, 'Invalid token.', ['WWW-Authenticate' => 'Bearer']);
        }

        return $next($request);
    }
}
