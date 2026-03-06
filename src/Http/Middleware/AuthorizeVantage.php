<?php

namespace Storvia\Vantage\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeVantage
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Try to resolve the authenticated user, but allow null
        $user = Auth::user() ?? Auth::guard('web')->user();

        // Authorization is fully handled by the Gate
        // The Gate accepts a null user and performs its own logic
        if (! Gate::allows('viewVantage', $user)) {
            abort(403, 'Unauthorized access to Vantage dashboard.');
        }

        return $next($request);
    }
}
