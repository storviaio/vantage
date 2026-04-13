<?php

namespace Storvia\Vantage\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeVantageApi
{
    /**
     * Authenticate the request using a Bearer token configured in vantage.api.token.
     *
     * This middleware is intentionally decoupled from the session-based
     * Gate used by the Blade dashboard so that headless / machine-to-machine
     * consumers can authenticate without a browser session.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $configured = trim((string) config('vantage.api.token'));

        if ($configured === '') {
            abort(403, 'Vantage API token is not configured.');
        }

        $bearer = trim((string) ($request->bearerToken() ?? ''));

        if (! hash_equals($configured, $bearer)) {
            abort(401, 'Invalid or missing API token.');
        }

        return $next($request);
    }
}
