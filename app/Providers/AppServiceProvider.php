<?php

namespace App\Providers;

use App\Services\Payment\PaymentGateway;
use App\Services\Payment\SimulatedGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Default payment gateway: the credential-free simulated one (Fase 3-5,
        // ADR-0012). A real Midtrans/Xendit gateway later rebinds this interface
        // without touching the payment flow.
        $this->app->bind(PaymentGateway::class, SimulatedGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Throttle the unauthenticated guest consultation API per client IP
        // (ADR-0003) — generous enough for live chat polling, tight enough to
        // bound abuse of an endpoint that needs no login.
        RateLimiter::for('guest-consultation', fn (Request $request): Limit => Limit::perMinute(60)->by($request->ip()));
    }
}
