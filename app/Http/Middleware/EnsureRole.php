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

        if (!$user || !in_array($user->role, $roles)) {
            // Redirect agents to their portal instead of showing 403
            if ($user && $user->role === 'agent') {
                return redirect()->route('agent.leads');
            }

            abort(403, 'Access denied.');
        }

        return $next($request);
    }
}
