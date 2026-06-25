<?php

namespace App\Http\Middleware;

use App\Filament\Pages\Auth\TwoFactorChallenge;
use App\Filament\Pages\Auth\TwoFactorSetup;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the internal panel behind two-factor authentication (CLAUDE.md — 2FA
 * mandatory for levels 1–3):
 *
 * - An account with 2FA enabled must clear the TOTP challenge once per session
 *   before reaching any panel page.
 * - An account that requires 2FA (level 1–3) but has not enabled it is forced
 *   to the enrollment page first.
 * - Levels 4–6 without 2FA pass through (2FA optional for them).
 *
 * The 2FA pages, logout and Livewire update requests are exempt so the
 * challenge/enrollment forms can function without redirect loops. Those pages
 * can only be reached after a full-page GET, which this middleware governs.
 */
class EnforceTwoFactorAuthentication
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        if ($request->routeIs('*two-factor-setup', '*two-factor-challenge', '*.logout', 'livewire.*')) {
            return $next($request);
        }

        if ($user->hasEnabledTwoFactorAuthentication()) {
            if (! $request->session()->get('auth.two_factor_passed', false)) {
                return redirect(TwoFactorChallenge::getUrl());
            }

            return $next($request);
        }

        if ($user->requiresTwoFactor()) {
            return redirect(TwoFactorSetup::getUrl());
        }

        return $next($request);
    }
}
