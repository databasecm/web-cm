<?php

use App\Services\Payment\MidtransGateway;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\SimulatedGateway;

/*
| Fase G-1 — the PaymentGateway binding is chosen by config (ADR-0012/0013).
| Default is the credential-free simulated gateway, so CI/dev/test need no
| Midtrans secret; 'midtrans' resolves only when explicitly selected.
*/

it('resolves the simulated gateway by default (no credentials needed)', function () {
    // Nothing set → default alias 'simulated'.
    expect(config('payment.gateway'))->toBe('simulated')
        ->and($this->app->make(PaymentGateway::class))->toBeInstanceOf(SimulatedGateway::class);
});

it('resolves the Midtrans gateway when selected, without any credentials', function () {
    config(['payment.gateway' => 'midtrans']);

    // Binding is a fresh factory each resolve — no creds required to construct.
    expect($this->app->make(PaymentGateway::class))->toBeInstanceOf(MidtransGateway::class);
});

it('fails safe to the simulated gateway for an unknown alias', function () {
    config(['payment.gateway' => 'bogus-provider']);

    expect($this->app->make(PaymentGateway::class))->toBeInstanceOf(SimulatedGateway::class);
});

it('keeps both gateways registered side by side (simulated is never removed)', function () {
    expect(config('payment.gateways.simulated'))->toBe(SimulatedGateway::class)
        ->and(config('payment.gateways.midtrans'))->toBe(MidtransGateway::class);
});

it('defaults the webhook allowlist to empty (allow all in dev/simulated)', function () {
    expect(config('payment.webhook.ips'))->toBe([])
        ->and(config('payment.midtrans.is_production'))->toBeFalse();
});
