<?php

namespace App\Http\Middleware;

use App\Models\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts the consumer API channel to Konsumen (L6) accounts — staff, Mitra
 * and others use other channels. Per-record ownership is still enforced by the
 * policies on each endpoint (Fase 2B-7).
 */
class EnsureConsumer
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless(
            $user !== null && $user->level() === Role::LEVEL_KONSUMEN,
            403,
            'Kanal ini khusus konsumen.',
        );

        return $next($request);
    }
}
