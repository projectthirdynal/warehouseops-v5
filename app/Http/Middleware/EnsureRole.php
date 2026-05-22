<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || ! in_array($user->role, $roles)) {
            if (! $user) {
                return redirect()->route('login');
            }

            if ($user->role === 'agent') {
                return redirect()->route('agent.leads');
            }

            abort(403, 'Access denied.');
        }

        return $next($request);
    }
}
