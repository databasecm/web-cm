<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts the Mandor field API channel to Mandor (L5) accounts. Per-record and
 * bidang scoping is still enforced by the policies/services on each endpoint
 * (Fase 5-4).
 */
class EnsureMandor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless(
            $user !== null && $user->isMandor(),
            403,
            'Kanal ini khusus mandor.',
        );

        return $next($request);
    }
}
