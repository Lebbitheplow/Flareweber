<?php

namespace FlareWeber\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for every FlareWeber admin route: the caller must be a Microweber
 * administrator (users.is_admin = 1). A plain logged-in user is not enough.
 */
class EnsureAdminAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isAdmin()) {
            return $next($request);
        }

        if ($this->expectsJson($request)) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        return redirect('/admin/login');
    }

    private function isAdmin(): bool
    {
        if (!function_exists('is_admin')) {
            return false;
        }

        try {
            return (bool) \is_admin();
        } catch (\Throwable) {
            return false;
        }
    }

    private function expectsJson(Request $request): bool
    {
        return $request->expectsJson()
            || $request->ajax()
            || $request->is('flareweber/sites*')
            || $request->is('flareweber/cloudflare*')
            || $request->is('flareweber/api*')
            || str_contains((string) $request->header('Accept'), 'application/json');
    }
}
