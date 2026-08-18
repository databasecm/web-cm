<?php

namespace App\Http\Middleware;

use App\Models\Role;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts the /portal web area to Konsumen (L6) accounts on the WEB (session)
 * guard.
 *
 * Deliberately SEPARATE from {@see EnsureConsumer}, which guards the Sanctum
 * token API: the portal and the token channel authenticate on different guards
 * and must not share one check. Staff (L1–5) who reach /portal are rejected here
 * (they use the Filament panel /sistem); consumers are conversely denied the
 * panel by {@see User::canAccessPanel()}. Per-record ownership is
 * still enforced by the policies each portal page calls (like the API).
 */
class EnsureConsumerWeb
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless(
            $user !== null && $user->level() === Role::LEVEL_KONSUMEN,
            403,
            'Area ini khusus akun konsumen.',
        );

        return $next($request);
    }
}
