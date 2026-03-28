<?php

declare(strict_types=1);

namespace App\Domain\Courier\Http\Middleware;

use App\Domain\Courier\Services\CourierServiceManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyCourierWebhookSignature
{
    public function handle(Request $request, Closure $next, string $courier): Response
    {
        $manager = app(CourierServiceManager::class);

        try {
            $service = $manager->driver($courier);
        } catch (\InvalidArgumentException $e) {
            abort(404, 'Unknown courier');
        }

        $rawBody   = $request->getContent();
        $signature = $request->header('X-Signature')
                  ?? $request->header('X-Sign')
                  ?? $request->input('sign', '');

        if (!$service->verifyWebhookSignature($rawBody, $signature)) {
            Log::warning('Courier webhook signature verification failed', [
                'courier' => $courier,
                'ip'      => $request->ip(),
            ]);
            abort(401, 'Invalid signature');
        }

        return $next($request);
    }
}
