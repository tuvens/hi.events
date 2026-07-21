<?php

declare(strict_types=1);

namespace HiEvents\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates server-to-server calls from tuvens-api.
 *
 * Requests carry X-Tuvens-Timestamp and X-Tuvens-Signature =
 * HMAC-SHA256(shared_secret, timestamp + "." + rawBody). The signature is
 * recomputed over the raw body and compared in constant time; timestamps
 * outside a ±5 minute window are rejected to bound replay.
 *
 * The optional X-On-Behalf-Of header (a tuvens user id) is passed through as
 * a request attribute for actions to resolve against users.external_user_id.
 */
class TuvensInternalApiMiddleware
{
    private const TIMESTAMP_TOLERANCE_SECONDS = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('services.main_backend.shared_secret');

        if (!$secret) {
            Log::error('Tuvens S2S request received but MAIN_BACKEND_SHARED_SECRET is not configured');

            return response()->json([
                'message' => 'Service not configured',
                'error' => 'SERVICE_UNAVAILABLE',
            ], 503);
        }

        $timestamp = $request->header('X-Tuvens-Timestamp');
        $signature = $request->header('X-Tuvens-Signature');

        if (!$timestamp || !$signature || !ctype_digit($timestamp)) {
            return $this->unauthorized();
        }

        if (abs(now()->getTimestamp() - (int)$timestamp) > self::TIMESTAMP_TOLERANCE_SECONDS) {
            return $this->unauthorized();
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $request->getContent(), $secret);

        if (!hash_equals($expected, $signature)) {
            return $this->unauthorized();
        }

        if ($request->hasHeader('X-On-Behalf-Of')) {
            $request->attributes->set('tuvens_user_id', (string)$request->header('X-On-Behalf-Of'));
        }

        return $next($request);
    }

    private function unauthorized(): Response
    {
        return response()->json([
            'message' => 'Invalid request signature',
            'error' => 'UNAUTHORIZED',
        ], 401);
    }
}
