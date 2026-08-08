<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * IP allowlist for the public payment webhook (ADR-0013). A defense-in-depth
 * layer on top of — never a replacement for — the callback signature (G-2):
 * an IP can be spoofed in some setups, so authenticity always comes from the
 * signature; this only bounds who may reach the endpoint at all.
 *
 * The allowlist is config-driven (payment.webhook.ips), never hardcoded. An
 * EMPTY list means allow all — so dev/simulated and CI are never blocked;
 * production fills in Midtrans' notification IPs. A request from outside a
 * populated list is refused with 403 BEFORE any controller logic runs.
 */
class AllowlistPaymentWebhookIp
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var list<string> $allowlist */
        $allowlist = (array) config('payment.webhook.ips', []);

        // Empty allowlist → allow all (dev/simulated/CI).
        if ($allowlist !== [] && ! in_array($request->ip(), $allowlist, true)) {
            abort(403, 'Alamat IP tidak diizinkan.');
        }

        return $next($request);
    }
}
