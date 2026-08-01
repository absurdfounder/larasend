<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform admin auth for the Trooper provisioning API (/api/admin/*).
 *
 * A single bearer token (LARASEND_ADMIN_TOKEN) authorizes the Trooper central
 * server to create projects, mint keys, and manage inbound routing. The admin
 * API is completely disabled (503) until the env var is set, so a stock
 * larasend install exposes nothing new.
 */
class AuthenticateLarasendAdminToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $configured = (string) config('larasend.admin_token');

        if ($configured === '') {
            return response()->json(['message' => 'Admin API is not enabled.'], 503);
        }

        $provided = (string) $request->bearerToken();

        if ($provided === '' || ! hash_equals($configured, $provided)) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return $next($request);
    }
}
