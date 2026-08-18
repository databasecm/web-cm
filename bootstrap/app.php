<?php

use App\Http\Middleware\AllowlistPaymentWebhookIp;
use App\Http\Middleware\EnsureConsumer;
use App\Http\Middleware\EnsureConsumerWeb;
use App\Http\Middleware\EnsureMandor;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'consumer' => EnsureConsumer::class,
            'consumer.web' => EnsureConsumerWeb::class,
            'mandor' => EnsureMandor::class,
            'payment.webhook.ip' => AllowlistPaymentWebhookIp::class,
        ]);

        // Unauthenticated web-guard requests go to the consumer portal login.
        // The Filament panel (/sistem) manages its own guest redirect internally,
        // so this only affects the portal + signed media route.
        $middleware->redirectGuestsTo(fn (Request $request): string => route('portal.login'));
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
