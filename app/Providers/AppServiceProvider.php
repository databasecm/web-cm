<?php

namespace App\Providers;

use App\Services\Payment\PaymentGateway;
use App\Services\Payment\SimulatedGateway;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
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
        // Payment gateway is chosen by config (ADR-0012/0013): PAYMENT_GATEWAY
        // selects the alias in config/payment.php. Default 'simulated' — the
        // credential-free gateway for dev/test/CI. Production sets 'midtrans'.
        // An unknown alias fails safe to the simulated gateway (never a hard
        // crash, never an accidental real charge).
        $this->app->bind(PaymentGateway::class, function (Application $app): PaymentGateway {
            $alias = (string) config('payment.gateway', 'simulated');
            $class = config("payment.gateways.{$alias}", SimulatedGateway::class);

            return $app->make($class);
        });
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

        // Throttle the public payment webhook per source IP (ADR-0013) — a cap on
        // callback floods (abuse/DoS), NOT a replacement for the signature. Limit
        // is read from config live, so it is env-overridable without code change.
        RateLimiter::for('payment-webhook', function (Request $request): Limit {
            $max = (int) config('payment.webhook.throttle.max_attempts', 60);
            $decaySeconds = (int) config('payment.webhook.throttle.decay_seconds', 60);

            return new Limit($request->ip(), $max, max(1, (int) ceil($decaySeconds / 60)));
        });

        // Password-reset emails (the deal→account setup link, ADR-0003) point at
        // the consumer portal's reset page. Consumers are the only accounts that
        // receive reset links, so scoping the URL to /portal is correct.
        ResetPassword::createUrlUsing(fn (object $notifiable, string $token): string => route('portal.password.reset', [
            'token' => $token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]));
    }
}
