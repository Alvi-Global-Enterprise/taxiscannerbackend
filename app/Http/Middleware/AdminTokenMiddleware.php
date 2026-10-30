<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Simple shared-secret admin auth for Req 2 admin APIs.
 * Send header: X-Admin-Token: <ADMIN_API_TOKEN>
 * or Authorization: Bearer <ADMIN_API_TOKEN>
 */
final class AdminTokenMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('taxiscanner.admin.api_token', '');

        if ($expected === '') {
            return response()->json([
                'success' => false,
                'message' => 'Admin API is not configured. Set ADMIN_API_TOKEN in the environment.',
                'error_code' => 'ADMIN_NOT_CONFIGURED',
            ], 503);
        }

        $provided = $request->header('X-Admin-Token')
            ?? (preg_match('/^Bearer\s+(.+)$/i', (string) $request->header('Authorization'), $m) ? trim($m[1]) : null);

        if (! is_string($provided) || ! hash_equals($expected, $provided)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.',
                'error_code' => 'ADMIN_UNAUTHORIZED',
            ], 401);
        }

        return $next($request);
    }
}
