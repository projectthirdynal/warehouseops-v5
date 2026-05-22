<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession as BaseStartSession;

class StartSession extends BaseStartSession
{
    /**
     * Get the session implementation from the manager, setting the session
     * ID from the request cookie. Strips any "hash|" prefix that may be
     * prepended when running behind a proxy (Traefik + Cloudflare) where
     * the cookie value decrypts to "<sha1_prefix>|<actual_session_id>".
     */
    public function getSession(Request $request): \Illuminate\Contracts\Session\Session
    {
        return tap($this->manager->driver(), function ($session) use ($request) {
            $raw = $request->cookies->get($session->getName());

            // Strip proxy-introduced "hash|" prefix if present
            if ($raw && str_contains($raw, '|')) {
                $raw = substr($raw, strrpos($raw, '|') + 1);
            }

            $session->setId($raw);
        });
    }
}
