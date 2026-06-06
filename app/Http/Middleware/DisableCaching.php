<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class DisableCaching
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        // Force revalidation - browser must check with server before using cached HTML
        if (str_contains($response->headers->get('Content-Type', ''), 'text/html')) {
            $response->headers->set('Cache-Control', 'no-cache, must-revalidate');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Expires', '0');
            $response->headers->set('Vary', 'Cookie, Authorization');
        }

        return $response;
    }
}
